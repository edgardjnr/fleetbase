<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Financeiro\AvisosDaFatura;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Financeiro\Faturas;
use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Financeiro\Lancamentos;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: Financeiro → Lojas e faturas no console (int/v1/entregas/financeiro/*, só administradores).
 * - lojas (GET lojas): formato "Lojas" (saldo, em aberto, vencido, não faturado, ciclo, ciclo em andamento, e-mail);
 * - salvarCiclo (PUT lojas/{id}/ciclo {ciclo, dia_fechamento, prazo_dias}) → { loja };
 * - extratoDaLoja (GET lojas/{id}/extrato?inicio&fim): o extrato da conta da loja (ExtratoDaConta, completo);
 * - faturas (GET faturas?situacao&loja) → { faturas }; gerarRascunho (POST faturas {loja, inicio, fim}) → { fatura }
 *   (null = sem lançamentos no período ou período já faturado);
 * - fatura, mudarVencimento, ajuste, naoCobrar, emitir, cancelar → { fatura, itens };
 * - reenviar (POST faturas/{id}/reenviar) → { enviado: true } ou { enviado: false, motivo: desligado|sem_email|falha }.
 * {id} de loja = vendors.public_id; de fatura = uuid. Erros: { errors: ['mensagem pt-BR'] }, 422 para entrada inválida ou
 * operação que a situação da fatura não permite (ex.: ajuste em fatura emitida).
 *
 * O e-mail da fatura emitida é da AvisosDaFatura (tarefa B4): só é chamado se a classe existir, e um erro no envio não
 * desfaz a emissão.
 */
class FinanceiroLojasController extends Controller
{
    /** Tipo do Vendor que é loja (cliente da operadora), como no LojasController. */
    public const TIPO_LOJA = 'customer';

    public const AVISOS_PADRAO = AvisosDaFatura::class;

    public const SITUACOES = ['rascunho', 'emitida', 'cancelada', 'vencida', 'aberta', 'paga'];

    /** A classe dos e-mails da fatura (`emitida(object $fatura): bool`); trocável nos testes. */
    public static string $avisos = self::AVISOS_PADRAO;

    public function lojas(Request $request)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $companyUuid = (string) session('company');
        $lojas       = Vendor::where('company_uuid', $companyUuid)->where('type', static::TIPO_LOJA)->get();

        return response()->json(['lojas' => Faturas::linhasDasLojas($companyUuid, $lojas)]);
    }

    public function salvarCiclo(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor = $this->loja($id);
        if (!$vendor) {
            return $this->erro('Loja não encontrada.', 404);
        }

        $config = [
            'ciclo'          => $request->input('ciclo'),
            'dia_fechamento' => $request->input('dia_fechamento', $request->input('ciclo') === 'quinzenal' ? 0 : null),
            'prazo_dias'     => $request->input('prazo_dias'),
        ];
        try {
            Faturas::salvarConfig((string) session('company'), (string) $vendor->uuid, $config);
        } catch (\InvalidArgumentException $e) {
            return $this->erro($e->getMessage(), 422);
        }

        return response()->json(['loja' => Faturas::linhasDasLojas((string) session('company'), [$vendor])[0]]);
    }

    public function extratoDaLoja(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor = $this->loja($id);
        if (!$vendor) {
            return $this->erro('Loja não encontrada.', 404);
        }
        $inicio = $request->input('inicio');
        $fim    = $request->input('fim');
        foreach ([$inicio, $fim] as $data) {
            if ($data !== null && $data !== '' && (!is_string($data) || !$this->dataValida($data))) {
                return $this->erro('Data inválida.', 422);
            }
        }
        if ($inicio && $fim && $inicio > $fim) {
            return $this->erro('O início do período precisa ser antes do fim.', 422);
        }

        return response()->json(ExtratoDaConta::montar((string) session('company'), Financeiro::CONTA_LOJA, (string) $vendor->uuid, $inicio ?: null, $fim ?: null, false));
    }

    public function faturas(Request $request)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $companyUuid = (string) session('company');
        $situacao    = $request->input('situacao');
        if ($situacao !== null && $situacao !== '' && !in_array($situacao, static::SITUACOES, true)) {
            return $this->erro('Situação desconhecida.', 422);
        }

        $consulta = DB::table(Faturas::TABELA)->where('company_uuid', $companyUuid);
        if ($request->input('loja')) {
            $vendor = $this->loja((string) $request->input('loja'));
            if (!$vendor) {
                return $this->erro('Loja não encontrada.', 404);
            }
            $consulta->where('vendor_uuid', $vendor->uuid);
        }
        if (in_array($situacao, ['rascunho', 'emitida', 'cancelada'], true)) {
            $consulta->where('situacao', $situacao);
        } elseif ($situacao) {
            // vencida, aberta e paga são calculadas: só as emitidas, filtradas depois do resumo
            $consulta->where('situacao', 'emitida');
        }
        $faturas = $consulta->orderBy('periodo_fim', 'desc')->orderBy('id', 'desc')->get()->all();

        $resumos = Faturas::resumos($companyUuid, $faturas, false);
        $resumos = array_values(array_filter($resumos, fn ($f) => match ($situacao) {
            'vencida' => $f['vencida'],
            'aberta'  => $f['pagamento'] !== 'paga',
            'paga'    => $f['pagamento'] === 'paga',
            default   => true,
        }));

        return response()->json(['faturas' => $resumos]);
    }

    public function gerarRascunho(Request $request)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        if (!$request->input('loja')) {
            return $this->erro('Escolha a loja.', 422);
        }
        $vendor = $this->loja((string) $request->input('loja'));
        if (!$vendor) {
            return $this->erro('Loja não encontrada.', 404);
        }
        $inicio = $request->input('inicio');
        $fim    = $request->input('fim');
        if (!is_string($inicio) || !is_string($fim) || !$this->dataValida($inicio) || !$this->dataValida($fim)) {
            return $this->erro('Informe o período (início e fim).', 422);
        }

        try {
            $fatura = Faturas::gerarRascunho((string) session('company'), (string) $vendor->uuid, $inicio, $fim, $this->usuarioUuid($request));
        } catch (\InvalidArgumentException $e) {
            return $this->erro($e->getMessage(), 422);
        }

        return response()->json(['fatura' => $fatura ? Faturas::resumo($fatura, [], (string) $vendor->name, false, (string) $vendor->public_id) : null]);
    }

    public function fatura(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        return $this->naFatura($id, fn () => null);
    }

    public function mudarVencimento(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        return $this->naFatura($id, fn ($fatura) => Faturas::mudarVencimento($fatura, (string) $request->input('vencimento', '')));
    }

    public function ajuste(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        if (FinanceiroController::milharSemCentavos($request->input('valor'))) {
            return $this->erro(FinanceiroController::MILHAR_SEM_CENTAVOS, 422);
        }
        $valor = $this->numero($request->input('valor'));
        if ($valor === null) {
            return $this->erro('Informe o valor do ajuste.', 422);
        }
        $obs = $request->input('observacao_interna');

        return $this->naFatura($id, fn ($fatura) => Faturas::ajuste($fatura, $valor, (string) ($request->input('motivo') ?? ''), is_string($obs) ? $obs : null, $this->usuarioUuid($request)));
    }

    public function naoCobrar(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $lancamentoId = $request->input('lancamento_id');
        $lancamento   = is_numeric($lancamentoId) ? Lancamentos::porId((string) session('company'), (int) $lancamentoId) : null;
        if (!$lancamento) {
            return $this->erro('Lançamento não encontrado.', 422);
        }

        return $this->naFatura($id, fn ($fatura) => Faturas::naoCobrar($fatura, $lancamento, $this->usuarioUuid($request)));
    }

    public function emitir(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        return $this->naFatura($id, function ($fatura) use ($request) {
            $emitida = Faturas::emitir($fatura, $this->usuarioUuid($request));
            // o e-mail não desfaz a emissão: o erro fica no log e o painel mostra "não enviada por e-mail"
            try {
                $this->enviarEmail($emitida);
            } catch (\Throwable $e) {
                Log::warning('[entregas] financeiro: e-mail da fatura falhou', ['fatura' => $emitida->uuid, 'erro' => get_class($e)]);
            }
        });
    }

    public function cancelar(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        return $this->naFatura($id, fn ($fatura) => Faturas::cancelar($fatura, $this->usuarioUuid($request)));
    }

    /** Apaga o rascunho (feito errado ou de teste); a emitida se cancela. 200 {apagada: true}. */
    public function apagar(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $fatura = Faturas::porUuid((string) session('company'), $id);
        if (!$fatura) {
            return $this->erro('Fatura não encontrada.', 404);
        }

        try {
            Faturas::apagar($fatura);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->erro($e->getMessage(), 422);
        }

        return response()->json(['apagada' => true]);
    }

    public function reenviar(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $fatura = Faturas::porUuid((string) session('company'), $id);
        if (!$fatura) {
            return $this->erro('Fatura não encontrada.', 404);
        }
        if ($fatura->situacao !== 'emitida') {
            return $this->erro('Só a fatura emitida vai por e-mail.', 422);
        }

        try {
            $enviado = $this->enviarEmail($fatura);
        } catch (\Throwable $e) {
            Log::warning('[entregas] financeiro: e-mail da fatura falhou', ['fatura' => $fatura->uuid, 'erro' => get_class($e)]);

            return $this->erro('Não foi possível enviar o e-mail.', 502);
        }

        return response()->json($enviado ? ['enviado' => true] : ['enviado' => false, 'motivo' => $this->motivoDoNaoEnvio($fatura)]);
    }

    /**
     * Por que o e-mail não saiu (a AvisosDaFatura só devolve false): 'desligado' (ENTREGAS_FINANCEIRO), 'sem_email' (nem a
     * loja nem os usuários do portal dela têm e-mail) ou 'falha' (o envio falhou; o motivo fica no log).
     */
    protected function motivoDoNaoEnvio(object $fatura): string
    {
        if (!Financeiro::ligada()) {
            return 'desligado';
        }
        $avisos = static::$avisos;
        if (class_exists($avisos) && method_exists($avisos, 'emailsDaLoja') && !$avisos::emailsDaLoja((string) $fatura->company_uuid, (string) $fatura->vendor_uuid)) {
            return 'sem_email';
        }

        return 'falha';
    }

    /**
     * Acha a fatura da empresa, roda a operação (as recusas viram 422) e devolve { fatura, itens } relidos.
     */
    protected function naFatura(string $id, \Closure $fazer)
    {
        $companyUuid = (string) session('company');
        $fatura      = Faturas::porUuid($companyUuid, $id);
        if (!$fatura) {
            return $this->erro('Fatura não encontrada.', 404);
        }

        try {
            $fazer($fatura);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->erro($e->getMessage(), 422);
        }

        $fatura = Faturas::porUuid($companyUuid, $id);

        return response()->json([
            'fatura' => Faturas::resumo($fatura, Faturas::situacoesDaLoja($companyUuid, (string) $fatura->vendor_uuid), null, false),
            'itens'  => Faturas::itensFormatados($fatura, false),
        ]);
    }

    /** Manda o e-mail da fatura emitida pela AvisosDaFatura (B4); sem a classe, não envia (false). */
    protected function enviarEmail(object $fatura): bool
    {
        $avisos = static::$avisos;
        if (!class_exists($avisos)) {
            return false;
        }

        return (bool) $avisos::emitida($fatura);
    }

    /** A loja (Vendor do tipo loja) da empresa da sessão, pelo public_id ou uuid; null se não achar. */
    protected function loja(string $id): ?Vendor
    {
        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        return Vendor::where('company_uuid', session('company'))
            ->where('type', static::TIPO_LOJA)
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
            ->first();
    }

    protected function usuarioUuid(Request $request): ?string
    {
        $usuario = Auth::getUserFromSession($request);

        return $usuario && !empty($usuario->uuid) ? (string) $usuario->uuid : null;
    }

    /** Número do corpo (número ou texto com vírgula ou ponto decimal); null se não for número. */
    protected function numero($valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        if (!is_string($valor)) {
            return null;
        }
        $texto = trim($valor);
        if (str_contains($texto, ',')) {
            $texto = str_replace(',', '.', str_replace('.', '', $texto));
        }

        return preg_match('/^[+-]?\d+(\.\d+)?$/', $texto) ? (float) $texto : null;
    }

    protected function dataValida(string $dia): bool
    {
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $dia);

        return $data && $data->format('Y-m-d') === $dia;
    }

    protected function erro(string $mensagem, int $status)
    {
        return response()->json(['errors' => [$mensagem]], $status);
    }

    protected function negarSeNaoAdmin(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);
        if (!$usuario || $usuario->isNotAdmin()) {
            return $this->erro('Somente administradores podem ver e mexer no financeiro.', 403);
        }

        return null;
    }
}
