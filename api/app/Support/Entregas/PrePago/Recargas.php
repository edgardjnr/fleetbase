<?php

namespace App\Support\Entregas\PrePago;

use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Financeiro\Pagamentos;
use App\Support\Entregas\Saques\AsaasCliente;
use App\Support\Entregas\Saques\Cofre;
use App\Support\Entregas\Saques\CofreIndisponivel;
use App\Support\Entregas\Saques\ConexaoAsaas;
use App\Support\Entregas\Saques\RespostaAsaas;
use App\Support\Entregas\Saques\Saque;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Entregas RestaurantePro: a recarga da carteira da loja pré-paga (entregas_recargas). A loja pede o valor no portal; o
 * servidor cria uma cobrança Pix na conta Asaas da central (a mesma conexão do saque Pix, com a chave cifrada no Cofre) e
 * devolve o QR code. Quem confirma o pagamento é sempre o servidor, consultando o Asaas com a chave da central (o portal
 * enquanto o QR está na tela e a rotina entregas:recargas-conferir a cada minuto): não há webhook, então ninguém de fora
 * consegue avisar um pagamento falso. O crédito só entra se a cobrança do Asaas confere com a recarga (id, valor,
 * externalReference, PIX e cliente) e está paga, numa transação com a recarga travada: entra uma vez só, como um
 * recebimento Pix na conta da loja (Pagamentos::registrar), que o extrato e as faturas já entendem.
 *
 * Situações: criando (pedida, ainda sem o id do Asaas) → aguardando (QR na tela) → paga | expirada (o QR passou de
 * MINUTOS_DO_PIX e a cobrança foi apagada no Asaas, ou a loja cancelou) | falhou (o Asaas não gerou a cobrança).
 */
final class Recargas
{
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /** O que a loja vê quando a cobrança não sai (o texto do Asaas fica só no log). */
    public const MOTIVO_SEM_PIX = 'Não foi possível gerar o Pix agora. Tente de novo ou fale com a central.';
    public const MOTIVO_EXPIROU = 'O Pix expirou sem pagamento.';
    public const MOTIVO_CANCELADA = 'Pix cancelado.';

    /** Status de recusa do Asaas que não criam a cobrança (com `errors` no corpo): a recarga falha. */
    public const RECUSAS_DO_ASAAS = [400, 401, 403, 404, 422];

    /** Maior QR code aceito em base64 (o do Asaas tem uns 10 a 30 KB). */
    public const TAMANHO_MAXIMO_DA_IMAGEM = 500000;

    public static function porUuid(string $companyUuid, string $uuid): ?object
    {
        return DB::table(PrePago::RECARGAS)->where('company_uuid', $companyUuid)->where('uuid', $uuid)->first();
    }

    /** As recargas da loja, mais nova primeiro. */
    public static function daLoja(string $companyUuid, string $lojaUuid, int $limite = 20): array
    {
        return DB::table(PrePago::RECARGAS)
            ->where('company_uuid', $companyUuid)
            ->where('loja_uuid', $lojaUuid)
            ->orderBy('id', 'desc')
            ->limit($limite)
            ->get()
            ->all();
    }

    /** A recarga em andamento (criando ou aguardando) da loja, ou null. */
    public static function emAndamento(string $companyUuid, string $lojaUuid): ?object
    {
        return DB::table(PrePago::RECARGAS)
            ->where('company_uuid', $companyUuid)
            ->where('loja_uuid', $lojaUuid)
            ->whereIn('situacao', PrePago::EM_ANDAMENTO)
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Pede a recarga: confere tudo e, com a linha da carteira da loja travada (uma recarga em andamento por loja; limite
     * do dia), grava a recarga "criando"; depois do commit, cria a cobrança no Asaas. Devolve a recarga relida.
     * Recusas: RecargaRecusada (422). Sem o secret do Cofre: CofreIndisponivel (503).
     */
    public static function pedir(string $companyUuid, string $lojaUuid, string $nomeDaLoja, $valor, ?string $usuarioUuid): object
    {
        if (!PrePago::ligada()) {
            throw new RecargaRecusada('Recarga por Pix indisponível no momento.');
        }
        $centavos = static::valorEmCentavos($valor);
        if ($centavos === null) {
            throw new RecargaRecusada('Informe o valor da recarga.');
        }
        if ($centavos < PrePago::centavos(PrePago::VALOR_MINIMO)) {
            throw new RecargaRecusada('A recarga mínima é de ' . PrePago::dinheiro(PrePago::VALOR_MINIMO) . '.');
        }
        if ($centavos > PrePago::centavos(PrePago::VALOR_MAXIMO)) {
            throw new RecargaRecusada('A recarga máxima é de ' . PrePago::dinheiro(PrePago::VALOR_MAXIMO) . '.');
        }
        $conexao = ConexaoAsaas::daEmpresa($companyUuid);
        if (!$conexao) {
            throw new RecargaRecusada('A central ainda não ligou a recarga por Pix. Fale com a central.');
        }
        $chave = Cofre::decifrar((string) $conexao->chave_cifrada, $companyUuid);
        if ($chave === null) {
            if (!Cofre::disponivel()) {
                throw new CofreIndisponivel();
            }
            Log::warning('[entregas] recarga: a chave do Asaas gravada não abre (o secret mudou)', ['empresa' => $companyUuid]);
            throw new RecargaRecusada('Recarga por Pix indisponível no momento. Fale com a central.');
        }

        $recarga = DB::transaction(function () use ($companyUuid, $lojaUuid, $centavos, $conexao, $usuarioUuid) {
            // a conexão relida travada: desconectar no meio espera esta recarga nascer (e depois a recusa), e vice-versa
            $travada = DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->lockForUpdate()->first();
            if (!$travada || (string) $travada->wallet_id !== (string) $conexao->wallet_id) {
                throw new RecargaRecusada('A central ainda não ligou a recarga por Pix. Fale com a central.');
            }
            // a linha da carteira travada: dois pedidos ao mesmo tempo não passam juntos pelas conferências
            $carteira = PrePago::linha($companyUuid, $lojaUuid, true);
            if (($carteira->modelo ?? PrePago::POS) !== PrePago::PRE) {
                throw new RecargaRecusada('Esta loja não usa a carteira pré-paga.');
            }
            if (!PrePago::documento($carteira->documento ?? null)) {
                throw new RecargaRecusada('O cadastro da loja está sem CPF/CNPJ. Fale com a central.');
            }
            if (static::emAndamento($companyUuid, $lojaUuid)) {
                throw new RecargaRecusada('Já há um Pix de recarga aguardando pagamento.');
            }
            $hoje = DB::table(PrePago::RECARGAS)
                ->where('company_uuid', $companyUuid)
                ->where('loja_uuid', $lojaUuid)
                ->where('created_at', '>=', Financeiro::hoje($companyUuid) . ' 00:00:00')
                ->count();
            if ($hoje >= PrePago::RECARGAS_POR_DIA) {
                throw new RecargaRecusada('Limite de recargas de hoje atingido.');
            }

            $uuid  = (string) Str::uuid();
            $agora = now()->format('Y-m-d H:i:s');
            DB::table(PrePago::RECARGAS)->insert([
                'uuid'               => $uuid,
                'company_uuid'       => $companyUuid,
                'loja_uuid'          => $lojaUuid,
                'valor'              => PrePago::reais($centavos),
                'situacao'           => PrePago::CRIANDO,
                'external_reference' => 'recarga:' . $uuid,
                'wallet_id'          => (string) $conexao->wallet_id,
                'pedida_por'         => $usuarioUuid,
                'created_at'         => $agora,
                'updated_at'         => $agora,
            ]);

            return static::porUuid($companyUuid, $uuid);
        });
        Log::info('[entregas] recarga: pedida', ['empresa' => $companyUuid, 'loja' => $lojaUuid, 'recarga' => $recarga->uuid, 'valor' => PrePago::reais($centavos)]);

        return static::enviar($recarga, new AsaasCliente($chave), $conexao, $nomeDaLoja);
    }

    /**
     * Cria a cobrança Pix no Asaas: confere a wallet, garante o cliente da loja, cria a cobrança e busca o QR code. Antes do
     * POST da cobrança, qualquer problema falha (nada foi criado). No POST: id → aguardando; recusa clara → falha; sem
     * resposta (a cobrança pode ter nascido) → fica "criando" e a rotina procura pelo externalReference.
     */
    private static function enviar(object $recarga, AsaasCliente $asaas, object $conexao, string $nomeDaLoja): object
    {
        $carteiras = $asaas->carteiras();
        if (!$carteiras->ok() || AsaasCliente::walletDa($carteiras) !== (string) $conexao->wallet_id) {
            Log::warning('[entregas] recarga: a chave do Asaas não respondeu ou é de outra carteira', ['recarga' => $recarga->uuid, 'status' => $carteiras->status]);
            static::falhar($recarga, static::MOTIVO_SEM_PIX);

            return static::recarregar($recarga);
        }
        $cliente = static::clienteDaLoja($asaas, $recarga, $conexao, $nomeDaLoja);
        if ($cliente === null) {
            static::falhar($recarga, static::MOTIVO_SEM_PIX);

            return static::recarregar($recarga);
        }

        $valor    = PrePago::reais(PrePago::centavos($recarga->valor));
        $resposta = $asaas->criarCobrancaPix($cliente, $valor, Financeiro::hoje((string) $recarga->company_uuid), PrePago::DESCRICAO_NO_ASAAS, (string) $recarga->external_reference);
        $id       = $resposta->dados['id'] ?? null;
        if ($resposta->ok() && is_string($id) && $id !== '') {
            static::adotar($recarga, $id, $cliente, $resposta->dados['status'] ?? null);
            static::buscarQrCode($asaas, static::recarregar($recarga));
        } elseif (static::recusaClara($resposta)) {
            Log::warning('[entregas] recarga: o Asaas recusou a cobrança', ['recarga' => $recarga->uuid, 'status' => $resposta->status, 'motivo' => $resposta->mensagem('sem motivo')]);
            static::falhar($recarga, static::MOTIVO_SEM_PIX);
        } else {
            // sem resposta, 5xx, 200 sem id...: a cobrança pode ter nascido; a rotina procura pelo externalReference
            Log::warning('[entregas] recarga: cobrança sem confirmação do Asaas; a rotina confere', ['recarga' => $recarga->uuid, 'status' => $resposta->status]);
        }

        return static::recarregar($recarga);
    }

    /** O id do cliente da loja no Asaas da central: o gravado (mesma wallet e mesmo documento) ou um novo; null se falhou. */
    private static function clienteDaLoja(AsaasCliente $asaas, object $recarga, object $conexao, string $nomeDaLoja): ?string
    {
        $carteira = PrePago::linha((string) $recarga->company_uuid, (string) $recarga->loja_uuid);
        if (!$carteira) {
            return null;
        }
        if ($carteira->asaas_cliente_id
            && (string) $carteira->asaas_cliente_wallet === (string) $conexao->wallet_id
            && (string) $carteira->asaas_cliente_documento === (string) $carteira->documento) {
            return (string) $carteira->asaas_cliente_id;
        }
        $nome     = trim($nomeDaLoja) !== '' ? mb_substr(trim($nomeDaLoja), 0, 100) : 'Loja';
        $resposta = $asaas->criarCliente($nome, (string) $carteira->documento, 'loja:' . $recarga->loja_uuid);
        $id       = $resposta->dados['id'] ?? null;
        if (!$resposta->ok() || !is_string($id) || $id === '') {
            Log::warning('[entregas] recarga: o Asaas não cadastrou o cliente da loja', ['recarga' => $recarga->uuid, 'status' => $resposta->status, 'motivo' => $resposta->mensagem('sem motivo')]);

            return null;
        }
        DB::table(PrePago::CARTEIRAS)->where('id', $carteira->id)->update([
            'asaas_cliente_id'        => $id,
            'asaas_cliente_wallet'    => (string) $conexao->wallet_id,
            'asaas_cliente_documento' => (string) $carteira->documento,
            'updated_at'              => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /** Grava o id da cobrança (só na recarga ainda "criando" e sem id): aguardando, com o prazo do QR desde o pedido. */
    private static function adotar(object $recarga, string $paymentId, ?string $cliente, $status): void
    {
        $expira = (new \DateTimeImmutable((string) $recarga->created_at))->modify('+' . PrePago::MINUTOS_DO_PIX . ' minutes')->format('Y-m-d H:i:s');
        try {
            DB::table(PrePago::RECARGAS)
                ->where('id', $recarga->id)
                ->where('situacao', PrePago::CRIANDO)
                ->whereNull('payment_id')
                ->update([
                    'payment_id'   => $paymentId,
                    'cliente_id'   => $cliente,
                    'asaas_status' => is_string($status) ? mb_substr($status, 0, 30) : null,
                    'situacao'     => PrePago::AGUARDANDO,
                    'expira_em'    => $expira,
                    // acabou de falar com o Asaas: a primeira pergunta do portal (em menos de 5 s) não repete a consulta
                    'consultada_em' => now()->format('Y-m-d H:i:s'),
                    'updated_at'   => now()->format('Y-m-d H:i:s'),
                ]);
        } catch (QueryException $e) {
            // só a chave única repetida (1062): outro erro (trava, banco fora) sobe e a recarga fica para a rotina
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            // o id já é de outra recarga: esta cobrança não é desta recarga, que falha
            Log::critical('[entregas] recarga: id da cobrança já usado por outra recarga; conferir à mão', ['recarga' => $recarga->uuid]);
            static::falhar($recarga, static::MOTIVO_SEM_PIX);
        }
    }

    /** Busca e grava o QR code (copia e cola e a imagem PNG em base64). true se gravou. */
    private static function buscarQrCode(AsaasCliente $asaas, object $recarga): bool
    {
        if ($recarga->situacao !== PrePago::AGUARDANDO || !$recarga->payment_id) {
            return false;
        }
        $resposta = $asaas->qrCodePix((string) $recarga->payment_id);
        $payload  = $resposta->dados['payload'] ?? null;
        $imagem   = $resposta->dados['encodedImage'] ?? null;
        if (!$resposta->ok() || !is_string($payload) || $payload === '' || strlen($payload) > 2000) {
            Log::warning('[entregas] recarga: o Asaas não devolveu o QR code', ['recarga' => $recarga->uuid, 'status' => $resposta->status, 'motivo' => $resposta->mensagem('sem motivo')]);

            return false;
        }
        // só base64 de verdade vai para a tela (vira data:image/png;base64,... no portal)
        $imagemValida = is_string($imagem) && strlen($imagem) <= static::TAMANHO_MAXIMO_DA_IMAGEM && preg_match('#^[A-Za-z0-9+/]+={0,2}\z#', $imagem);
        DB::table(PrePago::RECARGAS)->where('id', $recarga->id)->where('situacao', PrePago::AGUARDANDO)->update([
            'pix_copia_cola' => $payload,
            'pix_imagem'     => $imagemValida ? $imagem : null,
            'updated_at'     => now()->format('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Pergunta ao Asaas e aplica: paga (e confere) → credita; apagada → expirada; QR vencido → apaga a cobrança e expira;
     * sem o QR → busca de novo. Com $respeitarIntervalo (o portal), no máximo uma consulta a cada SEGUNDOS_ENTRE_CONSULTAS.
     * Nunca lança por erro do Asaas (espera a próxima volta). Devolve a recarga relida.
     */
    public static function conferir(object $recarga, bool $respeitarIntervalo = false): object
    {
        if (!in_array($recarga->situacao, [PrePago::CRIANDO, PrePago::AGUARDANDO], true)) {
            return $recarga;
        }
        if ($respeitarIntervalo && $recarga->consultada_em
            && (string) $recarga->consultada_em > now()->subSeconds(PrePago::SEGUNDOS_ENTRE_CONSULTAS)->format('Y-m-d H:i:s')) {
            return $recarga;
        }
        if ($recarga->situacao === PrePago::CRIANDO
            && (string) $recarga->created_at > now()->subMinutes(PrePago::MINUTOS_CRIANDO)->format('Y-m-d H:i:s')) {
            // o POST da cobrança pode estar em andamento: só a rotina, depois de MINUTOS_CRIANDO, procura
            return $recarga;
        }
        $asaas = static::asaasDaRecarga($recarga);
        if (!$asaas) {
            return $recarga;
        }
        if (!static::reivindicarConsulta($recarga, $respeitarIntervalo)) {
            // outra requisição do portal acabou de consultar (pedidos em paralelo não multiplicam as chamadas ao Asaas)
            return static::recarregar($recarga);
        }
        if ($recarga->situacao === PrePago::CRIANDO) {
            return static::procurar($asaas, $recarga);
        }

        $resposta = $asaas->cobranca((string) $recarga->payment_id);
        if ($resposta->ok() && is_array($resposta->dados)) {
            static::aplicar($asaas, $recarga, $resposta->dados);
        } elseif ($resposta->status === 404 && static::venceu($recarga)) {
            Log::warning('[entregas] recarga: cobrança não encontrada no Asaas; expirada', ['recarga' => $recarga->uuid]);
            static::marcarExpirada($recarga, static::MOTIVO_EXPIROU, null);
        }

        return static::recarregar($recarga);
    }

    /** Aplica a cobrança do Asaas à recarga aguardando. */
    private static function aplicar(AsaasCliente $asaas, object $recarga, array $dados): void
    {
        $status = strtoupper((string) ($dados['status'] ?? ''));
        DB::table(PrePago::RECARGAS)->where('id', $recarga->id)->update(['asaas_status' => mb_substr($status, 0, 30) ?: null]);
        if (in_array($status, PrePago::PAGOS_NO_ASAAS, true)) {
            if (static::confere($recarga, $dados)) {
                static::creditar($recarga, $status);
            }

            return;
        }
        if (!empty($dados['deleted'])) {
            static::marcarExpirada($recarga, static::MOTIVO_EXPIROU, null);

            return;
        }
        if (static::venceu($recarga)) {
            static::expirar($asaas, $recarga, static::MOTIVO_EXPIROU, null);

            return;
        }
        if (!$recarga->pix_copia_cola) {
            static::buscarQrCode($asaas, $recarga);
        }
    }

    /**
     * A conferência tardia (rotina, uma vez, MINUTOS_CONFERENCIA_TARDIA depois de expirar): se o Asaas diz que a cobrança
     * foi paga mesmo assim, credita. Devolve true se creditou.
     */
    public static function conferirDepois(object $recarga): bool
    {
        if ($recarga->situacao !== PrePago::EXPIRADA || !$recarga->payment_id || $recarga->conferida_depois_em) {
            return false;
        }
        $asaas    = static::asaasDaRecarga($recarga);
        $resposta = $asaas ? $asaas->cobranca((string) $recarga->payment_id) : null;
        if ($resposta && $resposta->semResposta()) {
            // o Asaas fora do ar: tenta na próxima volta
            return false;
        }
        // uma vez só (sem a conta conectada também: o log crítico do asaasDaRecarga já pediu a conferência à mão)
        DB::table(PrePago::RECARGAS)->where('id', $recarga->id)->update(['conferida_depois_em' => now()->format('Y-m-d H:i:s')]);
        if (!$resposta) {
            return false;
        }
        $status = strtoupper((string) ($resposta->dados['status'] ?? ''));
        if ($resposta->ok() && in_array($status, PrePago::PAGOS_NO_ASAAS, true) && static::confere($recarga, $resposta->dados)) {
            Log::warning('[entregas] recarga: Pix pago depois de expirar; creditado', ['recarga' => $recarga->uuid]);

            return static::creditar($recarga, $status);
        }

        return false;
    }

    /**
     * A loja cancela o Pix aguardando: apaga a cobrança no Asaas. Se o Asaas não apagar (já paga, fora do ar), confere: a
     * paga é creditada; senão RecargaRecusada. Devolve a recarga relida.
     */
    public static function cancelar(object $recarga, ?string $usuarioUuid): object
    {
        if ($recarga->situacao === PrePago::CRIANDO) {
            throw new RecargaRecusada('Espere o Pix ser gerado.');
        }
        if ($recarga->situacao !== PrePago::AGUARDANDO) {
            return $recarga;
        }
        $asaas = static::asaasDaRecarga($recarga);
        if ($asaas && static::expirar($asaas, $recarga, static::MOTIVO_CANCELADA, $usuarioUuid)) {
            Log::info('[entregas] recarga: cancelada pela loja', ['recarga' => $recarga->uuid, 'por' => $usuarioUuid]);

            return static::recarregar($recarga);
        }
        $relida = static::conferir(static::recarregar($recarga));
        if ($relida->situacao === PrePago::AGUARDANDO) {
            throw new RecargaRecusada('Não foi possível cancelar agora. Tente de novo.');
        }

        return $relida;
    }

    /**
     * A recarga "criando" há mais de MINUTOS_CRIANDO (o POST da cobrança ficou sem resposta): procura a cobrança pelo
     * externalReference. Nenhuma → falha (nada nasceu). Uma → adota (e segue: paga credita; senão apaga e expira, porque
     * ninguém viu o QR). Mais de uma → Log::critical e espera alguém conferir no Asaas.
     */
    private static function procurar(AsaasCliente $asaas, object $recarga): object
    {
        $resposta = $asaas->cobrancasDaReferencia((string) $recarga->external_reference);
        if (!$resposta->ok()) {
            return static::recarregar($recarga);
        }
        $achadas = array_values(array_filter(
            is_array($resposta->dados['data'] ?? null) ? $resposta->dados['data'] : [],
            fn ($cobranca) => is_array($cobranca) && ($cobranca['externalReference'] ?? null) === (string) $recarga->external_reference
        ));
        if (!is_array($resposta->dados['data'] ?? null)) {
            // resposta sem a lista: não dá para dizer que nada nasceu
            return static::recarregar($recarga);
        }
        if (count($achadas) === 0) {
            // a busca do Asaas pode demorar a mostrar a cobrança: só falha depois de MINUTOS_SEM_COBRANCA procurando
            if ((string) $recarga->created_at > now()->subMinutes(PrePago::MINUTOS_SEM_COBRANCA)->format('Y-m-d H:i:s')) {
                return static::recarregar($recarga);
            }
            static::falhar($recarga, static::MOTIVO_SEM_PIX);

            return static::recarregar($recarga);
        }
        if (count($achadas) > 1) {
            Log::critical('[entregas] recarga: mais de uma cobrança no Asaas com o externalReference da recarga; conferir à mão', ['recarga' => $recarga->uuid]);

            return static::recarregar($recarga);
        }
        $id = $achadas[0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return static::recarregar($recarga);
        }
        static::adotar($recarga, $id, is_string($achadas[0]['customer'] ?? null) ? $achadas[0]['customer'] : null, $achadas[0]['status'] ?? null);
        $adotada = static::recarregar($recarga);
        if ($adotada->situacao === PrePago::AGUARDANDO && $adotada->payment_id === $id) {
            Log::info('[entregas] recarga: cobrança achada no Asaas pelo externalReference', ['recarga' => $recarga->uuid]);
            $status = strtoupper((string) ($achadas[0]['status'] ?? ''));
            if (in_array($status, PrePago::PAGOS_NO_ASAAS, true)) {
                if (static::confere($adotada, $achadas[0])) {
                    static::creditar($adotada, $status);
                }
            } else {
                // ninguém viu este QR: apaga a cobrança para ela não ficar pagável
                static::expirar($asaas, $adotada, static::MOTIVO_SEM_PIX, null);
            }
        }

        return static::recarregar($recarga);
    }

    /**
     * A cobrança do Asaas é a desta recarga: mesmo id, mesmo valor, mesmo externalReference, Pix e (quando os dois existem)
     * o mesmo cliente. Senão, Log::critical e false: nada é creditado (a recarga fica como está até alguém conferir).
     */
    public static function confere(object $recarga, array $dados): bool
    {
        $valor      = $dados['value'] ?? null;
        $cliente    = $dados['customer'] ?? null;
        $idBate     = ($dados['id'] ?? null) === (string) $recarga->payment_id;
        $valorBate  = is_numeric($valor) && PrePago::centavos($valor) === PrePago::centavos($recarga->valor);
        $refBate    = ($dados['externalReference'] ?? null) === (string) $recarga->external_reference;
        $pixBate    = ($dados['billingType'] ?? null) === 'PIX';
        $clienteBate = $cliente === null || $recarga->cliente_id === null || (string) $cliente === (string) $recarga->cliente_id;
        if ($idBate && $valorBate && $refBate && $pixBate && $clienteBate) {
            return true;
        }
        Log::critical('[entregas] recarga: cobrança do Asaas não confere com a recarga', [
            'recarga'           => $recarga->uuid,
            'id'                => $idBate ? 'confere' : 'diferente',
            'valor'             => $valorBate ? 'confere' : 'diferente',
            'externalReference' => $refBate ? 'confere' : 'diferente',
            'billingType'       => $pixBate ? 'confere' : 'diferente',
            'cliente'           => $clienteBate ? 'confere' : 'diferente',
        ]);

        return false;
    }

    /**
     * Credita a recarga paga: com a recarga travada e ainda não paga, um recebimento Pix na conta da loja e a recarga
     * "paga", na mesma transação. Duas conferências ao mesmo tempo (portal e rotina) creditam uma vez só. true se creditou.
     */
    private static function creditar(object $recarga, string $status): bool
    {
        $creditou = DB::transaction(function () use ($recarga, $status) {
            $linha = DB::table(PrePago::RECARGAS)->where('id', $recarga->id)->lockForUpdate()->first();
            if (!$linha || !in_array($linha->situacao, [PrePago::CRIANDO, PrePago::AGUARDANDO, PrePago::EXPIRADA], true)) {
                return false;
            }
            $empresa   = (string) $linha->company_uuid;
            $pagamento = Pagamentos::registrar($empresa, Financeiro::CONTA_LOJA, (string) $linha->loja_uuid, PrePago::reais(PrePago::centavos($linha->valor)), 'pix', Financeiro::hoje($empresa), null, PrePago::OBSERVACAO_DO_RECEBIMENTO, null);
            $agora     = now()->format('Y-m-d H:i:s');
            DB::table(PrePago::RECARGAS)->where('id', $linha->id)->update([
                'situacao'       => PrePago::PAGA,
                'paga_em'        => $agora,
                'pagamento_uuid' => (string) $pagamento->uuid,
                'asaas_status'   => $status,
                'motivo_falha'   => null,
                'pix_copia_cola' => null,
                'pix_imagem'     => null,
                'updated_at'     => $agora,
            ]);

            return true;
        });
        if ($creditou) {
            Log::info('[entregas] recarga: paga e creditada', ['empresa' => $recarga->company_uuid, 'loja' => $recarga->loja_uuid, 'recarga' => $recarga->uuid, 'valor' => round((float) $recarga->valor, 2)]);
        }

        return $creditou;
    }

    /** Apaga a cobrança no Asaas e expira a recarga. false se o Asaas não apagou (talvez já paga: a próxima consulta vê). */
    private static function expirar(AsaasCliente $asaas, object $recarga, string $motivo, ?string $usuarioUuid): bool
    {
        $resposta = $asaas->apagarCobranca((string) $recarga->payment_id);
        if (!$resposta->ok() && $resposta->status !== 404) {
            Log::warning('[entregas] recarga: o Asaas não apagou a cobrança', ['recarga' => $recarga->uuid, 'status' => $resposta->status, 'motivo' => $resposta->mensagem('sem motivo')]);

            return false;
        }

        return static::marcarExpirada($recarga, $motivo, $usuarioUuid);
    }

    private static function marcarExpirada(object $recarga, string $motivo, ?string $usuarioUuid): bool
    {
        return DB::table(PrePago::RECARGAS)->where('id', $recarga->id)->where('situacao', PrePago::AGUARDANDO)->update([
            'situacao'       => PrePago::EXPIRADA,
            'motivo_falha'   => $motivo,
            'cancelada_por'  => $usuarioUuid,
            'pix_copia_cola' => null,
            'pix_imagem'     => null,
            'updated_at'     => now()->format('Y-m-d H:i:s'),
        ]) > 0;
    }

    private static function falhar(object $recarga, string $motivo): void
    {
        DB::table(PrePago::RECARGAS)->where('id', $recarga->id)->where('situacao', PrePago::CRIANDO)->update([
            'situacao'     => PrePago::FALHOU,
            'motivo_falha' => $motivo,
            'updated_at'   => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Grava a consulta de agora. Com $respeitarIntervalo, só se ninguém consultou nos últimos SEGUNDOS_ENTRE_CONSULTAS
     * (num update condicional: das requisições do portal em paralelo, só uma consulta o Asaas). true se pode consultar.
     */
    private static function reivindicarConsulta(object $recarga, bool $respeitarIntervalo): bool
    {
        $consulta = DB::table(PrePago::RECARGAS)->where('id', $recarga->id);
        if ($respeitarIntervalo) {
            $limite = now()->subSeconds(PrePago::SEGUNDOS_ENTRE_CONSULTAS)->format('Y-m-d H:i:s');
            $consulta->where(fn ($q) => $q->whereNull('consultada_em')->orWhere('consultada_em', '<=', $limite));
        }

        return $consulta->update(['consultada_em' => now()->format('Y-m-d H:i:s')]) > 0 || !$respeitarIntervalo;
    }

    /** O QR code da recarga já passou de MINUTOS_DO_PIX. */
    private static function venceu(object $recarga): bool
    {
        return $recarga->expira_em !== null && (string) $recarga->expira_em <= now()->format('Y-m-d H:i:s');
    }

    /**
     * O cliente do Asaas com a chave da central, só se a conexão ainda é a mesma conta (wallet) da recarga; senão null e
     * log (sem a conta certa, ninguém confere esta cobrança: alguém precisa olhar no Asaas).
     */
    private static function asaasDaRecarga(object $recarga): ?AsaasCliente
    {
        $empresa = (string) $recarga->company_uuid;
        $conexao = ConexaoAsaas::daEmpresa($empresa);
        if (!$conexao || (string) $conexao->wallet_id !== (string) $recarga->wallet_id) {
            Log::critical('[entregas] recarga: a conta Asaas da recarga não está mais conectada; conferir à mão', ['recarga' => $recarga->uuid]);

            return null;
        }
        $chave = Cofre::decifrar((string) $conexao->chave_cifrada, $empresa);
        if ($chave === null) {
            Log::warning('[entregas] recarga: sem a chave do Asaas para conferir', ['recarga' => $recarga->uuid]);

            return null;
        }

        return new AsaasCliente($chave);
    }

    /** O Asaas recusou com certeza (status de recusa e `errors` no corpo): a cobrança não nasceu. */
    private static function recusaClara(RespostaAsaas $resposta): bool
    {
        $erros = $resposta->dados['errors'] ?? null;

        return in_array($resposta->status, static::RECUSAS_DO_ASAAS, true) && is_array($erros) && $erros !== [];
    }

    public static function recarregar(object $recarga): object
    {
        return DB::table(PrePago::RECARGAS)->where('id', $recarga->id)->first() ?? $recarga;
    }

    /** 50, 50.5, "50", "50,50", "1500.00" → centavos; outra coisa, zero, negativo ou não finito → null. */
    public static function valorEmCentavos($valor): ?int
    {
        if (is_int($valor) || is_float($valor)) {
            return is_finite((float) $valor) && $valor > 0 ? PrePago::centavos($valor) : null;
        }
        if (is_string($valor) && preg_match('/^\d{1,5}([.,]\d{1,2})?\z/', trim($valor))) {
            $centavos = PrePago::centavos(str_replace(',', '.', trim($valor)));

            return $centavos > 0 ? $centavos : null;
        }

        return null;
    }

    /**
     * A recarga no formato do portal: {id, valor, situacao, motivo, criada_em, expira_em, paga_em, pix}. O `pix`
     * ({copia_e_cola, imagem}) só na aguardando e com $comPix.
     */
    public static function publico(object $recarga, bool $comPix = true): array
    {
        $empresa = (string) $recarga->company_uuid;

        return [
            'id'        => (string) $recarga->uuid,
            'valor'     => round((float) $recarga->valor, 2),
            'situacao'  => (string) $recarga->situacao,
            'motivo'    => $recarga->motivo_falha,
            'criada_em' => Saque::iso($recarga->created_at, $empresa),
            'expira_em' => Saque::iso($recarga->expira_em, $empresa),
            'paga_em'   => Saque::iso($recarga->paga_em, $empresa),
            'pix'       => $comPix && $recarga->situacao === PrePago::AGUARDANDO && $recarga->pix_copia_cola
                ? ['copia_e_cola' => (string) $recarga->pix_copia_cola, 'imagem' => $recarga->pix_imagem]
                : null,
        ];
    }
}
