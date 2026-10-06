<?php

namespace App\Http\Middleware;

use App\Support\Entregas\Ifood\PedidosIfood;
use App\Support\Entregas\LojaDoUsuario;
use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Closure;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Entregas RestaurantePro: regras de negócio das rotas do portal da loja (customer-portal/int/v1/*)
 * para o usuário de loja que o ProtegerPortalLoja identificou (o PHP do portal vem do Composer).
 *
 * - Novo pedido: a coleta é sempre o Local da loja (o que vier na requisição é descartado); o destino
 *   tem de ser um endereço salvo da loja com coordenadas, a 30 m ou mais da coleta (o km da cobrança sai
 *   delas); criado o pedido, ele é despachado como pedido aberto (adhoc) aos motoboys próximos, como faz a central.
 * - Cancelamento: só antes de um motoboy aceitar, conferido e feito com a trava do pedido (TravaDoPedido), a
 *   mesma do aceite no app; depois do portal, que só grava o status, grava a atividade e o evento do
 *   cancelamento e tira o pedido dos pedidos abertos do app do motoboy. Pedido do iFood nunca: 400 (o cancelamento é
 *   feito no iFood; RegrasDoPedidoIfood barra o mesmo na API v1 e no console).
 * - Endereços: gravados com as coordenadas marcadas no mapa (o servidor não geocodifica). Endereço
 *   salvo não muda pelo portal (nem por edição, nem sobrescrito por um cadastro com o mesmo nome e
 *   rua): pedidos já feitos usam esses endereços e o km da cobrança sai deles.
 *
 * O portal lê a entrada por input()/only()/filled(), que juntam a query string ao corpo (e o only(),
 * também os arquivos enviados): os campos que estas regras conferem saem da query string e dos
 * arquivos, para o portal ver só o que foi conferido aqui. O corpo é lido por all(), porque o
 * InputBag::get() recusa (400) campo com objeto, como o `dropoff` que o front do portal manda.
 */
class RegrasPortalLoja
{
    public const PREFIXO = 'customer-portal/int/v1/';

    /**
     * O que a loja não define no pedido: coleta, paradas extras, arquivos, meta (cache do km), agendamento
     * e itens (o Fleetbase baixa a `photo` de um item de qualquer URL e devolve o link: leitura de rede interna).
     * - `internal_id`: o Order::findRecordOrFail, que as rotas do app do motoboy usam (inclusive o aceite), acha
     *   o pedido pelo public_id ou pelo internal_id. Uma loja que gravasse ali o public_id de outro pedido
     *   confundiria essas rotas.
     * - `pod_required` e `pod_method`: a comprovação de entrega é decisão da central.
     */
    public const CAMPOS_DESCARTADOS = [
        'payload', 'pickup', 'return', 'waypoints', 'files', 'meta', 'scheduled_at', 'entities',
        'internal_id', 'pod_required', 'pod_method',
    ];

    /** Campos do endereço novo conferidos aqui (o PlaceController os lê por input()/only()/filled()). */
    public const CAMPOS_DO_ENDERECO = ['name', 'street1', 'address', 'latitude', 'longitude', 'location'];

    /** Status em que a loja ainda pode cancelar, desde que nenhum motoboy tenha aceitado. */
    public const STATUS_CANCELAVEIS = ['created', 'dispatched'];

    /**
     * Destino mais perto que isto da coleta é recusado. O km da cobrança da loja e do pagamento do motoboy sai
     * das coordenadas, e um destino marcado na loja (o mapa do portal abre nela) daria km ~0. Como o endereço
     * salvo não se edita pelo portal, o ponto errado voltaria nos pedidos seguintes.
     */
    public const DISTANCIA_MINIMA_DA_COLETA_METROS = 30;

    public function handle(Request $request, Closure $next)
    {
        $usuario = $request->attributes->get(ProtegerPortalLoja::ATRIBUTO_USUARIO);
        // caminho decodificado, como o roteador do Laravel casa as rotas (igual ao ProtegerPortalLoja): com o
        // path() cru, "POST .../order%73" escaparia da coleta fixa e "PATCH .../place%73/{id}" do 403
        $caminho = trim(rawurldecode($request->path()), '/');

        if (!$usuario instanceof User || !str_starts_with($caminho, static::PREFIXO)) {
            return $next($request);
        }

        // method() é o mesmo que o roteador usa (já com o _method / X-HTTP-Method-Override aplicado)
        $rota = $request->method() . ' ' . substr($caminho, strlen(static::PREFIXO));

        if ($rota === 'POST orders') {
            return $this->novoPedido($request, $next, $usuario);
        }

        if (preg_match('#^POST orders/([^/]+)/cancel$#', $rota, $m)) {
            return $this->cancelamento($request, $next, $usuario, $m[1]);
        }

        if ($rota === 'POST places') {
            return $this->novoEndereco($request, $next, $usuario);
        }

        // o portal registra só PATCH e DELETE para places/{id} (não há PUT)
        if (preg_match('#^(PATCH|DELETE) places/[^/]+$#', $rota)) {
            return $this->erro(403, 'Endereços salvos não podem ser alterados pelo portal. Cadastre um endereço novo.');
        }

        return $next($request);
    }

    protected function novoPedido(Request $request, Closure $next, User $usuario)
    {
        $vendor = LojaDoUsuario::vendor($usuario->uuid);
        if (!$vendor) {
            return $this->erro(403, 'Este usuário não está ligado a nenhuma loja. Fale com a central.');
        }

        $coleta = LojaDoUsuario::coleta($vendor);
        if (!$coleta || !$this->ehDaLoja($coleta, $vendor, $usuario->company_uuid) || !$this->temCoordenadas($coleta)) {
            Log::warning('[entregas] portal da loja: loja sem local de coleta válido', ['loja' => $vendor->public_id]);

            return $this->erro(422, 'A loja ainda não tem endereço de coleta cadastrado. Fale com a central.');
        }

        $entrada = $this->entrada($request);
        $destino = $this->lugarDaLoja($this->identificador($this->campo($entrada, 'dropoff')), $vendor, $usuario->company_uuid);
        if (!$destino || !$this->temCoordenadas($destino)) {
            return $this->erro(422, 'Escolha um endereço de entrega salvo e marcado no mapa.');
        }
        if ($destino->uuid === $coleta->uuid) {
            return $this->erro(422, 'O endereço de entrega não pode ser o da própria loja.');
        }
        // outro Place, mas com o ponto na loja: o km sai das coordenadas e daria ~0 (ver DISTANCIA_MINIMA_DA_COLETA_METROS)
        if ($this->metrosEntre($coleta->location, $destino->location) < static::DISTANCIA_MINIMA_DA_COLETA_METROS) {
            return $this->erro(422, 'O endereço de entrega está no mesmo lugar da loja. Cadastre o endereço de novo, marcando no mapa o local da entrega.');
        }

        // fora do corpo, da query string e dos arquivos: o portal só vê a coleta e o destino conferidos aqui
        foreach ([...static::CAMPOS_DESCARTADOS, 'dropoff'] as $campo) {
            $entrada->remove($campo);
            $request->query->remove($campo);
        }
        $request->files->replace([]);
        $entrada->set('pickup', $coleta->uuid);
        $entrada->set('dropoff', $destino->uuid);

        $resposta = $next($request);

        if ($resposta->isSuccessful()) {
            $this->despacharParaMotoboys((string) $resposta->getContent(), $vendor, $usuario);
        }

        return $resposta;
    }

    /** Pedido aberto (adhoc) para os motoboys próximos da loja, como a central faz. */
    protected function despacharParaMotoboys(string $conteudo, Vendor $vendor, User $usuario): void
    {
        // a resposta do portal é {"order": OrderResource}; rota interna → uuid e public_id (o id é numérico)
        $dados = json_decode($conteudo, true);
        $ids   = array_values(array_filter(
            [data_get($dados, 'order.uuid'), data_get($dados, 'order.public_id'), data_get($dados, 'order.id')],
            fn ($id) => is_string($id) && $id !== ''
        ));

        $pedido = $ids ? Order::where('company_uuid', $usuario->company_uuid)
            ->where('customer_uuid', $vendor->uuid)
            ->where(fn ($q) => $q->whereIn('uuid', $ids)->orWhereIn('public_id', $ids))
            ->first() : null;

        if (!$pedido) {
            Log::warning('[entregas] portal da loja: pedido criado não encontrado para despachar', ['loja' => $vendor->public_id]);

            return;
        }

        try {
            session(['company' => $pedido->company_uuid, 'user' => $usuario->uuid]);
            // sem eventos: o despacho logo abaixo grava de novo e emite os eventos (sem um "order.updated" a mais)
            $pedido->adhoc = true;
            $pedido->saveQuietly();
            // dispatched + atividade "dispatched" (status) + OrderDispatched na fila → HandleOrderDispatched avisa os
            // motoboys disponíveis e online no raio da coleta
            $pedido->firstDispatchWithActivity();
        } catch (\Throwable $e) {
            // o pedido existe; a central despacha à mão
            Log::error('[entregas] portal da loja: falha ao despachar o pedido aos motoboys', ['pedido' => $pedido->public_id, 'erro' => $e->getMessage()]);
        }
    }

    protected function cancelamento(Request $request, Closure $next, User $usuario, string $id)
    {
        // os mesmos donos de pedido que o portal aceita (PortalOrderService::accountCustomerUuids): a loja e o
        // contato do usuário; sem o contato, um pedido lançado pela central para ele seria cancelado sem a regra
        $donos = array_values(array_filter([
            LojaDoUsuario::vendor($usuario->uuid)?->uuid,
            LojaDoUsuario::contato($usuario->uuid)?->uuid,
        ]));

        $pedido = $donos ? Order::where('company_uuid', $usuario->company_uuid)
            ->whereIn('customer_uuid', $donos)
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->first() : null;

        // pedido de outra loja (ou inexistente): o próprio portal responde 404
        if (!$pedido) {
            return $next($request);
        }

        // pedido do iFood: para nós vale o cancelamento do iFood (CAN), que a loja faz no Gestor de Pedidos
        if (PedidosIfood::ehDoIfood((string) $pedido->uuid)) {
            return $this->erro(400, RegrasDoPedidoIfood::MENSAGEM_CANCELAMENTO);
        }

        // a conferência e o cancelamento com a trava do pedido, a mesma do aceite no app: o motoboy não aceita no meio
        try {
            return TravaDoPedido::executar($pedido->uuid, fn () => $this->cancelarComATrava($request, $next, $usuario, $pedido));
        } catch (LockTimeoutException $e) {
            Log::warning('[entregas] portal da loja: trava do pedido ocupada no cancelamento', ['pedido' => $pedido->public_id]);

            return $this->erro(409, 'Este pedido está sendo atualizado neste momento. Tente cancelar de novo em alguns segundos.');
        }
    }

    protected function cancelarComATrava(Request $request, Closure $next, User $usuario, Order $pedido)
    {
        // relido com a trava: um aceite que terminou enquanto esta requisição esperava aparece aqui
        $pedido = $pedido->fresh();
        if (!$pedido) {
            return $next($request);
        }

        if (in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
            return $this->erro(422, 'Este pedido já foi encerrado.');
        }

        if ($pedido->started || !in_array($pedido->status, static::STATUS_CANCELAVEIS, true)) {
            return $this->erro(422, 'O motoboy já aceitou este pedido. Para cancelar, fale com a central.');
        }

        // o portal grava o status e responde {"order": ...}, que o front usa (a resposta é mantida)
        $resposta = $next($request);

        if ($resposta->isSuccessful()) {
            $this->completarCancelamento($pedido, $usuario);
        }

        return $resposta;
    }

    /**
     * O cancelOrder do portal só grava status = canceled: não grava a atividade (tracking status), não dispara o
     * OrderCanceled (que avisa o motoboy atribuído e vai para o socket e para os webhooks) e deixa o pedido
     * despachado e aberto (adhoc), que é como o app do motoboy reconhece um pedido à espera de aceite (lista de
     * pedidos abertos e card de aceitar). O pedido já está cancelado: uma falha aqui só vai para o log, e a
     * resposta do portal vale do mesmo jeito.
     */
    protected function completarCancelamento(Order $pedido, User $usuario): void
    {
        try {
            // relido: o portal acabou de gravar o status (sem isto, o cancel() o regravaria e dispararia outro "updated")
            $pedido = $pedido->fresh() ?? $pedido;

            // primeiro e sem eventos: o pedido sai dos pedidos abertos do app mesmo que o cancel() falhe
            $pedido->dispatched = false;
            $pedido->adhoc      = false;
            $pedido->saveQuietly();

            session(['company' => $pedido->company_uuid, 'user' => $usuario->uuid]);
            // atividade "canceled" (status do pedido) + OrderCanceled na fila
            $pedido->cancel();
        } catch (\Throwable $e) {
            // com a exceção no contexto: o log grava a classe e o stack, e não só a mensagem
            Log::error('[entregas] portal da loja: falha ao registrar o cancelamento do pedido', ['pedido' => $pedido->public_id, 'erro' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /** Endereço novo: com as coordenadas marcadas no mapa e sem sobrescrever um endereço salvo. */
    protected function novoEndereco(Request $request, Closure $next, User $usuario)
    {
        // o PlaceController junta a query string e os arquivos ao corpo: sem isto, ele gravaria valores
        // diferentes dos conferidos abaixo
        foreach (static::CAMPOS_DO_ENDERECO as $campo) {
            $request->query->remove($campo);
        }
        $request->files->replace([]);

        $entrada = $this->entrada($request);
        if (!$this->copiarCoordenadas($entrada)) {
            return $this->erro(422, 'Marque no mapa o local do endereço (botão "Selecionar no mapa").');
        }

        // o portal grava com firstOrNew(empresa, dono, nome = strtoupper(name ?: rua), rua = strtoupper(street1 ?: address))
        // e sobrescreveria o endereço salvo com a mesma chave (até o Local da loja): mesma consulta, mesmas linhas
        $vendor = LojaDoUsuario::vendor($usuario->uuid);
        $rua    = $this->texto($this->campo($entrada, 'street1')) ?: $this->texto($this->campo($entrada, 'address'));
        $nome   = $this->texto($this->campo($entrada, 'name')) ?: $rua;
        if ($vendor && Place::where('company_uuid', $usuario->company_uuid)
            ->where('owner_uuid', $vendor->uuid)
            ->where('owner_type', Utils::getMutationType($vendor))
            ->where('name', strtoupper($nome))
            ->where('street1', strtoupper($rua))
            ->exists()) {
            return $this->erro(422, 'Já existe um endereço salvo com este nome e esta rua. Escolha-o na lista ou use outro nome.');
        }

        return $next($request);
    }

    /**
     * O mapa do portal grava a posição em `location` (GeoJSON [lng, lat]), mas o portal só lê
     * `latitude`/`longitude`. Copia para esses campos. Retorna se ficou com coordenadas válidas.
     */
    protected function copiarCoordenadas(ParameterBag $entrada): bool
    {
        $lat = $this->campo($entrada, 'latitude');
        $lng = $this->campo($entrada, 'longitude');

        if (!$this->coordenadaValida($lat, $lng)) {
            $coordenadas = data_get($entrada->all(), 'location.coordinates');
            if (is_array($coordenadas) && count($coordenadas) === 2) {
                [$lng, $lat] = array_values($coordenadas);
            }
        }

        if (!$this->coordenadaValida($lat, $lng)) {
            return false;
        }

        $entrada->set('latitude', (float) $lat);
        $entrada->set('longitude', (float) $lng);

        return true;
    }

    /** uuid/public_id de um endereço vindo como string ou como objeto. */
    protected function identificador($lugar): ?string
    {
        if (is_string($lugar) && $lugar !== '') {
            return $lugar;
        }

        if (is_array($lugar)) {
            foreach (['uuid', 'public_id', 'id'] as $campo) {
                if (is_string($lugar[$campo] ?? null) && $lugar[$campo] !== '') {
                    return $lugar[$campo];
                }
            }
        }

        return null;
    }

    /**
     * Endereço salvo da loja (dono = o Vendor), por uuid ou public_id: a mesma consulta do
     * PortalOrderService::findAccountPlaceByIdentifier, que monta o pedido.
     */
    protected function lugarDaLoja(?string $id, Vendor $vendor, ?string $companyUuid): ?Place
    {
        if (!$id || !$companyUuid) {
            return null;
        }

        return Place::where('company_uuid', $companyUuid)
            ->where('owner_uuid', $vendor->uuid)
            ->where('owner_type', Utils::getMutationType($vendor))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->first();
    }

    /** Mesmo critério do portal para achar a coleta no pedido: empresa e dono = o Vendor. */
    protected function ehDaLoja(Place $lugar, Vendor $vendor, ?string $companyUuid): bool
    {
        return $companyUuid
            && $lugar->company_uuid === $companyUuid
            && $lugar->owner_uuid === $vendor->uuid
            && $lugar->owner_type === Utils::getMutationType($vendor);
    }

    protected function temCoordenadas(Place $lugar): bool
    {
        return $lugar->location instanceof Point && $this->coordenadaValida($lugar->location->getLat(), $lugar->location->getLng());
    }

    /** Distância em metros entre dois pontos (haversine, raio médio da Terra). */
    protected function metrosEntre(Point $a, Point $b): float
    {
        $dLat = deg2rad($b->getLat() - $a->getLat());
        $dLng = deg2rad($b->getLng() - $a->getLng());
        $h    = sin($dLat / 2) ** 2 + cos(deg2rad($a->getLat())) * cos(deg2rad($b->getLat())) * sin($dLng / 2) ** 2;

        return 2 * 6371000 * asin(min(1, sqrt($h)));
    }

    protected function coordenadaValida($lat, $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng)
            && abs((float) $lat) <= 90 && abs((float) $lng) <= 180
            && (abs((float) $lat) > 0.0001 || abs((float) $lng) > 0.0001);
    }

    protected function entrada(Request $request): ParameterBag
    {
        return $request->isJson() ? $request->json() : $request->request;
    }

    /** Valor do corpo como veio (objeto/lista inclusive): o InputBag::get() lança 400 nesses casos. */
    protected function campo(ParameterBag $entrada, string $nome)
    {
        return $entrada->all()[$nome] ?? null;
    }

    /** Texto de um campo (o PlaceController recusa o que não for string, então o resto não chega ao firstOrNew). */
    protected function texto($valor): string
    {
        return is_string($valor) ? $valor : '';
    }

    protected function erro(int $status, string $mensagem)
    {
        return response()->json(['errors' => [$mensagem]], $status);
    }
}
