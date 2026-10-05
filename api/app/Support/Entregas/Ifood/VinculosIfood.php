<?php

namespace App\Support\Entregas\Ifood;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: vínculo das Lojas (Vendor) com as lojas do iFood (merchantId), tabela entregas_ifood_lojas.
 *
 * Fluxo distribuído (docs/ifood/referencia-logistics.md, seção 1.1), pela tela Lojas:
 * 1. iniciar(): pede o userCode ao iFood e guarda o authorizationCodeVerifier no cache (cifrado), por loja, pelo
 *    tempo do código (10 min). A central mostra o código e o link ao dono da loja.
 * 2. concluir(): troca o código de autorização pelos tokens (o código vale uma vez só) e lista as lojas da conta
 *    (GET /merchant/v1.0/merchants). Uma só é vinculada direto; várias ficam no cache (cifradas, 10 min) até a central
 *    escolher (escolher()). Um merchantId nunca fica em duas Lojas.
 *
 * Tokens cifrados com encrypt() (APP_KEY). Renovação: tokenValido() renova o que vence em menos de 5 min; o
 * entregas:ifood-tokens renova o que vence em menos de 1 h (renovarVencendo); comToken() renova no 401 e repete uma
 * vez. A renovação roda com trava por loja e relê o vínculo: dois processos não gastam o mesmo refresh token. Refresh
 * vencido ou revogado (400/401/403) = `vinculo_perdido`, log "[entregas] ifood: vínculo perdido" e a loja sai do
 * polling. Os logs levam só ids (loja = uuid do Vendor, merchant), nunca token.
 *
 * A confirmar no primeiro vínculo real: o nome do campo do refresh token na resposta (a documentação não lista;
 * tratamos `refreshToken` e, de reserva, `refresh_token`) e se o iFood devolve um refresh novo a cada renovação (se não
 * devolver, o atual continua).
 */
class VinculosIfood
{
    public const TABELA = 'entregas_ifood_lojas';

    public const VINCULADA    = 'vinculada';
    public const PERDIDO      = 'vinculo_perdido';
    public const DESVINCULADA = 'desvinculada';

    /** tokenValido() renova o token que vence em menos disto (segundos). */
    public const RENOVAR_ANTES_SEGUNDOS = 300;

    /** entregas:ifood-tokens renova os tokens que vencem em menos disto (segundos). */
    public const RENOVACAO_PROATIVA_SEGUNDOS = 3600;

    /** Validade do código de vínculo e da escolha da loja, se o iFood não disser (segundos). */
    public const VALIDADE_DO_CODIGO_SEGUNDOS = 600;

    /** Validade do token, se a resposta não trouxer expiresIn (6 h, documentação). */
    public const VALIDADE_PADRAO_DO_TOKEN = 21600;

    public function __construct(protected ClienteIfood $cliente)
    {
    }

    public static function chaveDoVerificador(string $vendorUuid): string
    {
        return "entregas:ifood-vinculo:{$vendorUuid}";
    }

    public static function chaveDaEscolha(string $vendorUuid): string
    {
        return "entregas:ifood-escolha:{$vendorUuid}";
    }

    /** Passo 1: o código de vínculo para o dono da loja autorizar no Portal do Parceiro. */
    public function iniciar(string $vendorUuid): array
    {
        $resposta    = $this->cliente->pedirCodigoDeVinculo();
        $codigo      = (string) ($resposta['userCode'] ?? '');
        $verificador = (string) ($resposta['authorizationCodeVerifier'] ?? '');
        if ($codigo === '' || $verificador === '') {
            throw new ErroIfood('userCode', 200, 'resposta sem userCode');
        }

        $segundos = (int) ($resposta['expiresIn'] ?? 0);
        $segundos = $segundos > 0 ? $segundos : static::VALIDADE_DO_CODIGO_SEGUNDOS;
        Cache::put(static::chaveDoVerificador($vendorUuid), encrypt($verificador), $segundos);
        Cache::forget(static::chaveDaEscolha($vendorUuid));

        return [
            'codigo'             => $codigo,
            'link'               => (string) ($resposta['verificationUrlComplete'] ?? $resposta['verificationUrl'] ?? ''),
            'expira_em_segundos' => $segundos,
        ];
    }

    /**
     * Passo 2: troca o código de autorização pelos tokens. Devolve ['situacao' => 'vinculada'] ou, com várias lojas na
     * conta, ['situacao' => 'escolher', 'lojas' => [['id', 'nome'], ...]].
     */
    public function concluir(string $companyUuid, string $vendorUuid, string $codigoDeAutorizacao): array
    {
        $verificador = Cache::get(static::chaveDoVerificador($vendorUuid));
        if (!$verificador) {
            throw new ErroDeVinculo('O código de vínculo venceu (vale 10 minutos). Gere um código novo.');
        }

        $tokens = $this->cliente->trocarCodigo(trim($codigoDeAutorizacao), decrypt($verificador));
        // o código de autorização vale uma vez só: daqui em diante valem os tokens
        Cache::forget(static::chaveDoVerificador($vendorUuid));

        $lojas = $this->lojasIfood($tokens['accessToken']);
        if (!$lojas) {
            throw new ErroDeVinculo('A conta do iFood que autorizou não tem lojas para este aplicativo.');
        }
        if (count($lojas) === 1) {
            $this->gravar($companyUuid, $vendorUuid, $lojas[0]['id'], $lojas[0]['nome'], $tokens);

            return ['situacao' => static::VINCULADA];
        }

        Cache::put(static::chaveDaEscolha($vendorUuid), encrypt(['tokens' => $tokens, 'lojas' => $lojas]), static::VALIDADE_DO_CODIGO_SEGUNDOS);

        return ['situacao' => 'escolher', 'lojas' => $lojas];
    }

    /** Passo 2b: a central escolheu a loja do iFood entre as da conta autorizada. */
    public function escolher(string $companyUuid, string $vendorUuid, string $merchantId): array
    {
        $cifrado = Cache::get(static::chaveDaEscolha($vendorUuid));
        if (!$cifrado) {
            throw new ErroDeVinculo('A escolha da loja do iFood venceu (10 minutos). Comece o vínculo de novo.');
        }

        $pendente = decrypt($cifrado);
        foreach ($pendente['lojas'] as $loja) {
            if ($loja['id'] === $merchantId) {
                $this->gravar($companyUuid, $vendorUuid, $loja['id'], $loja['nome'], $pendente['tokens']);
                Cache::forget(static::chaveDaEscolha($vendorUuid));

                return ['situacao' => static::VINCULADA];
            }
        }

        throw new ErroDeVinculo('Esta loja não está entre as lojas da conta do iFood que autorizou.');
    }

    /** Apaga os tokens e tira a loja do polling. O merchant fica livre para outra loja. */
    public function desvincular(string $vendorUuid): void
    {
        DB::table(static::TABELA)->where('vendor_uuid', $vendorUuid)->update([
            'situacao'      => static::DESVINCULADA,
            'merchant_id'   => null,
            'nome_ifood'    => null,
            'access_token'  => null,
            'refresh_token' => null,
            'expira_em'     => null,
            'updated_at'    => now()->toDateTimeString(),
        ]);
        Cache::forget(static::chaveDoVerificador($vendorUuid));
        Cache::forget(static::chaveDaEscolha($vendorUuid));
        Log::info('[entregas] ifood: loja desvinculada', ['loja' => $vendorUuid]);
    }

    /** As lojas do polling: vinculadas e com merchant. */
    public function vinculadas(): array
    {
        return DB::table(static::TABELA)->where('situacao', static::VINCULADA)->whereNotNull('merchant_id')->orderBy('id')->get()->all();
    }

    public function porMerchant(string $merchantId): ?object
    {
        return DB::table(static::TABELA)->where('merchant_id', $merchantId)->where('situacao', static::VINCULADA)->first();
    }

    /** O token de acesso, renovado antes se vence em menos de RENOVAR_ANTES_SEGUNDOS. */
    public function tokenValido(object $vinculo): string
    {
        $expira = $vinculo->expira_em ? Carbon::parse($vinculo->expira_em, 'UTC')->getTimestamp() : 0;
        if (!$vinculo->access_token || $expira - now()->getTimestamp() < static::RENOVAR_ANTES_SEGUNDOS) {
            return $this->renovar($vinculo);
        }

        return decrypt($vinculo->access_token);
    }

    /** Roda $chamada(token); no 401, renova o token e repete uma vez (a segunda falha sobe). */
    public function comToken(object $vinculo, Closure $chamada): mixed
    {
        $token = $this->tokenValido($vinculo);

        try {
            return $chamada($token);
        } catch (ErroIfood $e) {
            if (!$e->naoAutorizado()) {
                throw $e;
            }
            Log::info('[entregas] ifood: token recusado (401), renovando', ['merchant' => $vinculo->merchant_id]);

            return $chamada($this->renovar($this->recarregar($vinculo)));
        }
    }

    /**
     * Renova pelo refresh token e devolve o token novo. Com trava por loja: se outro processo renovou enquanto este
     * esperava, usa o token dele. Refresh recusado = vínculo perdido (VinculoPerdido); rede, 429 e 5xx sobem como
     * ErroIfood e a loja continua vinculada.
     */
    public function renovar(object $vinculo): string
    {
        return Cache::lock('entregas:ifood-token:' . $vinculo->id, 30)->block(20, function () use ($vinculo) {
            $atual = $this->recarregar($vinculo);
            if ($atual->situacao !== static::VINCULADA) {
                throw new VinculoPerdido('a loja não está mais vinculada');
            }
            if ($atual->access_token && $atual->renovado_em !== $vinculo->renovado_em) {
                return decrypt($atual->access_token);
            }

            $refresh = $atual->refresh_token ? decrypt($atual->refresh_token) : null;
            if (!$refresh) {
                $this->perder($atual, 0);

                throw new VinculoPerdido('sem refresh token');
            }

            try {
                $tokens = $this->cliente->renovar($refresh);
            } catch (ErroIfood $e) {
                if ($e->temporario()) {
                    throw $e;
                }
                $this->perder($atual, $e->status);

                throw new VinculoPerdido('refresh recusado: ' . $e->status);
            }

            $agora = now()->toDateTimeString();
            DB::table(static::TABELA)->where('id', $atual->id)->update([
                'access_token'  => encrypt($tokens['accessToken']),
                'refresh_token' => encrypt(static::refreshDaResposta($tokens) ?? $refresh),
                'expira_em'     => static::expiraEm($tokens),
                'renovado_em'   => $agora,
                'updated_at'    => $agora,
            ]);

            return $tokens['accessToken'];
        });
    }

    /** Renova os tokens que vencem em menos de RENOVACAO_PROATIVA_SEGUNDOS (entregas:ifood-tokens). */
    public function renovarVencendo(): array
    {
        $limite    = now()->addSeconds(static::RENOVACAO_PROATIVA_SEGUNDOS)->toDateTimeString();
        $resultado = ['renovados' => 0, 'perdidos' => 0, 'falhas' => 0];
        $vencendo  = DB::table(static::TABELA)->where('situacao', static::VINCULADA)->where('expira_em', '<', $limite)->orderBy('id')->get()->all();

        foreach ($vencendo as $vinculo) {
            try {
                $this->renovar($vinculo);
                $resultado['renovados']++;
            } catch (VinculoPerdido) {
                $resultado['perdidos']++;
            } catch (ErroIfood $e) {
                $resultado['falhas']++;
                Log::warning('[entregas] ifood: renovação do token falhou', ['merchant' => $vinculo->merchant_id, 'status' => $e->status]);
            }
        }

        return $resultado;
    }

    /** Marca o vínculo como perdido: sem tokens e fora do polling. */
    public function perder(object $vinculo, int $status): void
    {
        DB::table(static::TABELA)->where('id', $vinculo->id)->update([
            'situacao'      => static::PERDIDO,
            'access_token'  => null,
            'refresh_token' => null,
            'expira_em'     => null,
            'updated_at'    => now()->toDateTimeString(),
        ]);
        Log::warning('[entregas] ifood: vínculo perdido', ['loja' => $vinculo->vendor_uuid, 'merchant' => $vinculo->merchant_id, 'status' => $status]);
    }

    /** O iFood recusou o merchant no polling (403, unauthorizedMerchants): o dono revogou a autorização. */
    public function perderPorMerchant(string $merchantId, int $status): void
    {
        $vinculo = $this->porMerchant($merchantId);
        if ($vinculo) {
            $this->perder($vinculo, $status);
        }
    }

    /** Situação de uma loja para a tela Lojas (sem tokens). */
    public static function resumo(string $vendorUuid): array
    {
        return static::resumos([$vendorUuid])[$vendorUuid];
    }

    /** Situação de várias lojas de uma vez: uuid do Vendor => ['situacao', 'nome', 'merchant_id']. */
    public static function resumos(array $vendorUuids): array
    {
        $resumos = array_fill_keys($vendorUuids, ['situacao' => null, 'nome' => null, 'merchant_id' => null]);
        if (!$vendorUuids) {
            return $resumos;
        }

        foreach (DB::table(static::TABELA)->whereIn('vendor_uuid', array_values($vendorUuids))->get()->all() as $vinculo) {
            if ($vinculo->situacao === static::DESVINCULADA) {
                continue;
            }
            $resumos[$vinculo->vendor_uuid] = ['situacao' => $vinculo->situacao, 'nome' => $vinculo->nome_ifood, 'merchant_id' => $vinculo->merchant_id];
        }

        return $resumos;
    }

    /** O refresh token da resposta do /oauth/token: `refreshToken` (a confirmar no primeiro vínculo real) ou `refresh_token`. */
    public static function refreshDaResposta(array $tokens): ?string
    {
        foreach (['refreshToken', 'refresh_token'] as $campo) {
            if (is_string($tokens[$campo] ?? null) && $tokens[$campo] !== '') {
                return $tokens[$campo];
            }
        }

        return null;
    }

    protected static function expiraEm(array $tokens): string
    {
        $segundos = (int) ($tokens['expiresIn'] ?? 0);

        return now()->addSeconds($segundos > 0 ? $segundos : static::VALIDADE_PADRAO_DO_TOKEN)->toDateTimeString();
    }

    /** Lojas da conta autorizada: [['id', 'nome'], ...]. */
    protected function lojasIfood(string $token): array
    {
        $lojas = [];
        foreach ($this->cliente->lojasDoToken($token) as $loja) {
            if (is_string($loja['id'] ?? null) && $loja['id'] !== '') {
                $lojas[] = ['id' => $loja['id'], 'nome' => (string) ($loja['name'] ?? $loja['corporateName'] ?? $loja['id'])];
            }
        }

        return $lojas;
    }

    protected function gravar(string $companyUuid, string $vendorUuid, string $merchantId, string $nome, array $tokens): void
    {
        $emOutraLoja = DB::table(static::TABELA)->where('merchant_id', $merchantId)->where('vendor_uuid', '!=', $vendorUuid)->exists();
        if ($emOutraLoja) {
            throw new ErroDeVinculo('Esta loja do iFood já está vinculada a outra loja. Desvincule a outra antes.');
        }

        $refresh = static::refreshDaResposta($tokens);
        if ($refresh === null) {
            // sem refresh, o vínculo cai quando o token vencer (6 h): conferir o nome do campo no log do primeiro vínculo
            Log::warning('[entregas] ifood: resposta do token sem refresh token', ['loja' => $vendorUuid, 'merchant' => $merchantId, 'campos' => array_keys($tokens)]);
        }

        $agora = now()->toDateTimeString();
        $dados = [
            'company_uuid'  => $companyUuid,
            'merchant_id'   => $merchantId,
            'nome_ifood'    => mb_substr($nome, 0, 190),
            'access_token'  => encrypt($tokens['accessToken']),
            'refresh_token' => $refresh === null ? null : encrypt($refresh),
            'expira_em'     => static::expiraEm($tokens),
            'situacao'      => static::VINCULADA,
            'vinculado_em'  => $agora,
            'renovado_em'   => null,
            'updated_at'    => $agora,
        ];

        if (DB::table(static::TABELA)->where('vendor_uuid', $vendorUuid)->exists()) {
            DB::table(static::TABELA)->where('vendor_uuid', $vendorUuid)->update($dados);
        } else {
            DB::table(static::TABELA)->insert($dados + ['vendor_uuid' => $vendorUuid, 'created_at' => $agora]);
        }

        Log::info('[entregas] ifood: loja vinculada', ['loja' => $vendorUuid, 'merchant' => $merchantId]);
    }

    protected function recarregar(object $vinculo): object
    {
        return DB::table(static::TABELA)->where('id', $vinculo->id)->first() ?? $vinculo;
    }
}
