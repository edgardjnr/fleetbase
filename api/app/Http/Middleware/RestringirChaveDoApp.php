<?php

namespace App\Http\Middleware;

use Closure;
use Fleetbase\Models\ApiCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a chave pública do app do motoboy (Navigator) só serve para o login.
 *
 * Por quê: a chave `flb_live_…` vai dentro do APK e, no Bearer, autentica a API pública `v1/*` como o
 * administrador que a criou (AuthenticateOnceWithBasicAuth do core), sem token de usuário. Quem a extrai do
 * APK lista, cria e cancela pedidos e lê os clientes de todas as lojas. O app só deveria precisar dela até o
 * login: depois, o SDK manda no Bearer o token do motoboy (Sanctum `id|token`), que não é a chave e passa
 * direto. O app não manda token de usuário em outro cabeçalho junto com a chave (o SDK só põe Authorization,
 * Content-Type e User-Agent), então, no APK novo, chave no Bearer = motoboy ainda sem login.
 *
 * Com `services.entregas.chave_app_motoboy` (ENTREGAS_CHAVE_APP_MOTOBOY) vazia, que é o padrão, nada muda.
 * Preenchida, a requisição cujo Bearer autentica como essa chave só passa em PERMITIDAS_COM_A_CHAVE: o login
 * por SMS, a conferência do código e, só por causa do APK atual, o registro do celular para o push e as
 * organizações do motoboy. O resto leva 403 e vai para o log (uma linha por minuto para cada IP e rota).
 * Outros Bearers passam: token de usuário, as chaves e o secret da integração iFood, outras chaves de API. Por
 * isso a integração iFood não pode usar a mesma chave do app. Se o app ganhar outra forma de login, a rota
 * nova entra em PERMITIDAS_COM_A_CHAVE.
 *
 * Pré-requisito para ligar: um APK novo instalado em TODOS os celulares (repo entregas-navigator). O APK atual
 * ainda usa a chave logo depois do login: o `Driver` que o `verifyCode` devolve nasce com o adapter da chave
 * (src/contexts/AuthContext.tsx: `verifyCode`, `createDriverSession`, `RESTORE_SESSION`) e o `useFleetbase`
 * (src/hooks/use-fleetbase.ts) só troca a instância da chave pela do token um render depois. Ligado assim,
 * `GET v1/orders`, `GET v1/chat-channels` e `POST v1/drivers/{id}/track` levam 403: as listas de pedidos ficam
 * vazias depois do login (até recarregar) e o envio de posição falha; os 403 aparecem no log. O APK novo cria o
 * `Driver` com o adapter do `driver.token` e o `useFleetbase` cria a instância de forma síncrona a partir do
 * `authToken`; com isso, `register-device` e `organizations` podem sair de PERMITIDAS_COM_A_CHAVE.
 *
 * Ligar (com o APK novo em todos os celulares e testado num deles: login, receber pedido, aceitar, concluir):
 *  1. ENTREGAS_CHAVE_APP_MOTOBOY = a chave pública do app (a mesma do secret FLEETBASE_KEY do entregas-navigator),
 *     sem aspas, nas Environment variables do stack no Portainer. O deploy/stack.env só existe no PC, e o
 *     deploy/atualizar.sh só faz `service update --force`: não relê o stack.env;
 *  2. Portainer: Stacks → entregas → Editor → Update the stack ("Re-pull image" desligado). O container novo lê a
 *     variável (o config:cache do deploy.sh roda dentro dele);
 *  3. checagem negativa: `curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer <chave>"
 *     https://entregas-api.restaurantepro.com.br/v1/orders` tem de dar 403, e `docker service logs
 *     entregas_application 2>&1 | grep "chave do app"` tem de mostrar o "acesso negado" desse curl e nenhum aviso
 *     de chave suspeita. Sem ela, um erro de digitação (ou aspas vindas do Portainer) pode deixar o middleware
 *     aberto sem ninguém notar: o aviso só pega a chave que não começa com `flb_live_`.
 * Trocou a FLEETBASE_KEY do app? Troque a variável junto: senão a chave nova fica sem restrição e nada avisa.
 * Desligar: apagar o valor da variável e repetir o passo 2.
 */
class RestringirChaveDoApp
{
    /** Começo da chave pública de produção, a que vai dentro do APK. */
    public const PREFIXO_DA_CHAVE_DO_APP = 'flb_live_';

    /** verify-code: a mesma regex libera a rota e confere o `for`, para as duas checagens casarem sempre o mesmo caminho. */
    public const ROTA_VERIFY_CODE = '#^POST v1/drivers/verify-code$#D';

    /**
     * "MÉTODO caminho" (caminho decodificado, sem a barra inicial) que a chave do app pode chamar. O modificador
     * `D` faz o `$` casar só no fim do texto, como no roteador do Laravel: sem ele, um "\n" no fim também passaria.
     */
    public const PERMITIDAS_COM_A_CHAVE = [
        '#^POST v1/drivers/login-with-sms$#D',        // manda o código por SMS (ou por e-mail, se o SMS falhar)
        self::ROTA_VERIFY_CODE,                       // confere o código e devolve o token do motoboy
        '#^POST v1/drivers/[^/]+/register-device$#D', // push: o APK atual registra o celular com a chave (sai com o APK novo)
        '#^GET v1/drivers/[^/]+/organizations$#D',    // o APK atual carrega as organizações com a chave (sai com o APK novo)
    ];

    /** Único `for` aceito no verify-code com a chave: o login (`create_driver` cria motoboy). */
    public const VERIFICACAO_DE_LOGIN = 'driver_login';

    /** Já avisou de chave suspeita neste worker? O Octane mantém o processo vivo: o aviso sai uma vez por worker. */
    protected static bool $avisouChaveSuspeita = false;

    public function handle(Request $request, Closure $next)
    {
        $chave = trim((string) config('services.entregas.chave_app_motoboy'));
        if ($chave === '') {
            return $next($request);
        }

        $this->avisarSeChaveSuspeita($chave);

        $bearer = $request->bearerToken();
        if (!is_string($bearer) || $bearer === '' || !$this->ehAChaveDoApp($bearer, $chave)) {
            return $next($request);
        }

        // caminho decodificado, como o roteador do Laravel casa as rotas (rawurldecode)
        $caminho = trim(rawurldecode($request->path()), '/');
        // HEAD executa a rota GET (o roteador registra as duas)
        $metodo = $request->isMethod('HEAD') ? 'GET' : $request->method();
        $rota   = $metodo . ' ' . $caminho;

        if (!$this->casa($rota, static::PERMITIDAS_COM_A_CHAVE)) {
            return $this->negar($request, $rota, 'rota fora do login do app');
        }

        // DriverController::verifyCode com `for=create_driver` cria um motoboy: com a chave, só o login.
        // Casa com a mesma regex (com D) que liberou a rota acima, e não com uma comparação de texto à parte.
        if (preg_match(static::ROTA_VERIFY_CODE, $rota) === 1 && !in_array($request->input('for'), [null, static::VERIFICACAO_DE_LOGIN], true)) {
            return $this->negar($request, $rota, 'verify-code fora do login');
        }

        return $next($request);
    }

    /**
     * A variável tem de ser a chave pública `flb_live_…` do app. Um valor com outro começo (erro de digitação,
     * aspas vindas do Portainer, chave de teste) deixa a chave do APK sem restrição, e nada mais avisa. A chave
     * de teste `flb_test_…` é do sandbox, e a conferência no banco (ehAChaveDoApp) usa sempre a conexão `mysql`.
     * Só avisa, uma vez por worker; não bloqueia nada, e o valor da variável nunca vai para o log.
     */
    protected function avisarSeChaveSuspeita(string $chave): void
    {
        if (static::$avisouChaveSuspeita || str_starts_with($chave, static::PREFIXO_DA_CHAVE_DO_APP)) {
            return;
        }

        // marcado antes de gravar: se o log falhar, a requisição seguinte não tenta de novo
        static::$avisouChaveSuspeita = true;

        Log::warning('[entregas] chave do app do motoboy: ENTREGAS_CHAVE_APP_MOTOBOY deve ser a chave pública flb_live_ do app (a do FLEETBASE_KEY do Navigator), e o valor configurado não começa com flb_live_ (erro de digitação, aspas do Portainer ou chave de teste flb_test_?). Se estiver errada, a chave do APK fica sem restrição.');
    }

    /**
     * O Bearer autentica como a chave do app? Além da igualdade exata, confere no banco do mesmo jeito que o
     * core acha a credencial (`where('key', $token)`). A coluna é utf8mb4_unicode_ci, que não diferencia
     * maiúsculas nem acentos e ignora alguns caracteres invisíveis: "FLB_LIVE_…" também autenticaria como a
     * chave do app e escaparia de uma comparação só de texto. A conferência usa sempre a conexão `mysql`; o
     * core procura as chaves `flb_test_…` na `sandbox`, então só a `flb_live_…` fica coberta por inteiro.
     */
    protected function ehAChaveDoApp(string $bearer, string $chave): bool
    {
        if (hash_equals($chave, $bearer)) {
            return true;
        }

        // Token de usuário (Sanctum `id|token`): o "|" conta na comparação do MySQL e a chave não tem "|",
        // então não pode ser a chave. Assim o console e o app já logado não pagam a consulta.
        if (str_contains($bearer, '|') && !str_contains($chave, '|')) {
            return false;
        }

        return ApiCredential::on('mysql')
            ->withoutGlobalScopes()
            ->where('key', $bearer)
            ->where('key', $chave)
            ->exists();
    }

    protected function casa(string $rota, array $padroes): bool
    {
        foreach ($padroes as $padrao) {
            if (preg_match($padrao, $rota) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function negar(Request $request, string $rota, string $motivo)
    {
        $this->registrarNegacao($request, $rota, $motivo);

        return response()->json(['errors' => ['Esta chave de API só pode ser usada no login do app do motoboy.']], 403);
    }

    /**
     * No máximo uma linha de log por minuto para cada IP + rota: este middleware roda antes do throttle da rota, e
     * quem tem a chave do APK pode repetir a chamada à vontade. Só o log é limitado; o 403 sai em toda requisição.
     * A chave do cache leva só o hash de IP + rota (o cache é Redis em produção): o token nunca entra nela nem no log.
     */
    protected function registrarNegacao(Request $request, string $rota, string $motivo): void
    {
        try {
            $primeira = Cache::add('entregas:chave-app:log:' . sha1($request->ip() . '|' . $rota), true, 60);
        } catch (\Throwable $e) {
            // cache fora do ar: o 403 não pode virar 500, e na dúvida registra
            $primeira = true;
        }

        if ($primeira) {
            Log::info('[entregas] chave do app do motoboy: acesso negado', ['rota' => $rota, 'motivo' => $motivo, 'ip' => $request->ip()]);
        }
    }
}
