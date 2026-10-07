<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ProtegerPortalLoja;
use App\Support\Entregas\Enderecos\BuscaDeEnderecos;
use App\Support\Entregas\LojaDoUsuario;
use Fleetbase\FleetOps\Http\Resources\v1\Place as PlaceResource;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\PlaceSearch;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: busca de endereço pelo Google Places (New), no console e no portal da loja.
 *
 * - GET int/v1/entregas/enderecos/sugestoes?texto=&latitude=&longitude=&sessao= → [{place_id, principal, secundario}]
 * - GET int/v1/entregas/enderecos/detalhes/{placeId}?sessao=&texto= → atributos do Place no formato brasileiro (502 se o
 *   Google falhar ou não trouxer o ponto)
 * - GET int/v1/entregas/enderecos/busca?query=&latitude=&longitude=&sessao= → campo do pedido: locais salvos da empresa
 *   (PlaceSearch do Fleet-Ops, sem o Geocoding) seguidos das sugestões marcadas em meta.entregas_sugestao. Só da central:
 *   o ProtegerPortalLoja não a libera ao usuário de loja, e ela exige a permissão `fleet-ops list place` (admin passa),
 *   como o PlaceController do Fleet-Ops.
 *
 * Usuário motoboy (type=driver): 403 nas três, para o token do app não gastar a cota do Google.
 * Usuário de loja: a referência é sempre o Local da loja (a posição enviada não vale).
 */
class EnderecosController extends Controller
{
    /** Id de lugar do Google (letras, números, - e _). */
    public const FORMATO_DO_PLACE_ID = '/^[A-Za-z0-9_-]{10,300}$/';

    /** Permissões que dão a lista de locais salvos (a do Fleet-Ops e os curingas). */
    public const PERMISSOES_DA_BUSCA = ['fleet-ops list place', 'fleet-ops * place', 'fleet-ops *'];

    public const LOCAIS_SALVOS_NA_BUSCA = 10;

    public function sugestoes(Request $request, BuscaDeEnderecos $busca)
    {
        if ($negado = $this->negarMotoboy()) {
            return $negado;
        }

        [$texto, $sessao] = $this->textoESessao($request, 'texto');

        return response()->json($busca->sugestoes($texto, $this->referencia($request), $sessao));
    }

    public function detalhes(Request $request, string $placeId, BuscaDeEnderecos $busca)
    {
        if ($negado = $this->negarMotoboy()) {
            return $negado;
        }

        if (!preg_match(static::FORMATO_DO_PLACE_ID, $placeId)) {
            return response()->json(['errors' => ['Endereço inválido.']], 422);
        }

        [$texto, $sessao] = $this->textoESessao($request, 'texto');
        $endereco         = $busca->detalhes($placeId, $sessao, $texto !== '' ? $texto : null);

        if ($endereco === null) {
            return response()->json(['errors' => ['Não foi possível carregar o endereço. Marque o ponto no mapa.']], 502);
        }

        return response()->json($endereco);
    }

    public function busca(Request $request, BuscaDeEnderecos $busca)
    {
        if ($negado = $this->negarMotoboy() ?? $this->negarSemPermissaoDeLocais($request)) {
            return $negado;
        }

        [$texto, $sessao] = $this->textoESessao($request, 'query');

        $consulta = Place::where('company_uuid', session('company'))
            ->whereNull('deleted_at')
            ->applyDirectivesForPermissions('fleet-ops list place');
        $salvos = PlaceSearch::search($consulta, $texto !== '' ? $texto : null, [
            'geo'            => false,
            'limit'          => static::LOCAIS_SALVOS_NA_BUSCA,
            'latitude'       => is_numeric($request->input('latitude')) ? $request->input('latitude') : null,
            'longitude'      => is_numeric($request->input('longitude')) ? $request->input('longitude') : null,
            'no_query_order' => 'name_desc',
        ]);

        $locais    = array_values(PlaceResource::collection($salvos)->resolve($request));
        $sugestoes = BuscaDeEnderecos::comoLocais($busca->sugestoes($texto, $this->referencia($request), $sessao), $sessao, $texto);

        return response()->json(array_merge($locais, $sugestoes));
    }

    /** [texto limpo e cortado, token de sessão válido] (BuscaDeEnderecos::entrada). */
    protected function textoESessao(Request $request, string $campo): array
    {
        return BuscaDeEnderecos::entrada($request->input($campo), $request->input('sessao'));
    }

    /** O token do app do motoboy não gasta a cota do Google. */
    protected function negarMotoboy()
    {
        $usuario = User::where('uuid', (string) session('user'))->first();

        if ($usuario && $usuario->type === 'driver') {
            return response()->json(['errors' => ['A busca de endereço não está disponível para motoboys.']], 403);
        }

        return null;
    }

    /** A lista de locais salvos exige a permissão de listar locais (admin passa), como o PlaceController do Fleet-Ops. */
    protected function negarSemPermissaoDeLocais(Request $request)
    {
        $negado  = response()->json(['errors' => ['Você não tem permissão para listar os locais.']], 403);
        $usuario = Auth::getUserFromSession($request);

        if (!$usuario) {
            return $negado;
        }

        if ($usuario->isAdmin()) {
            return null;
        }

        $vinculo = CompanyUser::where('user_uuid', $usuario->uuid)->where('company_uuid', (string) session('company'))->first();
        if (!$vinculo || $vinculo->status === 'inactive') {
            return $negado;
        }

        $nomes = [];
        foreach ($vinculo->getAllPermissions() as $permissao) {
            $nomes[] = (string) $permissao->name;
        }

        return array_intersect(static::PERMISSOES_DA_BUSCA, $nomes) !== [] ? null : $negado;
    }

    protected function referencia(Request $request): array
    {
        if ($request->attributes->get(ProtegerPortalLoja::ATRIBUTO_USUARIO)) {
            $coleta = LojaDoUsuario::coleta(LojaDoUsuario::vendor(session('user')));
            $ponto  = $coleta?->location;

            return BuscaDeEnderecos::referencia(null, null, $ponto ? [$ponto->getLat(), $ponto->getLng()] : null);
        }

        return BuscaDeEnderecos::referencia($request->input('latitude'), $request->input('longitude'));
    }
}
