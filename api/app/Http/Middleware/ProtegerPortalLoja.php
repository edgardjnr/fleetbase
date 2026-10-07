<?php

namespace App\Http\Middleware;

use App\Support\Entregas\LojaDoUsuario;
use Closure;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Entregas RestaurantePro: o que um usuário de loja (User type=customer) pode chamar na API.
 *
 * Middleware global (roda antes das rotas, inclusive as que vêm do Composer), por isso identifica
 * o usuário direto pelo token Sanctum: o do `Authorization: Bearer` (console e portal) ou o do
 * cabeçalho `Customer-Token` (API pública de clientes do Fleet-Ops `v1/customers/*` e do Storefront,
 * que vão com a chave de API no Bearer e criariam pedido com coleta livre, fora destas regras).
 * Com o usuário de loja identificado por qualquer um dos dois, nega por padrão:
 * - customer-portal/int/v1/*: liberado, menos NEGADAS_NO_PORTAL (o portal filtra os dados pela
 *   conta da loja, mas tem rotas sem checagem de admin e telas fora do escopo);
 * - int/v1/*: só PERMITIDAS_INTERNAS, o próprio perfil (campos limitados) e a foto do perfil;
 * - ~registry/v1/engines: só GET (a lista de extensões instaladas, que o console pede ao abrir);
 * - todo o resto, inclusive a API pública v1/* e storefront/*, que aceitam o mesmo token e
 *   devolveriam os dados de todas as lojas.
 * Não confia nas permissões do Fleetbase: o papel "Fleet-Ops Customer" lista e apaga contatos de
 * todas as lojas e edita usuários (inclusive o papel).
 * Usuário de loja desativado não passa em nada, nem no login (o Fleetbase não confere o status).
 * Ninguém se cadastra sozinho: ROTAS_DE_CADASTRO dá 403 a qualquer requisição, com ou sem token
 * (só a central cria usuários e lojas, na tela Lojas).
 */
class ProtegerPortalLoja
{
    /** Onde o usuário de loja fica guardado no request (o RegrasPortalLoja lê daqui). */
    public const ATRIBUTO_USUARIO = 'entregas.usuario_loja';

    public const ROTAS_DE_LOGIN = ['customer-portal/int/v1/auth/login', 'int/v1/auth/login'];

    /**
     * Cadastro por conta própria (POST): o core cria uma organização nova com um administrador, sem token (é o botão
     * "Create a new Account" do login do console). O customer-portal-api não tem rota de cadastro, só `auth/login`:
     * ao atualizá-lo, confira se ele ganhou uma (`auth/register`, `auth/sign-up`...) e inclua aqui.
     */
    public const ROTAS_DE_CADASTRO = ['int/v1/onboard/create-account'];

    public const MENSAGEM_DE_CADASTRO = 'O cadastro é feito pela central. Fale com a central.';

    /** int/v1: "MÉTODO caminho" liberados para o usuário de loja. */
    public const PERMITIDAS_INTERNAS = [
        '#^GET auth/(session|organizations|validate-verification)$#',
        '#^POST auth/(login|logout|get-magic-reset-link|reset-password|confirm-email-change|create-verification-session|validate-verification-session|send-verification-email|verify-email)$#',
        '#^(GET|POST) two-fa/(check|validate|verify|resend|invalidate)$#',
        '#^GET lookup/[a-z-]+(/[A-Za-z]{2})?$#',
        '#^GET settings/branding$#',
        '#^GET users/me$#',
        '#^(GET|POST) users/locale$#',
        '#^GET geocoder/(reverse|query)$#',
        // painel da home do portal (<Dashboard>): o DashboardFilter e o DashboardController filtram pelo usuário da sessão
        '#^GET dashboards$#',
        '#^POST dashboards/(switch|reset-default)$#',
        '#^[A-Z]+ entregas/loja/.+$#',
        // busca de endereço pelo Google (EnderecosController); a rota busca lista locais salvos da empresa toda e fica fora
        '#^GET entregas/enderecos/(sugestoes|detalhes/[A-Za-z0-9_-]+)$#',
    ];

    /** customer-portal/int/v1: rotas do portal negadas ao usuário de loja. */
    public const NEGADAS_NO_PORTAL = [
        '#^[A-Z]+ settings(/.*)?$#',                          // configuração do portal: o portal não confere se é admin
        '#^[A-Z]+ service-quotes(/.*)?$#',                    // cotação: sobrescreve endereço salvo (até o da loja); sem tarifas no Entregas
        '#^[A-Z]+ (account|contacts/[^/]+)/convert-to-vendor$#',
        '#^GET account/personnel-candidates$#',              // lista os usuários de todas as lojas
        '#^(POST|DELETE) account/personnels(/[^/]+)?$#',     // usuários da loja: só a central cria (tela Lojas)
        '#^POST orders/[^/]+/reschedule$#',
        '#^[A-Z]+ orders/[^/]+/files(/[^/]+)?$#',
        '#^[A-Z]+ (issues|invoices)(/.*)?$#',                // suporte e faturas: fora do escopo
        '#^GET (documents|address-book|pending-actions)$#',
        '#^[A-Z]+ notification-preferences$#',
    ];

    /**
     * Campos que o usuário de loja pode mudar no próprio perfil: nome, foto e fuso. Sem papel, permissões,
     * status ou empresa, e sem e-mail/telefone: o login só muda pela central (se a loja trocasse, a trava
     * "um login, uma loja" da tela Lojas poderia ser contornada, porque o Fleetbase liga um contato novo
     * ao usuário que já tem aquele e-mail/telefone).
     */
    public const CAMPOS_DO_PERFIL = ['name', 'avatar_uuid', 'avatar_url', 'timezone'];

    /**
     * Lista das extensões instaladas (registry-bridge), que o console pede ao abrir cada página
     * (ember-core load-installed-extensions.js). Liberada só em GET/HEAD e neste caminho exato.
     */
    public const CAMINHO_DAS_EXTENSOES = '~registry/v1/engines';

    /** Cabeçalho do token do cliente na API pública de clientes (Fleet-Ops CustomerAuth::HEADER e Storefront). */
    public const CABECALHO_TOKEN_DO_CLIENTE = 'Customer-Token';

    /** Foto do perfil: MIME real do conteúdo => extensões aceitas no nome (o core grava com a extensão do nome). */
    public const FOTO_TIPOS = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];

    public const FOTO_TAMANHO_MAXIMO = 5 * 1024 * 1024; // 5 MB

    /** Parâmetros do upload que a loja não escolhe (vale o padrão do servidor); os `resize*` também saem. */
    public const PARAMETROS_DO_UPLOAD_REMOVIDOS = ['disk', 'bucket', 'path', 'file_size'];

    public function handle(Request $request, Closure $next)
    {
        // caminho decodificado, como o roteador do Laravel casa as rotas (rawurldecode): senão
        // "account/personnel%2Dcandidates" escaparia de NEGADAS_NO_PORTAL e cairia na rota
        $caminho = trim(rawurldecode($request->path()), '/');
        // HEAD executa a rota GET (o roteador registra as duas)
        $metodo = $request->isMethod('HEAD') ? 'GET' : $request->method();

        // antes da saída das requisições sem usuário de loja (logo abaixo): o cadastro é feito sem token
        if ($metodo === 'POST' && in_array($caminho, static::ROTAS_DE_CADASTRO, true) && $this->cadastroFechado()) {
            return response()->json(['errors' => [static::MENSAGEM_DE_CADASTRO]], 403);
        }

        if ($request->isMethod('POST') && in_array($caminho, static::ROTAS_DE_LOGIN, true) && $this->loginDesativado($request)) {
            return response()->json(['errors' => ['Acesso desativado. Fale com a central.']], 401);
        }

        $usuario = $this->usuario($request);
        if (!$usuario || $usuario->type !== 'customer') {
            return $next($request);
        }

        if ($usuario->status !== 'active') {
            return $this->negar($caminho, $usuario, 'login desativado');
        }

        $request->attributes->set(static::ATRIBUTO_USUARIO, $usuario);

        // o portal juntaria os pedidos de todas as lojas do usuário: um login, uma loja
        if ((str_starts_with($caminho, 'customer-portal/int/v1/') || str_starts_with($caminho, 'int/v1/entregas/loja/'))
            && LojaDoUsuario::totalDeLojas($usuario->uuid) > 1) {
            return $this->negar($caminho, $usuario, 'usuário ligado a mais de uma loja');
        }

        if (str_starts_with($caminho, 'customer-portal/int/v1/')) {
            $rota = $metodo . ' ' . substr($caminho, strlen('customer-portal/int/v1/'));

            return $this->casa($rota, static::NEGADAS_NO_PORTAL) ? $this->negar($caminho, $usuario) : $next($request);
        }

        if (str_starts_with($caminho, 'int/v1/')) {
            $rota = $metodo . ' ' . substr($caminho, strlen('int/v1/'));

            if ($this->casa($rota, static::PERMITIDAS_INTERNAS)) {
                return $next($request);
            }

            if ($this->ehOProprioPerfil($rota, $usuario)) {
                $this->limitarCamposDoPerfil($request);

                return $next($request);
            }

            if ($rota === 'POST files/upload' && $this->ehFotoDoProprioPerfil($request, $usuario)) {
                if (!$this->fotoValida($request)) {
                    return response()->json(['errors' => ['A foto do perfil precisa ser uma imagem JPG, PNG ou WEBP de até 5 MB.']], 422);
                }

                $this->limparParametrosDoUpload($request);

                return $next($request);
            }
        }

        // Entregas: a lista de extensões instaladas é informação pública e o console a pede em toda página que abre. Sem esta
        // exceção o usuário de loja levava 403 nela e o log de acesso negado (a pista das chamadas necessárias que faltam
        // liberar) enchia de ruído. Só GET (HEAD vira GET acima) e exatamente este caminho.
        if ($metodo === 'GET' && $caminho === static::CAMINHO_DAS_EXTENSOES) {
            return $next($request);
        }

        return $this->negar($caminho, $usuario);
    }

    /**
     * Usuário do Bearer; se ele não for de loja, o do Customer-Token (a API pública de clientes vai com a
     * chave de API no Bearer). Um usuário de loja em qualquer dos dois cabeçalhos faz valerem as regras.
     */
    protected function usuario(Request $request): ?User
    {
        $doBearer = $this->usuarioDoToken($request->bearerToken());
        if ($doBearer && $doBearer->type === 'customer') {
            return $doBearer;
        }

        $doCliente = $this->usuarioDoToken($request->header(static::CABECALHO_TOKEN_DO_CLIENTE));

        return $doCliente && $doCliente->type === 'customer' ? $doCliente : $doBearer;
    }

    /** Dono de um token Sanctum (`id|token`); chave de API (`flb_live_…`) ou token inválido → null. */
    protected function usuarioDoToken(?string $token): ?User
    {
        if (!$token) {
            return null;
        }

        $usuario = PersonalAccessToken::findToken($token)?->tokenable;

        return $usuario instanceof User ? $usuario : null;
    }

    /**
     * O login é de um usuário de loja desativado? Confere o mesmo usuário que o login autentica: a consulta do
     * AuthController do portal (e do core), por e-mail ou telefone, sem filtro de tipo e com o first(). Assim
     * outro usuário com o mesmo e-mail ou telefone (um cliente pendente, por exemplo) não barra uma loja ativa.
     */
    protected function loginDesativado(Request $request): bool
    {
        $identidade = $request->input('identity');
        if (!is_string($identidade) || trim($identidade) === '') {
            return false;
        }

        // o mesmo valor que o controller usa (o TrimStrings global já tirou os espaços)
        $usuario = User::where(fn ($q) => $q->where('email', $identidade)->orWhere('phone', $identidade))->first();

        // status nulo também conta como desativado (como no handle(), que só aceita "active")
        return $usuario instanceof User && $usuario->type === 'customer' && $usuario->status !== 'active';
    }

    /**
     * O cadastro por conta própria está fechado? Só a instalação o libera: sem nenhum usuário, o core faz do primeiro
     * cadastro o administrador da plataforma (OnboardController::createAccount confere o mesmo `User::exists()`).
     * Na dúvida (erro ao consultar o banco), fechado.
     */
    protected function cadastroFechado(): bool
    {
        try {
            return User::exists();
        } catch (\Throwable $e) {
            return true;
        }
    }

    protected function idsDoUsuario(User $usuario): array
    {
        return array_values(array_filter([$usuario->uuid, $usuario->public_id, (string) $usuario->id]));
    }

    protected function ehOProprioPerfil(string $rota, User $usuario): bool
    {
        return preg_match('#^(PUT|PATCH) users/([^/]+)$#', $rota, $m) === 1 && in_array($m[2], $this->idsDoUsuario($usuario), true);
    }

    /** Só nome, foto e fuso: sem papel, permissões, políticas, status, empresa ou login (e-mail/telefone). */
    protected function limitarCamposDoPerfil(Request $request): void
    {
        $entrada = $request->isJson() ? $request->json() : $request->request;
        $dados   = $entrada->all();

        $entrada->replace(is_array($dados['user'] ?? null)
            ? ['user' => Arr::only($dados['user'], static::CAMPOS_DO_PERFIL)]
            : Arr::only($dados, static::CAMPOS_DO_PERFIL));

        // o input() do Laravel junta a query string (e os arquivos) ao corpo: sem isto, um corpo sem
        // "user" e "?user[role]=..." na URL chegariam ao UserController (user.role, user.policies...)
        $request->query->replace([]);
        $request->files->replace([]);
    }

    protected function ehFotoDoProprioPerfil(Request $request, User $usuario): bool
    {
        $sujeito = $request->input('subject_uuid');

        return $request->input('type') === 'user_avatar' && is_string($sujeito) && in_array($sujeito, $this->idsDoUsuario($usuario), true);
    }

    /**
     * O campo `file` (o que o FileController@upload do core lê) tem de ser uma imagem JPEG, PNG ou WEBP pelo
     * conteúdo, com a extensão do nome combinando (o core grava o arquivo com ela), de até 5 MB.
     */
    protected function fotoValida(Request $request): bool
    {
        $arquivo = $request->file('file');
        if (!$arquivo instanceof UploadedFile || !$arquivo->isValid()) {
            return false;
        }

        $tamanho = $arquivo->getSize();
        if (!is_int($tamanho) || $tamanho <= 0 || $tamanho > static::FOTO_TAMANHO_MAXIMO) {
            return false;
        }

        try {
            $mime = $arquivo->getMimeType(); // pelo conteúdo (finfo), não pelo que o navegador informou
        } catch (\Throwable $e) {
            return false;
        }

        $tipos     = static::FOTO_TIPOS;
        $extensoes = is_string($mime) ? ($tipos[$mime] ?? null) : null;

        return $extensoes !== null && in_array(strtolower($arquivo->getClientOriginalExtension()), $extensoes, true);
    }

    /** Disco, bucket, pasta, tamanho informado e redimensionamento ficam no padrão do servidor (corpo e query). */
    protected function limparParametrosDoUpload(Request $request): void
    {
        foreach ([$request->request, $request->query] as $parametros) {
            foreach (array_keys($parametros->all()) as $chave) {
                if (in_array($chave, static::PARAMETROS_DO_UPLOAD_REMOVIDOS, true) || str_starts_with((string) $chave, 'resize')) {
                    $parametros->remove($chave);
                }
            }
        }
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

    protected function negar(string $caminho, User $usuario, string $motivo = 'rota fora do portal da loja')
    {
        Log::info('[entregas] portal da loja: acesso negado', ['caminho' => $caminho, 'motivo' => $motivo, 'usuario' => $usuario->public_id]);

        return response()->json(['errors' => ['Acesso não permitido para usuários de loja.']], 403);
    }
}
