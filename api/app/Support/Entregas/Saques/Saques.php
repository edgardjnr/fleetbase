<?php

namespace App\Support\Entregas\Saques;

use App\Notifications\Entregas\SaqueConcluido;
use App\Notifications\Entregas\SaqueNaoConcluido;
use App\Support\Entregas\Financeiro\ChavePix;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Financeiro\Pagamentos;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o saque Pix do motoboy (entregas_saques) e o que ele exige da linha do motoboy no Financeiro
 * (entregas_motoboys_financeiro: chave Pix, bloqueio, troca da chave). Desenho:
 * docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md.
 */
final class Saques
{
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** A linha do motoboy no Financeiro (chave Pix, bloqueio, troca); com $travar, lockForUpdate (dentro de transação). */
    public static function linhaDoMotoboy(string $companyUuid, string $driverUuid, bool $travar = false): ?object
    {
        $consulta = DB::table(ChavePix::TABELA)->where('company_uuid', $companyUuid)->where('driver_uuid', $driverUuid);
        if ($travar) {
            $consulta->lockForUpdate();
        }

        return $consulta->first();
    }

    /** Bloqueia ou libera o saque do motoboy (cria a linha sem chave Pix, se não houver). */
    public static function bloquear(string $companyUuid, string $driverUuid, bool $bloqueado): void
    {
        $agora = now()->format('Y-m-d H:i:s');
        if (static::linhaDoMotoboy($companyUuid, $driverUuid)) {
            DB::table(ChavePix::TABELA)->where('company_uuid', $companyUuid)->where('driver_uuid', $driverUuid)->update(['saque_bloqueado' => $bloqueado, 'updated_at' => $agora]);
        } else {
            DB::table(ChavePix::TABELA)->insert(['company_uuid' => $companyUuid, 'driver_uuid' => $driverUuid, 'saque_bloqueado' => $bloqueado, 'created_at' => $agora, 'updated_at' => $agora]);
        }
        Log::info('[entregas] saque: bloqueio do motoboy', ['empresa' => $companyUuid, 'motoboy' => $driverUuid, 'bloqueado' => $bloqueado]);
    }

    /** Até quando ('Y-m-d H:i:s') o saque fica bloqueado pela troca da chave Pix; null se não há carência agora. */
    public static function liberadoEm(?object $linha): ?string
    {
        $alterada = $linha->pix_alterada_em ?? null;
        if (!$alterada) {
            return null;
        }
        $liberado = (new \DateTimeImmutable((string) $alterada))->modify('+' . Saque::CARENCIA_PIX_HORAS . ' hours')->format('Y-m-d H:i:s');

        return $liberado > now()->format('Y-m-d H:i:s') ? $liberado : null;
    }

    /**
     * O bloco `saque` da "Minha carteira" do app: {disponivel, minimo, maximo_hoje, taxa, em_andamento, indisponivel_motivo};
     * null quando o saque não está liberado na central (chave geral, saque ligado e validação testada): o app esconde o botão.
     */
    public static function situacaoDoMotoboy(string $companyUuid, string $driverUuid): ?array
    {
        if (!Saque::ligada()) {
            return null;
        }
        $conexao = ConexaoAsaas::daEmpresa($companyUuid);
        if (!static::liberadoNaCentral($conexao)) {
            return null;
        }
        $limites   = ConexaoAsaas::limites($conexao);
        $andamento = static::emAndamento($companyUuid, $driverUuid);
        $calculo   = static::calculo($companyUuid, $driverUuid, $limites);

        return [
            'disponivel'          => Saque::reais($calculo['disponivel']),
            'minimo'              => $limites['minimo'],
            'maximo_hoje'         => Saque::reais($calculo['restante_do_dia']),
            'taxa'                => $limites['taxa'],
            'em_andamento'        => $andamento ? static::publico($andamento) : null,
            'indisponivel_motivo' => $andamento ? 'Saque em andamento.' : static::impedimento(static::linhaDoMotoboy($companyUuid, $driverUuid), $limites, $calculo),
        ];
    }

    /**
     * Pede o saque: confere tudo e, numa transação com a conexão da central e a linha do motoboy travadas, grava o saque
     * "enviando", o pagamento Pix no livro (o saldo já desconta) e a taxa. O $uuid vem do app: repetido devolve o mesmo
     * saque (`novo` false). Recusas: SaqueRecusado com a mensagem pt-BR. Quem chama manda ao Asaas depois do commit (enviar).
     *
     * @return array{saque: object, novo: bool}
     */
    public static function pedir(string $companyUuid, string $driverUuid, $uuid, $valor): array
    {
        if (!Saque::ligada()) {
            throw new SaqueRecusado('Saque Pix indisponível no momento.');
        }
        $uuid = is_string($uuid) ? strtolower(trim($uuid)) : '';
        if (!preg_match(static::UUID, $uuid)) {
            throw new SaqueRecusado('Pedido de saque inválido.');
        }
        if ($repetido = static::repetido($companyUuid, $driverUuid, $uuid)) {
            return ['saque' => $repetido, 'novo' => false];
        }
        $centavos = static::valorEmCentavos($valor);
        if ($centavos === null) {
            throw new SaqueRecusado('Informe o valor do saque.');
        }
        try {
            $saque = DB::transaction(function () use ($companyUuid, $driverUuid, $uuid, $centavos) {
                // a conexão relida travada: desconectar ou desligar no meio espera este pedido (e vice-versa)
                $conexao = DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->lockForUpdate()->first();
                if (!static::liberadoNaCentral($conexao)) {
                    throw new SaqueRecusado('A central não liberou o saque Pix.');
                }
                $limites = ConexaoAsaas::limites($conexao);
                // a linha do motoboy travada: dois pedidos ao mesmo tempo não passam juntos pelas conferências
                $linha = static::linhaDoMotoboy($companyUuid, $driverUuid, true);
                if (static::emAndamento($companyUuid, $driverUuid)) {
                    throw new SaqueRecusado('Já há um saque em andamento.');
                }
                $calculo = static::calculo($companyUuid, $driverUuid, $limites);
                if ($motivo = static::impedimento($linha, $limites, $calculo)) {
                    throw new SaqueRecusado($motivo);
                }
                $minimo = Saque::centavos($limites['minimo']);
                if ($centavos < $minimo) {
                    throw new SaqueRecusado('O valor mínimo do saque é ' . Saque::dinheiro(Saque::reais($minimo)) . '.');
                }
                if ($centavos > $calculo['disponivel']) {
                    throw new SaqueRecusado('Valor acima do disponível para saque (' . Saque::dinheiro(Saque::reais($calculo['disponivel'])) . ').');
                }

                $hoje      = Financeiro::hoje($companyUuid);
                $pagamento = Pagamentos::registrar($companyUuid, Financeiro::CONTA_MOTOBOY, $driverUuid, Saque::reais($centavos), 'pix', $hoje, null, Saque::OBSERVACAO_DO_PAGAMENTO, null);
                $taxa      = Saque::centavos($limites['taxa']);
                $taxaId    = $taxa > 0 ? Lancamentos::inserir([
                    'company_uuid'    => $companyUuid,
                    'conta_tipo'      => Financeiro::CONTA_MOTOBOY,
                    'conta_uuid'      => $driverUuid,
                    'tipo'            => 'ajuste',
                    'valor'           => -Saque::reais($taxa),
                    'data_referencia' => $hoje,
                    'motivo'          => Saque::MOTIVO_DA_TAXA,
                ]) : null;

                $agora = now()->format('Y-m-d H:i:s');
                DB::table(Saque::TABELA)->insert([
                    'uuid'               => $uuid,
                    'company_uuid'       => $companyUuid,
                    'driver_uuid'        => $driverUuid,
                    'valor'              => Saque::reais($centavos),
                    'taxa'               => Saque::reais($taxa),
                    'chave_pix'          => (string) $linha->chave_pix,
                    'tipo_chave'         => (string) $linha->tipo_chave,
                    'situacao'           => Saque::ENVIANDO,
                    'external_reference' => 'saque:' . $uuid,
                    'pagamento_uuid'     => (string) $pagamento->uuid,
                    'taxa_lancamento_id' => $taxaId,
                    'created_at'         => $agora,
                    'updated_at'         => $agora,
                ]);

                return DB::table(Saque::TABELA)->where('uuid', $uuid)->first();
            });
        } catch (QueryException $e) {
            // o mesmo uuid em dois pedidos simultâneos: a chave única segura o segundo, que devolve o primeiro
            if ($repetido = static::repetido($companyUuid, $driverUuid, $uuid)) {
                return ['saque' => $repetido, 'novo' => false];
            }
            throw $e;
        }

        Log::info('[entregas] saque: pedido', ['empresa' => $companyUuid, 'motoboy' => $driverUuid, 'saque' => $uuid, 'valor' => Saque::reais($centavos)]);

        return ['saque' => $saque, 'novo' => true];
    }

    /** O saque em andamento (enviando ou processando) do motoboy, ou null. */
    public static function emAndamento(string $companyUuid, string $driverUuid): ?object
    {
        return DB::table(Saque::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('driver_uuid', $driverUuid)
            ->whereIn('situacao', Saque::EM_ANDAMENTO)
            ->orderBy('id', 'desc')
            ->first();
    }

    /** O saque cujo pagamento é este (null se não é de saque, ou se a tabela ainda não existe: API antes da migration). */
    public static function doPagamento(string $companyUuid, string $pagamentoUuid): ?object
    {
        try {
            return DB::table(Saque::TABELA)->where('company_uuid', $companyUuid)->where('pagamento_uuid', $pagamentoUuid)->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** {situacao, comprovante_url} do saque deste pagamento, para o extrato e o recibo; null se não é de saque. */
    public static function marcaDoPagamento(object $pagamento): ?array
    {
        if (($pagamento->criado_por ?? null) !== null || ($pagamento->forma ?? null) !== 'pix') {
            return null;
        }
        $saque = static::doPagamento((string) $pagamento->company_uuid, (string) $pagamento->uuid);

        return $saque ? ['situacao' => (string) $saque->situacao, 'comprovante_url' => $saque->comprovante_url] : null;
    }

    /**
     * Os avisos do saque no "Atenção" do painel do Financeiro: saque_falhou (últimas 24 h) e saque_parado (em andamento há
     * mais de 1 h), até 20 de cada. Desligado ou com o banco sem a tabela: nada.
     */
    public static function atencao(string $companyUuid): array
    {
        if (!Saque::ligada()) {
            return [];
        }
        try {
            $falhos = DB::table(Saque::TABELA)
                ->where('company_uuid', $companyUuid)
                ->where('situacao', Saque::FALHOU)
                ->where('updated_at', '>=', now()->subHours(24)->format('Y-m-d H:i:s'))
                ->orderBy('id', 'desc')
                ->limit(Saque::LIMITE_NO_PAINEL)
                ->get()
                ->all();
            $parados = DB::table(Saque::TABELA)
                ->where('company_uuid', $companyUuid)
                ->whereIn('situacao', Saque::EM_ANDAMENTO)
                ->where('created_at', '<=', now()->subMinutes(Saque::MINUTOS_PARADO)->format('Y-m-d H:i:s'))
                ->orderBy('id')
                ->limit(Saque::LIMITE_NO_PAINEL)
                ->get()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[entregas] saque: painel sem os avisos de saque', ['empresa' => $companyUuid, 'erro' => get_class($e)]);

            return [];
        }

        $nomes = [];
        $uuids = array_values(array_unique(array_map(fn ($s) => (string) $s->driver_uuid, array_merge($falhos, $parados))));
        if ($uuids) {
            foreach (Driver::withTrashed()->where('company_uuid', $companyUuid)->whereIn('uuid', $uuids)->get() as $motoboy) {
                $nomes[(string) $motoboy->uuid] = $motoboy->name;
            }
        }

        $itens = [];
        foreach ($falhos as $saque) {
            $itens[] = ['tipo' => 'saque_falhou', 'saque' => (string) $saque->uuid, 'motoboy' => $nomes[(string) $saque->driver_uuid] ?? null, 'valor' => round((float) $saque->valor, 2), 'motivo' => static::motivoNoConsole($saque)];
        }
        foreach ($parados as $saque) {
            $itens[] = ['tipo' => 'saque_parado', 'saque' => (string) $saque->uuid, 'motoboy' => $nomes[(string) $saque->driver_uuid] ?? null, 'valor' => round((float) $saque->valor, 2), 'desde' => Saque::iso($saque->created_at, $companyUuid)];
        }

        return $itens;
    }

    /**
     * O saque no formato do app e do console: {id, valor, taxa, situacao, motivo, chave, tipo_chave, comprovante_url,
     * criado_em, concluido_em}. No app, `motivo` é o que o motoboy vê (nunca o texto cru do Asaas). Com $console, `motivo`
     * é o texto do Asaas quando houver, e vem também `motivo_asaas` (null se a falha não veio do Asaas).
     */
    public static function publico(object $saque, bool $console = false): array
    {
        $empresa = (string) $saque->company_uuid;
        $dados   = [
            'id'              => (string) $saque->uuid,
            'valor'           => round((float) $saque->valor, 2),
            'taxa'            => round((float) $saque->taxa, 2),
            'situacao'        => (string) $saque->situacao,
            'motivo'          => $console ? static::motivoNoConsole($saque) : $saque->motivo_falha,
            'chave'           => Saque::chaveEscondida((string) $saque->chave_pix, (string) $saque->tipo_chave),
            'tipo_chave'      => (string) $saque->tipo_chave,
            'comprovante_url' => $saque->comprovante_url,
            'criado_em'       => Saque::iso($saque->created_at, $empresa),
            'concluido_em'    => Saque::iso($saque->concluido_em, $empresa),
        ];
        if ($console) {
            $dados['motivo_asaas'] = ($saque->motivo_asaas ?? null) !== null ? (string) $saque->motivo_asaas : null;
        }

        return $dados;
    }

    /** O motivo da falha para a central: o texto do Asaas quando houver; senão o nosso. */
    private static function motivoNoConsole(object $saque): ?string
    {
        $doAsaas = $saque->motivo_asaas ?? null;

        return is_string($doAsaas) && $doAsaas !== '' ? $doAsaas : $saque->motivo_falha;
    }

    /** O saque relido do banco. */
    public static function recarregar(object $saque): object
    {
        return DB::table(Saque::TABELA)->where('id', $saque->id)->first() ?? $saque;
    }

    /**
     * Manda o saque ao Asaas (depois do commit do pedido): decifra a chave, confere a wallet fixada e cria a transferência
     * Pix com a chave do banco e o externalReference estável. Antes do POST, qualquer problema falha e reembolsa (nada
     * saiu). No POST: id → processando (ou já concluído/falho); recusa clara (400/401/403/404/422 com `errors` no corpo)
     * → falha e reembolsa; qualquer outra coisa (sem resposta, 5xx, 200 sem id, outro 4xx, 4xx sem `errors`) → fica
     * "enviando" e a rotina procura pelo externalReference. Devolve o saque relido.
     */
    public static function enviar(object $saque): object
    {
        $conexao = ConexaoAsaas::daEmpresa((string) $saque->company_uuid);
        $chave   = $conexao ? Cofre::decifrar((string) $conexao->chave_cifrada) : null;
        if ($chave === null) {
            static::falhar($saque, 'Saque Pix indisponível no momento. Avise a central.');

            return static::recarregar($saque);
        }

        $asaas     = new AsaasCliente($chave);
        $carteiras = $asaas->carteiras();
        if (!$carteiras->ok()) {
            static::falhar($saque, $carteiras->semResposta() ? 'O Asaas não respondeu. Tente de novo.' : 'A chave do Asaas da central foi recusada. Avise a central.');

            return static::recarregar($saque);
        }
        if (AsaasCliente::walletDa($carteiras) !== (string) $conexao->wallet_id) {
            Log::warning('[entregas] saque: a chave do Asaas é de outra carteira', ['empresa' => $saque->company_uuid]);
            static::falhar($saque, 'A chave do Asaas não é mais da conta conectada. Avise a central.');

            return static::recarregar($saque);
        }

        $resposta = $asaas->transferirPix(
            Saque::reais(Saque::centavos($saque->valor)),
            (string) $saque->chave_pix,
            Saque::TIPOS_ASAAS[(string) $saque->tipo_chave] ?? '',
            Saque::DESCRICAO_NO_ASAAS,
            (string) $saque->external_reference
        );
        $id = $resposta->dados['id'] ?? null;
        if ($resposta->ok() && is_string($id) && $id !== '') {
            static::registrarTransferencia($saque, $resposta->dados);
        } elseif (static::recusaClara($resposta)) {
            static::falhar($saque, Saque::MOTIVO_FALHA_DO_ASAAS, null, $resposta->mensagem('O Asaas recusou a transferência.'));
        } else {
            // sem resposta, 5xx, 200 sem id, 409, 429, 4xx sem `errors`...: pode ter saído; a rotina procura
            Log::warning('[entregas] saque: envio sem confirmação do Asaas; a rotina confere', ['saque' => $saque->uuid, 'status' => $resposta->status]);
        }

        return static::recarregar($saque);
    }

    /** Status em que o Asaas recusa sem criar a transferência, e só com a lista `errors` no corpo: aí falha e devolve. */
    public const RECUSAS_DO_ASAAS = [400, 401, 403, 404, 422];

    /** O Asaas recusou com certeza (status de recusa e `errors` no corpo): nada saiu. */
    private static function recusaClara(RespostaAsaas $resposta): bool
    {
        $erros = $resposta->dados['errors'] ?? null;

        return in_array($resposta->status, static::RECUSAS_DO_ASAAS, true) && is_array($erros) && $erros !== [];
    }

    /**
     * A transferência do Asaas é a deste saque: o valor (quando vem) e o externalReference (quando vem) batem. Se não
     * batem, Log::critical e false: quem chama não muda nada (o saque fica parado e aparece no painel).
     */
    public static function confere(object $saque, array $dados): bool
    {
        $valor      = $dados['value'] ?? null;
        $referencia = $dados['externalReference'] ?? null;
        $valorBate  = $valor === null || (is_numeric($valor) && Saque::centavos($valor) === Saque::centavos($saque->valor));
        $refBate    = $referencia === null || $referencia === '' || (string) $referencia === (string) $saque->external_reference;
        if ($valorBate && $refBate) {
            return true;
        }
        Log::critical('[entregas] saque: transferência não confere com o saque', [
            'empresa'  => $saque->company_uuid,
            'saque'    => $saque->uuid,
            'transfer' => is_scalar($dados['id'] ?? null) ? (string) $dados['id'] : null,
            'valor'    => $valorBate ? 'confere' : 'diferente',
            'externalReference' => $refBate ? 'confere' : 'diferente',
        ]);

        return false;
    }

    /**
     * Aplica o status de uma transferência do Asaas ao saque (só se ela confere com o saque: valor e externalReference).
     * true se ele terminou agora (concluído ou falho).
     */
    public static function aplicarStatus(object $saque, array $dados): bool
    {
        if (!static::confere($saque, $dados)) {
            return false;
        }
        $status   = strtoupper((string) ($dados['status'] ?? ''));
        $situacao = Saque::situacaoDoAsaas($status);
        if ($situacao === Saque::CONCLUIDO) {
            $url = $dados['transactionReceiptUrl'] ?? null;

            return static::concluir($saque, is_string($url) && $url !== '' ? mb_substr($url, 0, 500) : null);
        }
        if ($situacao === Saque::FALHOU) {
            if (is_string($dados['failReason'] ?? null) && trim($dados['failReason']) !== '') {
                // o texto do Asaas fica para a central; o motoboy vê a frase nossa
                return static::falhar($saque, Saque::MOTIVO_FALHA_DO_ASAAS, $status, trim($dados['failReason']));
            }

            return static::falhar($saque, $status === 'CANCELLED' ? 'Transferência cancelada no Asaas.' : 'Transferência não concluída pelo Asaas.', $status);
        }
        if ($status !== '') {
            DB::table(Saque::TABELA)->where('id', $saque->id)->update(['asaas_status' => mb_substr($status, 0, 30), 'updated_at' => now()->format('Y-m-d H:i:s')]);
        }

        return false;
    }

    /** Conclui o saque em andamento (guarda o comprovante do Asaas) e avisa o motoboy. false se ele não estava em andamento. */
    public static function concluir(object $saque, ?string $comprovante): bool
    {
        $agora = now()->format('Y-m-d H:i:s');
        $feito = DB::table(Saque::TABELA)
            ->where('id', $saque->id)
            ->whereIn('situacao', Saque::EM_ANDAMENTO)
            ->update(['situacao' => Saque::CONCLUIDO, 'asaas_status' => 'DONE', 'comprovante_url' => $comprovante, 'concluido_em' => $agora, 'updated_at' => $agora]);
        if ($feito < 1) {
            return false;
        }
        Log::info('[entregas] saque: concluído', ['empresa' => $saque->company_uuid, 'saque' => $saque->uuid, 'valor' => round((float) $saque->valor, 2)]);
        if ((static::recarregar($saque)->aprovado_em ?? null) === null) {
            // o dinheiro saiu sem a nossa validação ter aprovado: a validação foi desligada no Asaas, ou algo pior
            Log::critical('[entregas] saque: concluído sem a aprovação da validação', ['empresa' => $saque->company_uuid, 'saque' => $saque->uuid, 'transfer' => $saque->transfer_id ?? null]);
        }
        static::avisar($saque, new SaqueConcluido((string) $saque->uuid, (float) $saque->valor));

        return true;
    }

    /**
     * Falha o saque em andamento e, na mesma transação, estorna o pagamento (motivo "Pagamento estornado", que o app
     * reconhece) e a taxa; depois avisa o motoboy. false se ele não estava em andamento (nada muda, nada é estornado).
     * $motivo é o que o motoboy vê (push e app); $motivoDoAsaas, o texto cru do Asaas, fica só para o console.
     */
    public static function falhar(object $saque, string $motivo, ?string $statusDoAsaas = null, ?string $motivoDoAsaas = null): bool
    {
        $feito = DB::transaction(function () use ($saque, $motivo, $statusDoAsaas, $motivoDoAsaas) {
            $agora = now()->format('Y-m-d H:i:s');
            $n     = DB::table(Saque::TABELA)
                ->where('id', $saque->id)
                ->whereIn('situacao', Saque::EM_ANDAMENTO)
                ->update([
                    'situacao'     => Saque::FALHOU,
                    'motivo_falha' => mb_substr($motivo, 0, 255),
                    'motivo_asaas' => $motivoDoAsaas !== null && trim($motivoDoAsaas) !== '' ? mb_substr(trim($motivoDoAsaas), 0, 255) : null,
                    'asaas_status' => $statusDoAsaas !== null && $statusDoAsaas !== '' ? mb_substr($statusDoAsaas, 0, 30) : null,
                    'updated_at'   => $agora,
                ]);
            if ($n < 1) {
                return false;
            }
            $pagamento = $saque->pagamento_uuid ? Pagamentos::porUuid((string) $saque->company_uuid, (string) $saque->pagamento_uuid) : null;
            if ($pagamento) {
                Pagamentos::estornar($pagamento, null);
            }
            $taxa = $saque->taxa_lancamento_id ? Lancamentos::porId((string) $saque->company_uuid, (int) $saque->taxa_lancamento_id) : null;
            if ($taxa) {
                Lancamentos::estornar($taxa, null, Saque::MOTIVO_TAXA_DEVOLVIDA);
            }

            return true;
        });
        if (!$feito) {
            return false;
        }
        Log::info('[entregas] saque: não concluído, valor devolvido', ['empresa' => $saque->company_uuid, 'saque' => $saque->uuid, 'motivo' => $motivo, 'motivo_asaas' => $motivoDoAsaas]);
        static::avisar($saque, new SaqueNaoConcluido((string) $saque->uuid, (float) $saque->valor, $motivo));

        return true;
    }

    /**
     * O id da resposta do NOSSO POST é o que vale: grava o transfer_id (se outro já estava gravado, Log::critical e fica o
     * do POST) e passa a processando; aplica o status. Id já usado por outro saque (chave única): Log::critical e o saque
     * fica "enviando" para a rotina (sem erro para o app).
     */
    private static function registrarTransferencia(object $saque, array $dados): void
    {
        $id      = (string) $dados['id'];
        $gravado = static::recarregar($saque)->transfer_id ?? null;
        if ($gravado !== null && (string) $gravado !== $id) {
            Log::critical('[entregas] saque: id da transferência diferente do gravado', ['empresa' => $saque->company_uuid, 'saque' => $saque->uuid, 'gravado' => (string) $gravado, 'do_post' => $id]);
        }
        if (!static::gravarTransferencia($saque, $id)) {
            return;
        }
        if (($dados['authorized'] ?? null) === false) {
            Log::warning('[entregas] saque: transferência aguardando autorização no Asaas (token)', ['saque' => $saque->uuid]);
        }
        static::aplicarStatus(static::recarregar($saque), $dados);
    }

    /**
     * Grava o transfer_id no saque ainda em andamento e o passa a processando. false se ele não estava em andamento ou se o
     * id já é de outro saque (chave única: Log::critical, nada muda).
     */
    public static function gravarTransferencia(object $saque, string $id): bool
    {
        try {
            $feito = DB::table(Saque::TABELA)
                ->where('id', $saque->id)
                ->whereIn('situacao', Saque::EM_ANDAMENTO)
                ->update(['transfer_id' => $id, 'situacao' => Saque::PROCESSANDO, 'updated_at' => now()->format('Y-m-d H:i:s')]);
        } catch (QueryException $e) {
            Log::critical('[entregas] saque: id da transferência já usado por outro saque', ['empresa' => $saque->company_uuid, 'saque' => $saque->uuid, 'transfer' => $id]);

            return false;
        }
        if ($feito > 0) {
            return true;
        }
        $atual = static::recarregar($saque);
        if (in_array($atual->situacao ?? null, Saque::EM_ANDAMENTO, true) && (string) ($atual->transfer_id ?? '') === $id) {
            // o MySQL conta só linhas que mudaram: já estava gravado assim
            return true;
        }
        // o saque já terminou (falho e devolvido, ou concluído) e há uma transferência dele no Asaas: confira à mão
        Log::critical('[entregas] saque: transferência no Asaas para saque que não está em andamento', ['empresa' => $saque->company_uuid, 'saque' => $saque->uuid, 'situacao' => $atual->situacao ?? null, 'transfer' => $id]);

        return false;
    }

    /** Push ao motoboy do saque; uma falha só vai ao log. */
    private static function avisar(object $saque, object $notificacao): void
    {
        try {
            $motoboy = Driver::where('company_uuid', (string) $saque->company_uuid)->where('uuid', (string) $saque->driver_uuid)->first();
            $motoboy?->notify($notificacao);
        } catch (\Throwable $e) {
            Log::warning('[entregas] saque: push não saiu', ['saque' => $saque->uuid ?? null, 'erro' => get_class($e)]);
        }
    }

    private static function liberadoNaCentral(?object $conexao): bool
    {
        return $conexao !== null && (bool) $conexao->saque_ligado && $conexao->validacao_confirmada_em !== null;
    }

    /** O saque com este uuid, se for deste motoboy; de outro motoboy ou empresa, recusa. null se não existe. */
    private static function repetido(string $companyUuid, string $driverUuid, string $uuid): ?object
    {
        $saque = DB::table(Saque::TABELA)->where('uuid', $uuid)->first();
        if (!$saque) {
            return null;
        }
        if ((string) $saque->company_uuid !== $companyUuid || (string) $saque->driver_uuid !== $driverUuid) {
            throw new SaqueRecusado('Pedido de saque inválido.');
        }

        return $saque;
    }

    /** 50, 50.5, "50,50", "50.5" → centavos; outra coisa, zero ou negativo → null. */
    private static function valorEmCentavos($valor): ?int
    {
        if (is_int($valor) || is_float($valor)) {
            $centavos = Saque::centavos($valor);

            return $centavos > 0 ? $centavos : null;
        }
        if (is_string($valor) && preg_match('/^\d{1,7}([.,]\d{1,2})?$/', trim($valor))) {
            $centavos = Saque::centavos(str_replace(',', '.', trim($valor)));

            return $centavos > 0 ? $centavos : null;
        }

        return null;
    }

    /**
     * Em centavos: saldo, sacado e número de saques de hoje (os que não falharam; dia no fuso da empresa, que é o do
     * servidor), o que ainda cabe no máximo do dia e o disponível = min(saldo − taxa, restante do dia).
     */
    private static function calculo(string $companyUuid, string $driverUuid, array $limites): array
    {
        $saldo   = Saque::centavos(ExtratoDaConta::saldo($companyUuid, Financeiro::CONTA_MOTOBOY, $driverUuid));
        $inicio  = Financeiro::hoje($companyUuid) . ' 00:00:00';
        $sacado  = 0;
        $quantos = 0;
        $doDia   = DB::table(Saque::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('driver_uuid', $driverUuid)
            ->where('created_at', '>=', $inicio)
            ->get();
        foreach ($doDia as $saque) {
            if ($saque->situacao === Saque::FALHOU) {
                continue;
            }
            $sacado += Saque::centavos($saque->valor);
            $quantos++;
        }
        $restante = max(0, Saque::centavos($limites['maximo_dia']) - $sacado);

        return [
            'saldo'           => $saldo,
            'sacado_hoje'     => $sacado,
            'saques_hoje'     => $quantos,
            'restante_do_dia' => $restante,
            'disponivel'      => max(0, min($saldo - Saque::centavos($limites['taxa']), $restante)),
        ];
    }

    /** O motivo de o motoboy não poder sacar agora (fora o saque em andamento), ou null. */
    private static function impedimento(?object $linha, array $limites, array $calculo): ?string
    {
        if (!$linha || ($linha->chave_pix ?? null) === null || $linha->chave_pix === '') {
            return 'Sem chave Pix cadastrada: fale com a central.';
        }
        if ((bool) ($linha->saque_bloqueado ?? false)) {
            return 'Saque bloqueado pela central.';
        }
        if ($liberado = static::liberadoEm($linha)) {
            $quando = new \DateTimeImmutable($liberado);

            return 'Chave Pix alterada; saque liberado às ' . $quando->format('H:i') . ' de ' . $quando->format('d/m') . '.';
        }
        if ($calculo['saques_hoje'] >= (int) $limites['saques_dia']) {
            return 'Limite de saques de hoje atingido.';
        }
        $minimo = Saque::centavos($limites['minimo']);
        if ($calculo['restante_do_dia'] < $minimo) {
            return 'Limite de hoje atingido.';
        }
        if ($calculo['disponivel'] < $minimo) {
            return 'Saldo abaixo do mínimo para saque (' . Saque::dinheiro($limites['minimo']) . ').';
        }

        return null;
    }
}
