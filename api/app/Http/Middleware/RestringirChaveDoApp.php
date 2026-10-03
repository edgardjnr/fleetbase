<?php

namespace App\Http\Middleware;

use Closure;
use Fleetbase\Models\ApiCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a chave pública do app do motoboy (Navigator) só serve para o login.
 *
 * Por quê: a chave `flb_live_…` vai dentro do APK e, no Bearer, autentica a API pública `v1/*` como o
 * administrador que a criou (AuthenticateOnceWithBasicAuth do core), sem token de usuário. Quem a extrai do
 * APK lista, cria e cancela pedidos e lê os clientes de todas as lojas. O app só precisa dela até o login:
 * depois, o SDK manda no Bearer o token do motoboy (Sanctum `id|token`), que não é a chave e passa direto.
 * O app não manda token de usuário em outro cabeçalho junto com a chave (o SDK só põe Authorization,
 * Content-Type e User-Agent), então chave no Bearer = motoboy ainda sem login.
 *
 * Com `services.entregas.chave_app_motoboy` (ENTREGAS_CHAVE_APP_MOTOBOY) vazia, que é o padrão, nada muda.
 * Preenchida, a requisição cujo Bearer autentica como essa chave só passa em PERMITIDAS_COM_A_CHAVE, que é
 * o que o Navigator chama com ela: o login por SMS e a conferência do código e, logo depois do login, o
 * registro do celular para o push e as organizações do motoboy (o app ainda faz essas duas com a chave).
 * O resto leva 403 e fica no log. Outros Bearers passam: token de usuário, as chaves e o secret da
 * integração iFood, outras chaves de API. Por isso a integração iFood não pode usar a mesma chave do app.
 * Se o app ganhar outra forma de login, a rota nova entra em PERMITIDAS_COM_A_CHAVE.
 *
 * Ligar (só depois de testar num celular: login, receber pedido, aceitar, concluir):
 *  1. no deploy/stack.env, ENTREGAS_CHAVE_APP_MOTOBOY = a chave pública do app (a mesma do secret
 *     FLEETBASE_KEY do repo entregas-navigator);
 *  2. Portainer: Stacks → entregas → Editor → Update the stack ("Re-pull image" desligado);
 *  3. a API sobe de novo com a variável. Se não subir, rode `docker service update --force entregas_application`.
 *     O config:cache do deploy.sh fica dentro do container, e o container novo lê a variável.
 * Desligar: apagar o valor da variável e repetir os passos 2 e 3.
 */
class RestringirChaveDoApp
{
    /** "MÉTODO caminho" (caminho decodificado, sem a barra inicial) que a chave do app pode chamar. */
    public const PERMITIDAS_COM_A_CHAVE = [
        '#^POST v1/drivers/login-with-sms$#',        // manda o código por SMS (ou por e-mail, se o SMS falhar)
        '#^POST v1/drivers/verify-code$#',           // confere o código e devolve o token do motoboy
        '#^POST v1/drivers/[^/]+/register-device$#', // push: logo depois do login o app registra o celular ainda com a chave
        '#^GET v1/drivers/[^/]+/organizations$#',    // logo depois do login o app carrega as organizações ainda com a chave
    ];

    /** Único `for` aceito no verify-code com a chave: o login (`create_driver` cria motoboy). */
    public const VERIFICACAO_DE_LOGIN = 'driver_login';

    public function handle(Request $request, Closure $next)
    {
        $chave = trim((string) config('services.entregas.chave_app_motoboy'));
        if ($chave === '') {
            return $next($request);
        }

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

        // DriverController::verifyCode com `for=create_driver` cria um motoboy: com a chave, só o login
        if ($rota === 'POST v1/drivers/verify-code' && !in_array($request->input('for'), [null, static::VERIFICACAO_DE_LOGIN], true)) {
            return $this->negar($request, $rota, 'verify-code fora do login');
        }

        return $next($request);
    }

    /**
     * O Bearer autentica como a chave do app? Além da igualdade exata, confere no banco do mesmo jeito que o
     * core acha a credencial (`where('key', $token)`). A coluna é utf8mb4_unicode_ci, que não diferencia
     * maiúsculas nem acentos e ignora alguns caracteres invisíveis: "FLB_LIVE_…" também autenticaria como a
     * chave do app e escaparia de uma comparação só de texto.
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
        Log::info('[entregas] chave do app do motoboy: acesso negado', ['rota' => $rota, 'motivo' => $motivo, 'ip' => $request->ip()]);

        return response()->json(['errors' => ['Esta chave de API só pode ser usada no login do app do motoboy.']], 403);
    }
}
