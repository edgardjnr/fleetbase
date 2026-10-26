<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Notifications\Entregas\PagamentoRegistrado;
use App\Notifications\Entregas\PixDeSaqueAlterado;
use App\Support\Entregas\Financeiro\ChavePix;
use App\Support\Entregas\Financeiro\CicloDeCobranca;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Financeiro\PainelFinanceiro;
use App\Support\Entregas\Financeiro\Pagamentos;
use App\Support\Entregas\Saques\Saque;
use App\Support\Entregas\Saques\Saques;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Models\Setting;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o Financeiro do console (Fleet-Ops → Financeiro), só para administradores: painel, motoboys
 * (lista, extrato, chave Pix), lançamentos manuais (diária, bônus, ajuste, saldo inicial) e estornos, pagamentos e
 * recebimentos (com comprovante) e as configurações (data de corte, ciclo padrão das lojas). Rotas em
 * int/v1/entregas/financeiro/* (RouteServiceProvider); formatos no contrato
 * (docs/superpowers/plans/2026-10-12-financeiro.md, seções 1.4 e 1.5). Nada se apaga: erro se corrige com estorno.
 *
 * Os ids das rotas: motoboy = drivers.public_id, loja = vendors.public_id, lançamento = entregas_lancamentos.id,
 * pagamento = uuid. Nenhum model registra o CompanyScope nesta versão: a empresa é filtrada em toda consulta.
 */
class FinanceiroController extends Controller
{
    public const MAXIMO_DE_CONTAS = 100;

    /** GET financeiro/painel?inicio&fim (padrão: este mês). */
    public function painel(Request $request)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }
        [$inicio, $fim, $erro] = $this->periodo($request, true);
        if ($erro) {
            return $erro;
        }

        return response()->json(PainelFinanceiro::montar($this->empresa(), $inicio, $fim));
    }

    /**
     * GET financeiro/motoboys?inicio&fim (padrão: este mês): todos os motoboys da empresa (mesmo sem lançamento), com o
     * saldo de agora, o último pagamento, as corridas do período (sem as estornadas), se tem chave Pix e `excluido` (o
     * motoboy excluído com soft delete só aparece com saldo ≠ 0, e aceita pagamento e lançamento). Ordem: saldo desc,
     * depois nome.
     */
    public function motoboys(Request $request)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }
        [$inicio, $fim, $erro] = $this->periodo($request, true);
        if ($erro) {
            return $erro;
        }

        $empresa = $this->empresa();
        $saldos  = Lancamentos::saldos($empresa, Financeiro::CONTA_MOTOBOY);
        $comPix  = ChavePix::motoboysComChave($empresa);

        // último pagamento (não estornado) de cada motoboy: maior data, depois o último registrado
        $ultimos = [];
        $pagamentos = DB::table(Pagamentos::TABELA)
            ->where('company_uuid', $empresa)
            ->where('conta_tipo', Financeiro::CONTA_MOTOBOY)
            ->whereNull('estornado_em')
            ->orderBy('data', 'desc')
            ->orderBy('id', 'desc')
            ->get();
        foreach ($pagamentos as $pagamento) {
            $ultimos[(string) $pagamento->conta_uuid] ??= [
                'id'    => (string) $pagamento->uuid,
                'valor' => round((float) $pagamento->valor, 2),
                'data'  => substr((string) $pagamento->data, 0, 10),
                'forma' => (string) $pagamento->forma,
            ];
        }

        // corridas do período (as estornadas não contam)
        $corridas = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $empresa)
            ->where('conta_tipo', Financeiro::CONTA_MOTOBOY)
            ->where('tipo', 'corrida')
            ->where('data_referencia', '>=', $inicio)
            ->where('data_referencia', '<=', $fim)
            ->get()
            ->all();
        $estornadas = array_flip(Lancamentos::jaEstornados(array_map(fn ($l) => (int) $l->id, $corridas)));
        $porMotoboy = [];
        foreach ($corridas as $corrida) {
            if (isset($estornadas[(int) $corrida->id])) {
                continue;
            }
            $conta                          = (string) $corrida->conta_uuid;
            $porMotoboy[$conta]['corridas'] = ($porMotoboy[$conta]['corridas'] ?? 0) + 1;
            $porMotoboy[$conta]['centavos'] = ($porMotoboy[$conta]['centavos'] ?? 0) + (int) round((float) $corrida->valor * 100);
        }

        // o motoboy excluído (soft delete) com saldo entra, marcado: ainda dá para pagar o acerto final (o painel já soma o
        // saldo dele no "a pagar")
        $lista = [];
        foreach (Driver::withTrashed()->where('company_uuid', $empresa)->with(['user'])->get() as $motoboy) {
            $uuid     = (string) $motoboy->uuid;
            $excluido = $motoboy->deleted_at !== null;
            if ($excluido && round((float) ($saldos[$uuid] ?? 0), 2) == 0) {
                continue;
            }
            $lista[] = [
                'id'               => $motoboy->public_id,
                'nome'             => $motoboy->name,
                'saldo'            => round((float) ($saldos[$uuid] ?? 0), 2),
                'ultimo_pagamento' => $ultimos[$uuid] ?? null,
                'corridas'         => $porMotoboy[$uuid]['corridas'] ?? 0,
                'valor_corridas'   => round(($porMotoboy[$uuid]['centavos'] ?? 0) / 100, 2),
                'tem_pix'          => isset($comPix[$uuid]),
                'excluido'         => $excluido,
            ];
        }
        usort($lista, fn ($a, $b) => [$b['saldo'], mb_strtolower((string) $a['nome'])] <=> [$a['saldo'], mb_strtolower((string) $b['nome'])]);

        return response()->json(['motoboys' => $lista]);
    }

    /** GET financeiro/motoboys/{id}/extrato?inicio&fim&tipo (período opcional; o console sempre manda). */
    public function extratoDoMotoboy(Request $request, string $id)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }
        $motoboy = $this->motoboy($id);
        if (!$motoboy) {
            return $this->erro('Motoboy não encontrado.', 404);
        }
        [$inicio, $fim, $erro] = $this->periodo($request, false);
        if ($erro) {
            return $erro;
        }
        $tipo = $request->input('tipo');
        if ($tipo !== null && $tipo !== '' && !in_array($tipo, ExtratoDaConta::TIPOS, true)) {
            return $this->erro('Tipo de lançamento inválido.');
        }

        return response()->json(ExtratoDaConta::montar($this->empresa(), Financeiro::CONTA_MOTOBOY, (string) $motoboy->uuid, $inicio, $fim, false, $tipo ?: null));
    }

    /** PUT financeiro/motoboys/{id}/pix {tipo, chave} → {pix: {chave, tipo}}. */
    public function salvarPix(Request $request, string $id)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }
        $motoboy = $this->motoboy($id);
        if (!$motoboy) {
            return $this->erro('Motoboy não encontrado.', 404);
        }

        $pix = ChavePix::validar($request->input('tipo'), $request->input('chave'));
        if (isset($pix['erro'])) {
            return $this->erro($pix['erro']);
        }
        if (ChavePix::salvar($this->empresa(), (string) $motoboy->uuid, $pix['chave'], $pix['tipo'], $this->usuario($request))) {
            $this->avisarPixAlterado($motoboy);
        }

        return response()->json(['pix' => ['chave' => $pix['chave'], 'tipo' => $pix['tipo']]]);
    }

    /**
     * POST financeiro/lancamentos {conta_tipo, contas: [public_id…], tipo, valor, data, pedido, motivo, observacao_interna}
     * → {lancamentos: [Lancamento…]} (um por conta, na mesma transação). Tipo dos manuais da conta; valor ≠ 0, positivo em
     * diária e bônus; pedido (public_id, uuid ou número com corrida na conta) só em ajuste; ajuste negativo de pedido com
     * a corrida estornada é recusado. Na conta da loja, o lançamento fica sem fatura (entra no próximo rascunho).
     */
    public function lancar(Request $request)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }

        $contaTipo = $request->input('conta_tipo');
        if (!in_array($contaTipo, [Financeiro::CONTA_MOTOBOY, Financeiro::CONTA_LOJA], true)) {
            return $this->erro('Tipo de conta inválido.');
        }
        $tipo   = $request->input('tipo');
        $tipos  = $contaTipo === Financeiro::CONTA_MOTOBOY ? Financeiro::TIPOS_MANUAIS_MOTOBOY : Financeiro::TIPOS_MANUAIS_LOJA;
        if (!in_array($tipo, $tipos, true)) {
            return $this->erro('Tipo de lançamento inválido para esta conta.');
        }

        if (static::milharSemCentavos($request->input('valor'))) {
            return $this->erro(static::MILHAR_SEM_CENTAVOS);
        }
        $valor = static::numero($request->input('valor'));
        if ($valor === null) {
            return $this->erro('Valor inválido.');
        }
        $valor = round($valor, 2);
        if ($valor == 0) {
            return $this->erro('O valor não pode ser zero.');
        }
        if ($valor < 0 && !in_array($tipo, ['ajuste', 'saldo_inicial'], true)) {
            return $this->erro('Diária e bônus não aceitam valor negativo.');
        }

        $data = static::dia($request->input('data'));
        if ($data === null) {
            return $this->erro('Data inválida.');
        }

        [$motivo, $erro] = $this->texto($request->input('motivo'), 255, 'O motivo pode ter até 255 caracteres.');
        if ($erro) {
            return $erro;
        }
        [$observacao, $erro] = $this->texto($request->input('observacao_interna'), 500, 'A observação interna pode ter até 500 caracteres.');
        if ($erro) {
            return $erro;
        }

        $codigo = $request->input('pedido');
        $codigo = is_scalar($codigo) ? trim((string) $codigo) : '';
        if ($codigo !== '' && $tipo !== 'ajuste') {
            return $this->erro('Só o ajuste pode ter pedido.');
        }

        $ids = $request->input('contas');
        if (!is_array($ids) || !$ids) {
            return $this->erro('Escolha pelo menos uma conta.');
        }
        $ids = array_values(array_unique(array_map('strval', array_filter($ids, 'is_scalar'))));
        if (count($ids) > static::MAXIMO_DE_CONTAS) {
            return $this->erro('Lance para até 100 contas de uma vez.');
        }
        $contas = $this->contas($contaTipo, $ids);
        $faltam = array_values(array_diff($ids, array_keys($contas)));
        if ($faltam) {
            return $this->erro('Conta não encontrada: ' . implode(', ', array_slice($faltam, 0, 5)) . '.');
        }

        $pedido = null;
        if ($codigo !== '') {
            $pedido = $this->pedido($codigo, $contaTipo, array_values($contas));
            if (is_string($pedido)) {
                return $this->erro($pedido);
            }
            if (!$pedido) {
                return $this->erro('Pedido não encontrado.');
            }
            // o desconto de uma corrida já estornada debitaria duas vezes (o caminho inverso, estorno automático depois
            // do "Não pagar", desconta o ajuste: LancadorDeCorridas::estornosDevidos)
            if ($valor < 0 && $this->corridaEstornada($contaTipo, array_values($contas), (string) $pedido->uuid)) {
                return $this->erro('A corrida deste pedido já foi estornada.');
            }
        }

        $empresa = $this->empresa();
        $usuario = $this->usuario($request);
        $criados = DB::transaction(function () use ($ids, $contas, $empresa, $contaTipo, $tipo, $valor, $data, $pedido, $motivo, $observacao, $usuario) {
            $criados = [];
            foreach ($ids as $id) {
                $criados[] = Lancamentos::inserir([
                    'company_uuid'       => $empresa,
                    'conta_tipo'         => $contaTipo,
                    'conta_uuid'         => $contas[$id],
                    'tipo'               => $tipo,
                    'valor'              => $valor,
                    'data_referencia'    => $data,
                    'order_uuid'         => $pedido ? (string) $pedido->uuid : null,
                    'motivo'             => $motivo,
                    'observacao_interna' => $observacao,
                    // o pedido do ajuste fica nos detalhes, como na corrida (o extrato mostra o número sem ler o Order)
                    'detalhes'           => $pedido ? ['pedido' => $pedido->public_id, 'numero' => $pedido->internal_id ?? null] : null,
                    'criado_por'         => $usuario,
                ]);
            }

            return $criados;
        });

        $linhas = DB::table(Lancamentos::TABELA)->where('company_uuid', $empresa)->whereIn('id', $criados)->orderBy('id')->get()->all();

        return response()->json(['lancamentos' => ExtratoDaConta::linhas($linhas, [], false, [])]);
    }

    /**
     * POST financeiro/lancamentos/{id}/estornar {motivo} → {lancamento} (o estorno). Pagamento e recebimento se estornam
     * pelo pagamento (financeiro/pagamentos/{uuid}/estornar), que marca o registro. Lançamento de loja numa fatura emitida
     * pode ser estornado: o estorno fica sem fatura e entra no próximo rascunho.
     */
    public function estornarLancamento(Request $request, string $id)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }
        $lancamento = ctype_digit($id) ? Lancamentos::porId($this->empresa(), (int) $id) : null;
        if (!$lancamento) {
            return $this->erro('Lançamento não encontrado.', 404);
        }
        if ($lancamento->tipo === 'estorno') {
            return $this->erro('Um estorno não pode ser estornado.');
        }
        if (in_array($lancamento->tipo, ['pagamento', 'recebimento'], true)) {
            return $this->erro('Estorne o pagamento pelo botão do pagamento.');
        }
        [$motivo, $erro] = $this->texto($request->input('motivo'), 255, 'O motivo pode ter até 255 caracteres.');
        if ($erro) {
            return $erro;
        }

        $estorno = Lancamentos::estornar($lancamento, $this->usuario($request), $motivo);
        if (!$estorno) {
            return $this->erro('Este lançamento já foi estornado.', 409);
        }

        return response()->json(['lancamento' => ExtratoDaConta::linhas([Lancamentos::porId($this->empresa(), $estorno)], [], false)[0]]);
    }

    /**
     * POST financeiro/pagamentos (multipart): conta_tipo, conta (public_id), valor ("150.50" ou "150,50"), forma, data,
     * observacao_interna e o arquivo em `comprovante` (ou `file`); um campo `fatura` é ignorado. → {pagamento, saldo}.
     * Valor acima do saldo vira adiantamento. Pagamento ao motoboy manda o push "Pagamento registrado" (falha no push
     * não desfaz o pagamento).
     */
    public function registrarPagamento(Request $request)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }

        $contaTipo = $request->input('conta_tipo');
        if (!in_array($contaTipo, [Financeiro::CONTA_MOTOBOY, Financeiro::CONTA_LOJA], true)) {
            return $this->erro('Tipo de conta inválido.');
        }
        $id    = is_scalar($request->input('conta')) ? trim((string) $request->input('conta')) : '';
        $conta = $id !== '' ? ($this->contas($contaTipo, [$id])[$id] ?? null) : null;
        if (!$conta) {
            return $this->erro($contaTipo === Financeiro::CONTA_MOTOBOY ? 'Motoboy não encontrado.' : 'Loja não encontrada.', 404);
        }
        if (static::milharSemCentavos($request->input('valor'))) {
            return $this->erro(static::MILHAR_SEM_CENTAVOS);
        }
        $valor = static::numero($request->input('valor'));
        if ($valor === null) {
            return $this->erro('Valor inválido.');
        }
        [$observacao, $erro] = $this->texto($request->input('observacao_interna'), 500, 'A observação interna pode ter até 500 caracteres.');
        if ($erro) {
            return $erro;
        }

        $arquivo = $request->file('comprovante') ?: $request->file('file');
        if (is_array($arquivo)) {
            return $this->erro('Envie um comprovante só.');
        }
        if ($arquivo && method_exists($arquivo, 'isValid') && !$arquivo->isValid()) {
            return $this->erro('Não foi possível receber o comprovante. Tente de novo.');
        }

        $empresa = $this->empresa();
        try {
            $pagamento = Pagamentos::registrar($empresa, $contaTipo, $conta, $valor, (string) $request->input('forma'),
                (string) $request->input('data'), $arquivo ?: null, $observacao, $this->usuario($request));
        } catch (\InvalidArgumentException $e) {
            return $this->erro($e->getMessage());
        }

        if ($contaTipo === Financeiro::CONTA_MOTOBOY) {
            $this->avisarMotoboy($empresa, $conta, $pagamento);
        }

        return response()->json([
            'pagamento' => ExtratoDaConta::pagamento($pagamento),
            'saldo'     => ExtratoDaConta::saldo($empresa, $contaTipo, $conta),
        ]);
    }

    /** POST financeiro/pagamentos/{uuid}/estornar → {pagamento, saldo}; 409 se já estornado. */
    public function estornarPagamento(Request $request, string $id)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }
        $empresa   = $this->empresa();
        $pagamento = Pagamentos::porUuid($empresa, $id);
        if (!$pagamento) {
            return $this->erro('Pagamento não encontrado.', 404);
        }
        // o pagamento de um saque Pix pelo app não se estorna à mão: em andamento, a rotina resolve; concluído, o dinheiro já
        // saiu (corrige-se com ajuste); falho, já foi estornado sozinho
        if ($saque = Saques::doPagamento($empresa, $id)) {
            return $this->erro(match ((string) $saque->situacao) {
                Saque::CONCLUIDO => 'O dinheiro do saque já saiu; corrija com um ajuste.',
                Saque::FALHOU    => 'Este saque não foi concluído e o pagamento já foi estornado.',
                default          => 'Saque em andamento.',
            });
        }
        if (!Pagamentos::estornar($pagamento, $this->usuario($request))) {
            return $this->erro('Este pagamento já foi estornado.', 409);
        }

        return response()->json([
            'pagamento' => ExtratoDaConta::pagamento(Pagamentos::porUuid($empresa, $id) ?? $pagamento),
            'saldo'     => ExtratoDaConta::saldo($empresa, (string) $pagamento->conta_tipo, (string) $pagamento->conta_uuid),
        ]);
    }

    /** GET financeiro/configuracoes → {corte, cobranca_padrao, ligada}. */
    public function configuracoes(Request $request)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }

        return response()->json($this->formatoDasConfiguracoes($this->empresa()));
    }

    /**
     * PUT financeiro/configuracoes {corte: 'Y-m-d' | null, cobranca_padrao: {ciclo, dia_fechamento, prazo_dias}} (cada
     * um opcional: o que não vem fica como está) → o formato completo do GET. `ligada` é só leitura (ENTREGAS_FINANCEIRO).
     */
    public function salvarConfiguracoes(Request $request)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }
        $empresa = $this->empresa();
        $dados   = $request->all();

        $corte = null;
        if (array_key_exists('corte', $dados) && $dados['corte'] !== null && $dados['corte'] !== '') {
            $corte = static::dia($dados['corte']);
            if ($corte === null) {
                return $this->erro('Data de corte inválida.');
            }
        }

        $cobranca = null;
        if (array_key_exists('cobranca_padrao', $dados)) {
            if (!is_array($dados['cobranca_padrao'])) {
                return $this->erro('Ciclo de cobrança inválido.');
            }
            $cobranca = static::cobranca($dados['cobranca_padrao']);
            if (is_string($cobranca)) {
                return $this->erro($cobranca);
            }
        }

        if (array_key_exists('corte', $dados)) {
            Setting::configure('company.' . $empresa . '.entregas.financeiro_corte', $corte);
        }
        if ($cobranca !== null) {
            Setting::configure('company.' . $empresa . '.entregas.cobranca_padrao', $cobranca);
        }

        return response()->json($this->formatoDasConfiguracoes($empresa));
    }

    /**
     * Ciclo de cobrança conferido ({ciclo, dia_fechamento, prazo_dias} com números) ou a mensagem de erro, pelas regras
     * do CicloDeCobranca (as mesmas do ciclo de cada loja): semanal dia 0..6 (0 = domingo), mensal 0..28 (0 = último
     * dia), quinzenal grava dia 0, prazo 0..60.
     */
    public static function cobranca(array $config): array|string
    {
        return CicloDeCobranca::validar($config) ?? CicloDeCobranca::normalizar($config);
    }

    /** Mensagem do valor no formato de milhar com ponto e sem vírgula ("1.500"), que viraria 1,50. */
    public const MILHAR_SEM_CENTAVOS = 'Valor ambíguo: para mil e quinhentos, escreva 1500 ou 1.500,00 (vírgula antes dos centavos).';

    /**
     * Texto no formato de milhar com ponto e sem vírgula ("1.500", "-2.000", "12.345"): ambíguo (1,50 ou 1.500?), é
     * recusado com 422 (MILHAR_SEM_CENTAVOS) para não gravar R$ 1,50 por engano vindo de outro cliente da API.
     */
    public static function milharSemCentavos($valor): bool
    {
        return is_string($valor) && preg_match('/^[+-]?\d{1,3}(\.\d{3})+$/', preg_replace('/\s/', '', $valor)) === 1;
    }

    /**
     * Número digitado ou enviado: 30, "30", "30.5", "30,5", "-2,75", "1.234,56". null se não for número ou se for o
     * milhar ambíguo "1.500" (quem chama confere antes com milharSemCentavos, para a mensagem certa).
     */
    public static function numero($valor): ?float
    {
        if (static::milharSemCentavos($valor)) {
            return null;
        }
        if (is_int($valor) || is_float($valor)) {
            return is_finite((float) $valor) ? (float) $valor : null;
        }
        if (!is_string($valor)) {
            return null;
        }
        $texto = preg_replace('/\s/', '', $valor);
        if (str_contains($texto, ',')) {
            // vírgula decimal: os pontos são separador de milhar
            $texto = str_replace(',', '.', str_replace('.', '', $texto));
        }

        return preg_match('/^[+-]?\d+(\.\d+)?$/', $texto) ? (float) $texto : null;
    }

    /** 'Y-m-d' válido, ou null. */
    public static function dia($valor): ?string
    {
        if (!is_string($valor) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }
        [$ano, $mes, $dia] = array_map('intval', explode('-', $valor));

        return checkdate($mes, $dia, $ano) ? $valor : null;
    }

    protected function formatoDasConfiguracoes(string $empresa): array
    {
        return [
            'corte'           => Financeiro::corte($empresa),
            'cobranca_padrao' => Financeiro::cobrancaPadrao($empresa),
            'ligada'          => Financeiro::ligada(),
        ];
    }

    /** O push "Pagamento registrado" ao motoboy; uma falha só fica no log (o pagamento já está gravado). */
    protected function avisarMotoboy(string $empresa, string $driverUuid, object $pagamento): void
    {
        try {
            $motoboy = Driver::where('company_uuid', $empresa)->where('uuid', $driverUuid)->first();
            $motoboy?->notify(new PagamentoRegistrado((string) $pagamento->uuid, (float) $pagamento->valor, (string) $pagamento->forma));
        } catch (\Throwable $e) {
            Log::warning('[entregas] financeiro: push do pagamento não saiu', ['pagamento' => $pagamento->uuid ?? null, 'erro' => $e->getMessage()]);
        }
    }

    /** O push "Chave Pix alterada" ao motoboy, com a hora em que o saque volta (24 h); uma falha só fica no log. */
    protected function avisarPixAlterado(Driver $motoboy): void
    {
        try {
            $liberado = (new \DateTimeImmutable(now()->format('Y-m-d H:i:s'), new \DateTimeZone((string) (config('app.timezone') ?: Financeiro::FUSO_PADRAO))))
                ->modify('+' . Saque::CARENCIA_PIX_HORAS . ' hours')
                ->setTimezone(new \DateTimeZone(Financeiro::fuso($this->empresa())));
            $motoboy->notify(new PixDeSaqueAlterado($liberado->format('d/m') . ' às ' . $liberado->format('H:i')));
        } catch (\Throwable $e) {
            Log::warning('[entregas] saque: push da troca da chave Pix não saiu', ['motoboy' => $motoboy->uuid ?? null, 'erro' => get_class($e)]);
        }
    }

    /**
     * [inicio, fim, erro]: datas 'Y-m-d' de inicio e fim. Com $padraoMes, sem as duas = este mês (dia 1 até hoje, no fuso
     * da empresa); sem ele, sem as duas = sem limite (null, null).
     */
    protected function periodo(Request $request, bool $padraoMes): array
    {
        $inicio = $request->input('inicio');
        $fim    = $request->input('fim');
        if (($inicio === null || $inicio === '') && ($fim === null || $fim === '')) {
            if (!$padraoMes) {
                return [null, null, null];
            }
            $hoje = Financeiro::hoje($this->empresa());

            return [substr($hoje, 0, 8) . '01', $hoje, null];
        }

        $inicio = static::dia($inicio);
        $fim    = static::dia($fim);
        if ($inicio === null || $fim === null) {
            return [null, null, $this->erro('Período inválido.')];
        }
        if ($fim < $inicio) {
            return [null, null, $this->erro('O fim do período precisa ser depois do início.')];
        }

        return [$inicio, $fim, null];
    }

    /** [texto aparado ou null, erro]. */
    protected function texto($valor, int $maximo, string $mensagem): array
    {
        if ($valor === null) {
            return [null, null];
        }
        if (!is_scalar($valor)) {
            return [null, $this->erro($mensagem)];
        }
        $texto = trim((string) $valor);
        if ($texto === '') {
            return [null, null];
        }
        if (mb_strlen($texto) > $maximo) {
            return [null, $this->erro($mensagem)];
        }

        return [$texto, null];
    }

    /**
     * [public_id => uuid] das contas da empresa encontradas (motoboys ou lojas). O motoboy excluído (soft delete) também
     * (o acerto final: pagamento e lançamento depois da exclusão).
     */
    protected function contas(string $contaTipo, array $ids): array
    {
        $consulta = $contaTipo === Financeiro::CONTA_MOTOBOY ? Driver::withTrashed()->where('company_uuid', $this->empresa()) : Vendor::where('company_uuid', $this->empresa());
        $contas   = [];
        foreach ($consulta->whereIn('public_id', $ids)->get() as $conta) {
            $contas[(string) $conta->public_id] = (string) $conta->uuid;
        }

        return $contas;
    }

    /**
     * Se, em alguma das contas, o pedido tem corrida lançada e todas as corridas dele estão estornadas (sem corrida
     * viva, nada a descontar).
     */
    protected function corridaEstornada(string $contaTipo, array $contaUuids, string $orderUuid): bool
    {
        $corridas = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $this->empresa())
            ->where('conta_tipo', $contaTipo)
            ->whereIn('conta_uuid', $contaUuids)
            ->where('tipo', 'corrida')
            ->where('order_uuid', $orderUuid)
            ->get()
            ->all();
        if (!$corridas) {
            return false;
        }

        $estornadas = array_flip(Lancamentos::jaEstornados(array_map(fn ($l) => (int) $l->id, $corridas)));
        $vivas      = [];
        $daConta    = [];
        foreach ($corridas as $corrida) {
            $daConta[(string) $corrida->conta_uuid] = true;
            if (!isset($estornadas[(int) $corrida->id])) {
                $vivas[(string) $corrida->conta_uuid] = true;
            }
        }

        return (bool) array_diff_key($daConta, $vivas);
    }

    /** O motoboy da empresa pelo public_id, inclusive o excluído (o extrato dele continua acessível no console). */
    protected function motoboy(string $id): ?Driver
    {
        return Driver::withTrashed()->where('company_uuid', $this->empresa())->where('public_id', $id)->with(['user'])->first();
    }

    /**
     * Pedido da empresa pelo public_id ou pelo uuid; ou pelo número (internal_id, que se repete entre dias e lojas), só
     * entre os pedidos com corrida lançada numa das contas escolhidas. null = não achou; texto = a mensagem de erro (o
     * número é de mais de um pedido).
     */
    protected function pedido(string $codigo, string $contaTipo, array $contaUuids): Order|string|null
    {
        $empresa = $this->empresa();
        $pedido  = Order::where('company_uuid', $empresa)
            ->where(fn ($query) => $query->where('public_id', $codigo)->orWhere('uuid', $codigo))
            ->first();
        if ($pedido) {
            return $pedido;
        }

        $candidatos = [];
        foreach (Order::where('company_uuid', $empresa)->where('internal_id', $codigo)->get() as $candidato) {
            $candidatos[(string) $candidato->uuid] = $candidato;
        }
        if (!$candidatos) {
            return null;
        }

        $comCorrida = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $empresa)
            ->where('conta_tipo', $contaTipo)
            ->whereIn('conta_uuid', $contaUuids)
            ->where('tipo', 'corrida')
            ->whereIn('order_uuid', array_map('strval', array_keys($candidatos)))
            ->pluck('order_uuid')
            ->all();
        $comCorrida = array_values(array_unique(array_map('strval', $comCorrida)));
        if (count($comCorrida) > 1) {
            return 'Há mais de um pedido com esse número; use o código do pedido (order_…)';
        }

        return $comCorrida ? ($candidatos[$comCorrida[0]] ?? null) : null;
    }

    protected function empresa(): string
    {
        return (string) session('company');
    }

    protected function usuario(Request $request): ?string
    {
        $usuario = Auth::getUserFromSession($request);

        return $usuario?->uuid ? (string) $usuario->uuid : null;
    }

    protected function erro(string $mensagem, int $status = 422)
    {
        return response()->json(['errors' => [$mensagem]], $status);
    }

    protected function negarSeNaoAdmin(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);

        if (!$usuario || $usuario->isNotAdmin()) {
            return response()->json(['errors' => ['Somente administradores podem mexer no financeiro.']], 403);
        }

        return null;
    }
}
