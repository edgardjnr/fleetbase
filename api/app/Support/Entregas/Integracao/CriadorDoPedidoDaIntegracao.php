<?php

namespace App\Support\Entregas\Integracao;

use App\Support\Entregas\Coordenada;
use App\Support\Entregas\Ifood\PedidoDoIfood;
use App\Support\Entregas\ItensDoPedido;
use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: cria no Fleetbase o pedido que veio de outro sistema e o despacha aos motoboys, no molde do
 * CriadorDoPedidoIfood:
 * - cliente = a Loja (Vendor) da chave; coleta = o Local da loja (dono = o Vendor, coordenadas válidas);
 * - entrega = Place novo com as coordenadas recebidas (ou do Google, como reserva), sem dono e sem o telefone do cliente
 *   (o telefone fica na entregas_integracao_pedidos: o app só o mostra ao motoboy do pedido em andamento);
 * - Order tipo transport, internal_id = número da origem, notas "RestaurantePro #4821" + as observações;
 * - a linha da entregas_integracao_pedidos na mesma transação: (vendor_uuid, pedido_externo_id) único impede um segundo
 *   pedido para o mesmo pedido da origem (o reenvio devolve o mesmo, sem atualizar nada, nem os `itens`);
 * - os produtos (`itens`, já normalizados pelo PedidoDaIntegracao) vão na linha em JSON (ItensDoPedido::paraOBanco);
 * - ponto ausente, inválido (0,0) ou a mais de 50 km da coleta: tenta o Google uma vez; se ele também não servir, nasce
 *   sem despacho, adhoc falso, "[SEM LOCALIZAÇÃO]" nas notas
 *   e a entrega ~1 km ao norte da loja (a regra do iFood); a central atribui;
 * - despacho como o do iFood e do portal: adhoc + firstDispatchWithActivity, com a TravaDoPedido e o pedido relido, o
 *   que dispara a distribuição. Falhou (trava ocupada, erro): o entregas:integracao-acompanhar refaz em 1 min.
 */
class CriadorDoPedidoDaIntegracao
{
    public const DISTANCIA_MAXIMA_METROS = 50000;

    /** ~1 km ao norte da coleta, em graus de latitude (a entrega do pedido sem localização). */
    public const DESLOCAMENTO_SEM_LOCALIZACAO = 0.009;

    public function __construct(protected GeocodificadorDaIntegracao $geocodificador) {}

    /**
     * @return array{0: object, 1: bool} a linha da entregas_integracao_pedidos e se o pedido nasceu agora (false = repetido)
     *
     * @throws ErroDaIntegracao 409 se a loja não tem Local de coleta válido
     */
    public function criar(object $loja, array $dados): array
    {
        if ($repetido = $this->existente($loja, $dados['pedido_externo_id'])) {
            Log::info('[entregas] integração: pedido repetido', ['vendor' => $loja->vendor_uuid, 'pedido_externo' => $dados['pedido_externo_id']]);

            return [$repetido, false];
        }

        $vendor = Vendor::where('uuid', $loja->vendor_uuid)->where('company_uuid', $loja->company_uuid)->first();
        $coleta = $vendor && $vendor->place_uuid ? Place::where('uuid', $vendor->place_uuid)->first() : null;
        if (!$vendor || !$coleta || !$this->coletaValida($coleta, $vendor, (string) $loja->company_uuid)) {
            Log::error('[entregas] integração: loja sem local de coleta válido; pedido não criado', ['vendor' => $loja->vendor_uuid]);
            throw new ErroDaIntegracao(409, 'A loja não tem local de coleta válido no Entregas. Fale com a central.');
        }

        [$latitude, $longitude, $semLocalizacao, $motivo] = $this->destino($dados, $coleta);
        $sistema                                         = Integracao::nomeDoSistema($loja->sistema ?? null);

        // o TrackingNumberObserver e o OrderConfig::default() leem a empresa da sessão
        session(['company' => $loja->company_uuid]);

        try {
            $pedido = DB::transaction(fn () => $this->gravar($loja, $vendor, $coleta, $dados, $sistema, $latitude, $longitude, $semLocalizacao));
        } catch (QueryException $e) {
            // o mesmo pedido chegou duas vezes no mesmo instante: a chave única segurou o segundo, que devolve o primeiro
            if ((string) $e->getCode() === '23000' && ($repetido = $this->existente($loja, $dados['pedido_externo_id']))) {
                Log::info('[entregas] integração: pedido repetido', ['vendor' => $loja->vendor_uuid, 'pedido_externo' => $dados['pedido_externo_id'], 'corrida' => true]);

                return [$repetido, false];
            }
            throw $e;
        }

        Log::info('[entregas] integração: pedido criado', ['pedido' => $pedido->public_id, 'sistema' => $sistema, 'sem_localizacao' => $semLocalizacao]);

        if ($semLocalizacao) {
            Log::warning('[entregas] integração: pedido sem localização; a central atribui', ['pedido' => $pedido->public_id, 'motivo' => $motivo]);
        } else {
            $this->despachar($pedido);
        }

        return [PedidosDaIntegracao::doPedido((string) $pedido->uuid), true];
    }

    /**
     * Pedido aberto aos motoboys (a distribuição), com a trava do pedido e o pedido relido. Já despachado: só marca o
     * despachado_em. Com motoboy atribuído pela central: despacha só para ele (adhoc falso). Apagado, encerrado ou aceito:
     * não despacha. Devolve se despachou (ou já estava).
     */
    public function despachar(Order $pedido): bool
    {
        try {
            return TravaDoPedido::executar((string) $pedido->uuid, fn () => $this->despacharComATrava($pedido));
        } catch (LockTimeoutException $e) {
            Log::warning('[entregas] integração: trava do pedido ocupada; despacho fica para o acompanhamento', ['pedido' => $pedido->public_id]);

            return false;
        } catch (\Throwable $e) {
            Log::error('[entregas] integração: falha ao despachar o pedido', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);

            return false;
        }
    }

    protected function despacharComATrava(Order $pedido): bool
    {
        session(['company' => $pedido->company_uuid]);

        $atual = $pedido->fresh();
        if (!$atual || $atual->deleted_at || in_array($atual->status, StatusDoPedido::ENCERRADOS, true) || $atual->started) {
            return false;
        }

        if (!$atual->dispatched) {
            // sem eventos: o despacho logo abaixo grava de novo e emite os eventos (OrderDispatched → distribuição)
            $atual->adhoc = !$atual->driver_assigned_uuid;
            $atual->saveQuietly();
            $atual->firstDispatchWithActivity();
        }

        $agora = now()->toDateTimeString();
        DB::table(Integracao::PEDIDOS)->where('order_uuid', $pedido->uuid)->update(['despachado_em' => $agora, 'updated_at' => $agora]);

        return true;
    }

    public static function notas(string $sistema, array $dados, bool $semLocalizacao): string
    {
        $notas = "{$sistema} #{$dados['numero']}" . ($semLocalizacao ? ' [SEM LOCALIZAÇÃO]' : '');

        return $dados['observacoes'] ? $notas . "\n" . $dados['observacoes'] : $notas;
    }

    protected function gravar(object $loja, Vendor $vendor, Place $coleta, array $dados, string $sistema, float $latitude, float $longitude, bool $semLocalizacao): Order
    {
        // mb_strtoupper: o PlaceObserver só faz strtoupper (ASCII) e gravaria "RIBEIRãO"
        $maiusculo = fn (?string $texto) => $texto === null ? null : mb_strtoupper($texto, 'UTF-8');
        $destino   = Place::create([
            'company_uuid' => $loja->company_uuid,
            'name'         => $maiusculo($dados['cliente_nome']),
            'street1'      => $maiusculo($dados['endereco']),
            'street2'      => $maiusculo($dados['complemento']),
            'neighborhood' => $maiusculo($dados['bairro']),
            'city'         => $maiusculo($dados['cidade']),
            'province'     => $dados['uf'],
            'postal_code'  => $dados['cep'],
            'country'      => 'BR',
            'location'     => new Point($latitude, $longitude),
        ]);

        $payload               = new Payload();
        $payload->company_uuid = $loja->company_uuid;
        $payload->setPickup($coleta, [
            'callback' => function ($pickup, $payload) {
                $payload->setCurrentWaypoint($pickup);
            },
        ]);
        $payload->setDropoff($destino);
        $payload->save();

        $config = OrderConfig::default() ?? OrderConfig::where('company_uuid', $loja->company_uuid)->first();
        if (!$config) {
            throw new \RuntimeException('a empresa não tem tipo de pedido (OrderConfig)');
        }

        $pedido = Order::create([
            'company_uuid'      => $loja->company_uuid,
            'customer_uuid'     => $vendor->uuid,
            'customer_type'     => Utils::getMutationType($vendor),
            'payload_uuid'      => $payload->uuid,
            'order_config_uuid' => $config->uuid,
            'type'              => $config->key,
            'status'            => 'created',
            'adhoc'             => !$semLocalizacao,
            'internal_id'       => $dados['numero'],
            'notes'             => static::notas($sistema, $dados, $semLocalizacao),
        ]);

        $agora = now()->toDateTimeString();
        DB::table(Integracao::PEDIDOS)->insert([
            'company_uuid'        => $loja->company_uuid,
            'vendor_uuid'         => $vendor->uuid,
            'order_uuid'          => $pedido->uuid,
            'pedido_externo_id'   => $dados['pedido_externo_id'],
            'sistema'             => $sistema,
            'numero'              => $dados['numero'],
            'cliente_nome'        => $dados['cliente_nome'],
            'cliente_telefone'    => $dados['cliente_telefone'],
            'complemento'         => $dados['complemento'],
            'referencia'          => $dados['referencia'],
            'observacoes'         => $dados['observacoes'],
            'cobranca_forma'      => $dados['cobranca_forma'],
            'cobranca_centavos'   => $dados['cobranca_centavos'],
            'troco_para_centavos' => $dados['troco_para_centavos'],
            'itens'               => ItensDoPedido::paraOBanco($dados['itens'] ?? null),
            'sem_localizacao'     => $semLocalizacao,
            'created_at'          => $agora,
            'updated_at'          => $agora,
        ]);

        return $pedido;
    }

    /**
     * Coordenada recebida ausente, inválida (0,0, fora da faixa) ou a mais de 50 km da coleta: tenta o Google uma vez (a
     * origem pode mandar 0,0 quando a geocodificação dela falha). Se ele também não servir, fica sem localização.
     *
     * @return array{0: float, 1: float, 2: bool, 3: ?string} latitude, longitude, se ficou sem localização e o motivo
     *                                                       (sem_coordenada, coordenada_invalida, longe_da_loja ou google_sem_resultado)
     */
    protected function destino(array $dados, Place $coleta): array
    {
        $latitudeDaColeta  = (float) $coleta->location->getLat();
        $longitudeDaColeta = (float) $coleta->location->getLng();
        $serve             = fn ($lat, $lng) => $lat !== null && $lng !== null && Coordenada::valida($lat, $lng)
            && PedidoDoIfood::metrosEntre($latitudeDaColeta, $longitudeDaColeta, (float) $lat, (float) $lng) <= static::DISTANCIA_MAXIMA_METROS;

        $latitude  = $dados['latitude'];
        $longitude = $dados['longitude'];
        if ($serve($latitude, $longitude)) {
            return [(float) $latitude, (float) $longitude, false, null];
        }

        $motivo = $latitude === null || $longitude === null
            ? 'sem_coordenada'
            : (Coordenada::valida($latitude, $longitude) ? 'longe_da_loja' : 'coordenada_invalida');

        $google = $this->geocodificador->coordenadas($dados);
        if ($google !== null) {
            if ($serve($google[0], $google[1])) {
                return [(float) $google[0], (float) $google[1], false, null];
            }
            $motivo = 'google_sem_resultado';
        }

        return [$latitudeDaColeta + static::DESLOCAMENTO_SEM_LOCALIZACAO, $longitudeDaColeta, true, $motivo];
    }

    protected function existente(object $loja, string $pedidoExternoId): ?object
    {
        return DB::table(Integracao::PEDIDOS)
            ->where('company_uuid', $loja->company_uuid)
            ->where('vendor_uuid', $loja->vendor_uuid)
            ->where('pedido_externo_id', $pedidoExternoId)
            ->first();
    }

    /** Mesmo critério do portal e do iFood para a coleta: da empresa, com dono = o Vendor e com coordenadas. */
    protected function coletaValida(Place $coleta, Vendor $vendor, string $companyUuid): bool
    {
        return $companyUuid !== ''
            && $coleta->company_uuid === $companyUuid
            && $coleta->owner_uuid === $vendor->uuid
            && $coleta->owner_type === Utils::getMutationType($vendor)
            && $coleta->location instanceof Point
            && Coordenada::valida($coleta->location->getLat(), $coleta->location->getLng());
    }
}
