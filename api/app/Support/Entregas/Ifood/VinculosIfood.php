<?php

namespace App\Support\Entregas\Ifood;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
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
 *    (GET /merchant/v1.0/merchants). Os tokens da troca ficam no cache (cifrados, 10 min) até o fim do vínculo: se a
 *    lista falhar, repetir o vínculo usa esses tokens, sem pedir ao dono que autorize de novo. Uma só loja é vinculada
 *    direto; várias ficam no cache (cifradas, 10 min) até a central escolher (escolher()). Um merchantId nunca fica em
 *    duas Lojas (checagem e, na corrida, a chave única da tabela).
 *
 * Tokens cifrados com encrypt() (APP_KEY). Renovação: tokenValido() renova o que vence em menos de 5 min; o
 * entregas:ifood-tokens renova o que vence em menos de 1 h (renovarVencendo); comToken() renova no 401 e repete uma
 * vez (se outro processo já renovou, usa o token dele). A renovação roda com trava por loja, relê o vínculo e só grava
 * se ninguém mexeu nele no meio (compare-and-set): dois processos não gastam o mesmo refresh token, e uma loja
 * desvinculada durante a chamada não volta a ter tokens.
 *
 * Vínculo perdido (`vinculo_perdido`, log "[entregas] ifood: vínculo perdido", fora do polling): refresh recusado pelo
 * /oauth/token (400/401), sem refresh com o token já vencido ou recusado, ou merchant revogado no polling (403). O access
 * token é apagado e o refresh fica cifrado, para dar para reativar depois de corrigir a configuração. Os outros erros
 * da renovação (403, 404, 408, 409, 429, 5xx, rede, 200 sem accessToken, trava ocupada) sobem como ErroIfood e a loja
 * continua vinculada. Token ilegível no banco (APP_KEY trocado ou texto corrompido) também tira a loja do polling, com
 * o log "[entregas] ifood: token ilegível (APP_KEY mudou?)" e o texto cifrado mantido. Os logs levam só ids (loja =
 * uuid do Vendor, merchant), nunca token.
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

    protected const MENSAGEM_OUTRA_LOJA = 'Esta loja do iFood já está vinculada a outra loja. Desvincule a outra antes.';

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

    /** Tokens já trocados pelo código de autorização, à espera da lista de lojas (cifrados, 10 min). */
    public static function chaveDosTokens(string $vendorUuid): string
    {
        return "entregas:ifood-tokens-pendentes:{$vendorUuid}";
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
        Cache::forget(static::chaveDosTokens($vendorUuid));

        return [
            'codigo'             => $codigo,
            'link'               => (string) ($resposta['verificationUrlComplete'] ?? $resposta['verificationUrl'] ?? ''),
            'expira_em_segundos' => $segundos,
        ];
    }

    /**
     * Passo 2: troca o código de autorização pelos tokens. Devolve ['situacao' => 'vinculada'] ou, com várias lojas na
     * conta, ['situacao' => 'escolher', 'lojas' => [['id', 'nome'], ...]].
     *
     * O código de autorização vale uma vez só. Por isso os tokens da troca vão para o cache (cifrados) antes de listar as
     * lojas: se a lista falhar (iFood fora, rede), o erro sobe e repetir o vínculo usa os tokens guardados, sem trocar o
     * código de novo (o código repetido é ignorado). Um código de vínculo novo (iniciar) ou desvincular descarta os
     * tokens guardados; a lista recusada (401, 403...) também, e aí é preciso um código novo.
     */
    public function concluir(string $companyUuid, string $vendorUuid, #[\SensitiveParameter] string $codigoDeAutorizacao): array
    {
        $tokens = $this->abrirDoCache(static::chaveDosTokens($vendorUuid));
        if ($tokens === null) {
            $verificador = $this->abrirDoCache(static::chaveDoVerificador($vendorUuid));
            if ($verificador === null) {
                throw new ErroDeVinculo('O código de vínculo venceu (vale 10 minutos). Gere um código novo.');
            }

            $tokens = $this->cliente->trocarCodigo(trim($codigoDeAutorizacao), $verificador);
            Cache::put(static::chaveDosTokens($vendorUuid), encrypt($tokens), static::VALIDADE_DO_CODIGO_SEGUNDOS);
            // o código de autorização vale uma vez só: daqui em diante valem os tokens
            Cache::forget(static::chaveDoVerificador($vendorUuid));
        }

        try {
            $lojas = $this->lojasIfood($tokens['accessToken']);
        } catch (ErroIfood $e) {
            if (!$e->temporario()) {
                Cache::forget(static::chaveDosTokens($vendorUuid));
            }

            throw $e;
        }
        if (!$lojas) {
            throw new ErroDeVinculo('A conta do iFood que autorizou não tem lojas para este aplicativo.');
        }
        if (count($lojas) === 1) {
            $this->gravar($companyUuid, $vendorUuid, $lojas[0]['id'], $lojas[0]['nome'], $tokens);
            Cache::forget(static::chaveDosTokens($vendorUuid));

            return ['situacao' => static::VINCULADA];
        }

        Cache::put(static::chaveDaEscolha($vendorUuid), encrypt(['tokens' => $tokens, 'lojas' => $lojas]), static::VALIDADE_DO_CODIGO_SEGUNDOS);
        Cache::forget(static::chaveDosTokens($vendorUuid));

        return ['situacao' => 'escolher', 'lojas' => $lojas];
    }

    /** Passo 2b: a central escolheu a loja do iFood entre as da conta autorizada. */
    public function escolher(string $companyUuid, string $vendorUuid, string $merchantId): array
    {
        $pendente = $this->abrirDoCache(static::chaveDaEscolha($vendorUuid));
        if ($pendente === null) {
            throw new ErroDeVinculo('A escolha da loja do iFood venceu (10 minutos). Comece o vínculo de novo.');
        }

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
        Cache::forget(static::chaveDosTokens($vendorUuid));
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
        if (!$vinculo->access_token || static::segundosAteVencer($vinculo) < static::RENOVAR_ANTES_SEGUNDOS) {
            return $this->renovar($vinculo);
        }

        return $this->abrirDoBanco($vinculo, 'access_token');
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

            // com o token recusado: se outro processo já renovou, a renovação devolve o token dele sem gastar o refresh
            return $chamada($this->renovar($vinculo, $token));
        }
    }

    /**
     * Renova pelo refresh token e devolve o token novo. Com trava por loja: se outro processo renovou enquanto este
     * esperava, usa o token dele (com $tokenRecusado, o do 401: renova só se o token do banco ainda é o recusado). Refresh
     * recusado (400/401) = vínculo perdido (VinculoPerdido); os outros erros, a rede e a trava ocupada sobem como
     * ErroIfood e a loja continua vinculada.
     */
    public function renovar(object $vinculo, #[\SensitiveParameter] ?string $tokenRecusado = null): string
    {
        try {
            return Cache::lock('entregas:ifood-token:' . $vinculo->id, 30)->block(20, fn () => $this->renovarComTrava($vinculo, $tokenRecusado));
        } catch (LockTimeoutException) {
            // outro processo ficou com a trava os 20 s: tenta de novo na próxima rodada
            throw new ErroIfood('refresh', 0, 'trava da renovação ocupada');
        }
    }

    /** Renova os tokens que vencem em menos de RENOVACAO_PROATIVA_SEGUNDOS (entregas:ifood-tokens). */
    public function renovarVencendo(): array
    {
        $limite    = now()->addSeconds(static::RENOVACAO_PROATIVA_SEGUNDOS)->toDateTimeString();
        $resultado = ['renovados' => 0, 'perdidos' => 0, 'falhas' => 0];
        $vencendo  = DB::table(static::TABELA)->where('situacao', static::VINCULADA)->where('expira_em', '<', $limite)->orderBy('id')->get()->all();

        foreach ($vencendo as $vinculo) {
            // sem refresh não há o que renovar: o token vale até vencer (aí a loja cai, aqui ou no tokenValido)
            if (!$vinculo->refresh_token && static::segundosAteVencer($vinculo) > 0) {
                continue;
            }

            // uma loja com problema não para as outras
            try {
                $this->renovar($vinculo);
                $resultado['renovados']++;
            } catch (VinculoPerdido) {
                $resultado['perdidos']++;
            } catch (ErroIfood $e) {
                $resultado['falhas']++;
                Log::warning('[entregas] ifood: renovação do token falhou', ['loja' => $vinculo->vendor_uuid, 'merchant' => $vinculo->merchant_id, 'status' => $e->status]);
            } catch (\Throwable $e) {
                $resultado['falhas']++;
                Log::warning('[entregas] ifood: renovação do token falhou', ['loja' => $vinculo->vendor_uuid, 'merchant' => $vinculo->merchant_id] + static::descreverErro($e));
            }
        }

        return $resultado;
    }

    /** Marca o vínculo como perdido: fora do polling, sem o access token; o refresh fica (cifrado) para reativar. */
    public function perder(object $vinculo, int $status): void
    {
        DB::table(static::TABELA)->where('id', $vinculo->id)->update([
            'situacao'     => static::PERDIDO,
            'access_token' => null,
            'expira_em'    => null,
            'updated_at'   => now()->toDateTimeString(),
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
    public static function refreshDaResposta(#[\SensitiveParameter] array $tokens): ?string
    {
        foreach (['refreshToken', 'refresh_token'] as $campo) {
            if (is_string($tokens[$campo] ?? null) && $tokens[$campo] !== '') {
                return $tokens[$campo];
            }
        }

        return null;
    }

    protected static function expiraEm(#[\SensitiveParameter] array $tokens): string
    {
        $segundos = (int) ($tokens['expiresIn'] ?? 0);

        return now()->addSeconds($segundos > 0 ? $segundos : static::VALIDADE_PADRAO_DO_TOKEN)->toDateTimeString();
    }

    /** Segundos até o access token vencer (negativo = vencido; sem validade gravada = vencido). */
    protected static function segundosAteVencer(object $vinculo): int
    {
        $expira = $vinculo->expira_em ? Carbon::parse($vinculo->expira_em, 'UTC')->getTimestamp() : 0;

        return $expira - now()->getTimestamp();
    }

    /** A parte da renovação que roda dentro da trava da loja. */
    protected function renovarComTrava(object $vinculo, #[\SensitiveParameter] ?string $tokenRecusado): string
    {
        $atual = $this->recarregar($vinculo);
        if ($atual->situacao !== static::VINCULADA) {
            throw new VinculoPerdido('a loja não está mais vinculada');
        }

        if ($tokenRecusado !== null) {
            // 401: se o token do banco já não é o recusado, outro processo renovou enquanto a chamada ia ao iFood
            $tokenAtual = $atual->access_token ? $this->abrirDoBanco($atual, 'access_token') : null;
            if ($tokenAtual !== null && !hash_equals($tokenAtual, $tokenRecusado)) {
                return $tokenAtual;
            }
        } elseif ($atual->access_token && $atual->renovado_em !== $vinculo->renovado_em) {
            // outro processo renovou depois que este leu o vínculo
            return $this->abrirDoBanco($atual, 'access_token');
        }

        $refresh = $atual->refresh_token ? $this->abrirDoBanco($atual, 'refresh_token') : null;
        if (!$refresh) {
            // sem refresh: o token atual vale até vencer, a não ser que o iFood já o tenha recusado
            if ($tokenRecusado === null && $atual->access_token && static::segundosAteVencer($atual) > 0) {
                return $this->abrirDoBanco($atual, 'access_token');
            }
            $this->perder($atual, 0);

            throw new VinculoPerdido('sem refresh token');
        }

        try {
            $tokens = $this->cliente->renovar($refresh);
        } catch (ErroIfood $e) {
            if (!$e->refreshRecusado()) {
                throw $e;
            }
            $this->perder($atual, $e->status);

            throw new VinculoPerdido('refresh recusado: ' . $e->status);
        }

        // compare-and-set: só grava se o vínculo continua como foi relido (vinculado e com o mesmo refresh). Se outro
        // processo renovou (trava vencida), desvinculou ou perdeu a loja no meio da chamada, vale o que ele gravou.
        $agora    = now()->toDateTimeString();
        $gravadas = DB::table(static::TABELA)
            ->where('id', $atual->id)
            ->where('situacao', static::VINCULADA)
            ->where('refresh_token', $atual->refresh_token)
            ->update([
                'access_token'  => encrypt($tokens['accessToken']),
                'refresh_token' => encrypt(static::refreshDaResposta($tokens) ?? $refresh),
                'expira_em'     => static::expiraEm($tokens),
                'renovado_em'   => $agora,
                'updated_at'    => $agora,
            ]);
        if ($gravadas > 0) {
            return $tokens['accessToken'];
        }

        $depois = DB::table(static::TABELA)->where('id', $atual->id)->first();
        if (!$depois || $depois->situacao !== static::VINCULADA || !$depois->access_token) {
            throw new VinculoPerdido('o vínculo mudou durante a renovação');
        }
        Log::info('[entregas] ifood: vínculo mudou durante a renovação; vale o token gravado', ['loja' => $atual->vendor_uuid, 'merchant' => $atual->merchant_id]);

        return $this->abrirDoBanco($depois, 'access_token');
    }

    /**
     * Decifra um token do banco. Ilegível (APP_KEY trocado ou texto corrompido): a loja sai do polling como vínculo
     * perdido, mantendo o texto cifrado (volta a valer se a chave antiga voltar), e sobe VinculoPerdido.
     */
    protected function abrirDoBanco(object $vinculo, string $coluna): string
    {
        try {
            return (string) decrypt($vinculo->$coluna);
        } catch (DecryptException) {
            DB::table(static::TABELA)->where('id', $vinculo->id)->where('situacao', static::VINCULADA)->update([
                'situacao'   => static::PERDIDO,
                'updated_at' => now()->toDateTimeString(),
            ]);
            Log::warning('[entregas] ifood: token ilegível (APP_KEY mudou?)', ['loja' => $vinculo->vendor_uuid, 'merchant' => $vinculo->merchant_id, 'campo' => $coluna]);

            throw new VinculoPerdido("{$coluna} ilegível");
        }
    }

    /** Decifra um valor do cache (verificador, tokens, escolha); null se não há. Ilegível: sai do cache e pede um código novo. */
    protected function abrirDoCache(string $chave): mixed
    {
        $cifrado = Cache::get($chave);
        if (!$cifrado) {
            return null;
        }

        try {
            return decrypt($cifrado);
        } catch (DecryptException) {
            Cache::forget($chave);

            throw new ErroDeVinculo('Não deu para ler o vínculo em andamento (a chave do servidor mudou). Gere um código novo.');
        }
    }

    /** Lojas da conta autorizada: [['id', 'nome'], ...]. */
    protected function lojasIfood(#[\SensitiveParameter] string $token): array
    {
        $lojas = [];
        foreach ($this->cliente->lojasDoToken($token) as $loja) {
            if (is_string($loja['id'] ?? null) && $loja['id'] !== '') {
                $lojas[] = ['id' => $loja['id'], 'nome' => (string) ($loja['name'] ?? $loja['corporateName'] ?? $loja['id'])];
            }
        }

        return $lojas;
    }

    protected function gravar(string $companyUuid, string $vendorUuid, string $merchantId, string $nome, #[\SensitiveParameter] array $tokens): void
    {
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

        // duas voltas no máximo: a chave única (merchant_id ou vendor_uuid) pega a corrida entre a checagem e a escrita
        // (outra loja gravou o mesmo merchant, ou outra aba criou a linha desta loja); a segunda volta decide qual foi
        for ($volta = 1; ; $volta++) {
            if (DB::table(static::TABELA)->where('merchant_id', $merchantId)->where('vendor_uuid', '!=', $vendorUuid)->exists()) {
                throw new ErroDeVinculo(static::MENSAGEM_OUTRA_LOJA);
            }

            try {
                if (DB::table(static::TABELA)->where('vendor_uuid', $vendorUuid)->exists()) {
                    DB::table(static::TABELA)->where('vendor_uuid', $vendorUuid)->update($dados);
                } else {
                    DB::table(static::TABELA)->insert($dados + ['vendor_uuid' => $vendorUuid, 'created_at' => $agora]);
                }
                break;
            } catch (QueryException $e) {
                if (!static::chaveRepetida($e)) {
                    throw $e;
                }
                if ($volta >= 2) {
                    throw new ErroDeVinculo(static::MENSAGEM_OUTRA_LOJA);
                }
            }
        }

        Log::info('[entregas] ifood: loja vinculada', ['loja' => $vendorUuid, 'merchant' => $merchantId]);
    }

    /** Violação de chave única (SQLSTATE 23000). */
    protected static function chaveRepetida(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000' || (string) $e->getCode() === '23000';
    }

    /** Classe e mensagem do erro para o log; a do QueryException fica de fora (traz o SQL com os valores). */
    protected static function descreverErro(\Throwable $e): array
    {
        return $e instanceof QueryException
            ? ['erro' => get_class($e)]
            : ['erro' => get_class($e), 'mensagem' => mb_substr($e->getMessage(), 0, 200)];
    }

    protected function recarregar(object $vinculo): object
    {
        return DB::table(static::TABELA)->where('id', $vinculo->id)->first() ?? $vinculo;
    }
}
