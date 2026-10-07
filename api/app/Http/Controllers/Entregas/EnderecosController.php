<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ProtegerPortalLoja;
use App\Support\Entregas\Enderecos\BuscaDeEnderecos;
use App\Support\Entregas\LojaDoUsuario;
use Fleetbase\FleetOps\Http\Resources\v1\Place as PlaceResource;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\PlaceSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Entregas RestaurantePro: busca de endereço pelo Google Places (New), no console e no portal da loja.
 *
 * - GET int/v1/entregas/enderecos/sugestoes?texto=&latitude=&longitude=&sessao= → [{place_id, principal, secundario}]
 * - GET int/v1/entregas/enderecos/detalhes/{placeId}?sessao=&texto= → atributos do Place no formato brasileiro (502 se o
 *   Google falhar ou não trouxer o ponto)
 * - GET int/v1/entregas/enderecos/busca?query=&latitude=&longitude=&sessao= → campo do pedido: locais salvos da empresa
 *   (PlaceSearch do Fleet-Ops, sem o Geocoding) seguidos das sugestões marcadas em meta.entregas_sugestao. Só da central:
 *   o ProtegerPortalLoja não a libera ao usuário de loja.
 *
 * Usuário de loja: a referência é sempre o Local da loja (a posição enviada não vale).
 */
class EnderecosController extends Controller
{
    /** Id de lugar do Google (letras, números, - e _). */
    public const FORMATO_DO_PLACE_ID = '/^[A-Za-z0-9_-]{10,300}$/';

    /** Token de sessão vindo da tela; fora do formato, o servidor gera um. */
    public const FORMATO_DA_SESSAO = '/^[A-Za-z0-9_-]{8,64}$/';

    public const LOCAIS_SALVOS_NA_BUSCA = 10;

    public function sugestoes(Request $request, BuscaDeEnderecos $busca)
    {
        [$texto, $sessao] = $this->textoESessao($request, 'texto');

        return response()->json($busca->sugestoes($texto, $this->referencia($request), $sessao));
    }

    public function detalhes(Request $request, string $placeId, BuscaDeEnderecos $busca)
    {
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
        [$texto, $sessao] = $this->textoESessao($request, 'query');

        $consulta = Place::where('company_uuid', session('company'))->whereNull('deleted_at');
        $salvos   = PlaceSearch::search($consulta, $texto !== '' ? $texto : null, [
            'geo'            => false,
            'limit'          => static::LOCAIS_SALVOS_NA_BUSCA,
            'latitude'       => $request->input('latitude'),
            'longitude'      => $request->input('longitude'),
            'no_query_order' => 'name_desc',
        ]);

        $locais    = array_values(PlaceResource::collection($salvos)->resolve($request));
        $sugestoes = BuscaDeEnderecos::comoLocais($busca->sugestoes($texto, $this->referencia($request), $sessao), $sessao, $texto);

        return response()->json(array_merge($locais, $sugestoes));
    }

    /** [texto sem espaços nas pontas, token de sessão válido]. */
    protected function textoESessao(Request $request, string $campo): array
    {
        $texto  = trim((string) $request->input($campo, ''));
        $sessao = (string) $request->input('sessao', '');
        if (!preg_match(static::FORMATO_DA_SESSAO, $sessao)) {
            $sessao = (string) Str::uuid();
        }

        return [$texto, $sessao];
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
