<?php

namespace App\Support\Entregas;

use App\Notifications\Entregas\PedidoPassadoParaOutro;
use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuidor;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a troca do motoboy de um pedido, pelo líder na aba Mapa do app (LiderController@trocarMotoboy)
 * e pela central no console (PainelInicialController@atribuir: Atribuir e Trocar).
 *
 * Antes da coleta, com a distribuição ligada (decisão do Edgard de 2026-10-28, spec
 * docs/superpowers/specs/2026-10-28-troca-com-aceite-e-buzina-design.md): o escolhido precisa aceitar. Com a TravaDoPedido
 * (a mesma do aceite e do cancelamento), relê o pedido e o devolve à fila (sem motoboy, aberto, não iniciado,
 * `dispatched`, num save só: os observadores toleram, nada vai ao iFood e a atribuição do valor congelado é limpa) e,
 * ainda dentro da trava, o Distribuidor cria a distribuição com a primeira oferta ao escolhido (o cartão "Oferta para
 * você"); recusou ou venceu, segue a fila de sempre. Fora da trava haveria um instante com o pedido aberto e sem
 * distribuição, com o aceite livre a qualquer um.
 *
 * Depois da coleta (status de SituacaoDoMotoboy::STATUS_EM_ENTREGA) ou com a distribuição desligada: a troca direta do
 * console (o pedido fica com o status que tinha), que desliga o pedido aberto (adhoc falso: com ele ligado, o
 * HandleOrderDriverAssigned do Fleet-Ops não avisa o motoboy novo e o reenvio do pedido aberto continuaria) e chama o
 * assignDriver do Order em modo silencioso. O save dele dispara o OrderObserver do Fleet-Ops, que dispara o
 * OrderDriverAssigned uma vez só (o assignDriver sem o silencioso dispara o evento antes do save, e o observer de novo
 * depois: o motoboy novo receberia dois avisos). O OrderDriverAssigned leva o push "Novo pedido para você" ao motoboy novo
 * (AvisosDoMotoboy) e, no pedido iFood, o ObservadorDosPedidosIfood manda o assignDriver ao iFood.
 *
 * Nos dois casos, o motoboy anterior recebe o PedidoPassadoParaOutro.
 */
class TrocaDoMotoboy
{
    /**
     * @return array{0: int, 1: array} status HTTP e corpo da resposta ({"pedido": …} ou {"errors": […]})
     */
    public static function trocar(string $empresaUuid, string $pedidoId, string $motoboyId, string $liderUuid): array
    {
        $pedido = static::pedido($empresaUuid, $pedidoId);
        if (!$pedido) {
            return [404, ['errors' => ['Pedido não encontrado.']]];
        }
        if (static::encerrado($pedido)) {
            return [409, ['errors' => ['Este pedido já foi encerrado.']]];
        }
        if (static::naoDespachado($pedido)) {
            return [409, ['errors' => ['Este pedido ainda não foi despachado pela central.']]];
        }

        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $novo = $motoboyId === '' ? null : Driver::where('company_uuid', $empresaUuid)->where('public_id', $motoboyId)->first();
        if (!$novo) {
            return [422, ['errors' => ['Escolha um motoboy da lista.']]];
        }

        try {
            [$resultado, $anteriorUuid, $oferta] = TravaDoPedido::executar((string) $pedido->uuid, fn () => static::trocarComATrava($empresaUuid, (string) $pedido->uuid, $novo));
        } catch (LockTimeoutException) {
            return [409, ['errors' => ['Outra pessoa está mexendo neste pedido. Tente de novo.']]];
        }

        if ($resultado === 'sumiu') {
            return [404, ['errors' => ['Pedido não encontrado.']]];
        }
        if ($resultado === 'encerrado') {
            return [409, ['errors' => ['Este pedido já foi encerrado.']]];
        }
        if ($resultado === 'nao_despachado') {
            return [409, ['errors' => ['Este pedido ainda não foi despachado pela central.']]];
        }

        $atualizado = static::pedido($empresaUuid, (string) $pedido->uuid, PedidosNoMapa::RELACOES_DO_LIDER) ?? $pedido;
        if ($resultado === 'trocado') {
            $anterior = static::avisarAnterior($empresaUuid, $anteriorUuid, $atualizado);
            Log::info('[entregas] líder trocou o motoboy', [
                'pedido'   => $atualizado->public_id,
                'anterior' => $anterior,
                'novo'     => $novo->public_id,
                'lider'    => $liderUuid,
            ]);
        }
        if ($resultado === 'oferecido') {
            $anterior = static::avisarAnterior($empresaUuid, $anteriorUuid, $atualizado);
            Log::info('[entregas] troca do motoboy: oferta ao escolhido', [
                'pedido'   => $atualizado->public_id,
                'anterior' => $anterior,
                'novo'     => $novo->public_id,
                'lider'    => $liderUuid,
                'oferta'   => $oferta?->id,
            ]);
        }

        return [200, ['pedido' => PedidosNoMapa::umDoLider($atualizado), 'oferta' => static::ofertaNaResposta($oferta, $novo)]];
    }

    /**
     * Dentro da trava: relê o pedido (outro líder, o aceite ou o cancelamento podem ter mudado) e troca: antes da coleta,
     * com a distribuição ligada, devolve à fila e oferece ao escolhido; senão, a troca direta.
     *
     * @return array{0: string, 1: ?string, 2: ?object} resultado (sumiu, encerrado, nao_despachado, igual, trocado,
     *                                                   oferecido), o uuid do motoboy anterior e a oferta criada
     */
    protected static function trocarComATrava(string $empresaUuid, string $pedidoUuid, Driver $novo): array
    {
        $pedido = static::pedido($empresaUuid, $pedidoUuid);
        if (!$pedido) {
            return ['sumiu', null, null];
        }
        if (static::encerrado($pedido)) {
            return ['encerrado', null, null];
        }
        if (static::naoDespachado($pedido)) {
            return ['nao_despachado', null, null];
        }
        if ((string) $pedido->driver_assigned_uuid === (string) $novo->uuid) {
            return ['igual', null, null];
        }

        $anterior = $pedido->driver_assigned_uuid ?: null;
        if (static::vaiPorOferta($pedido)) {
            static::devolverAFila($pedido);
            static::limparPedidoAtualDoAnterior($empresaUuid, $anterior, $pedidoUuid);
            // sem a trava de novo (não é reentrante): o Distribuidor grava sem tomá-la; nunca lança
            $oferta = app(Distribuidor::class)->oferecerAoEscolhidoSemTrava($pedido, $novo);

            return ['oferecido', $anterior, $oferta];
        }

        $pedido->adhoc = false;
        $pedido->assignDriver($novo, true);
        static::limparPedidoAtualDoAnterior($empresaUuid, $anterior, $pedidoUuid);

        return ['trocado', $anterior, null];
    }

    /** O escolhido precisa aceitar: a distribuição ligada e o pedido ainda não saiu da loja. */
    protected static function vaiPorOferta($pedido): bool
    {
        return Distribuicao::ligada() && !in_array(strtolower((string) $pedido->status), SituacaoDoMotoboy::STATUS_EM_ENTREGA, true);
    }

    /**
     * O pedido volta a ser aberto, sem motoboy e não iniciado, num save só (com eventos: o socket, os caches e o valor
     * congelado ficam sabendo; nenhum OrderDriverAssigned sai com motoboy nulo). O dispatched_at passa a ser agora, como
     * num despacho novo: o ReenviarPedidosAbertos conta do dispatched_at o reenvio aos motoboys e o aviso "sem motoboy"
     * à central (12 min depois, uma vez por despacho); com o antigo, a central levaria logo um falso "sem motoboy" (e
     * gastaria o único aviso) ou, com o despacho de mais de 60 min, nunca seria avisada. O startOrder do próximo aceite só exige o
     * dispatched_at preenchido, e a linha do tempo ganha um segundo "iniciado".
     */
    protected static function devolverAFila($pedido): void
    {
        $pedido->driver_assigned_uuid = null;
        $pedido->adhoc                = true;
        $pedido->started              = false;
        $pedido->started_at           = null;
        $pedido->status               = 'dispatched';
        $pedido->dispatched_at        = now();
        $pedido->save();
    }

    /** A oferta na resposta (console e líder mostram "Oferta enviada a Fulano"): o escolhido e quando vence (ISO, -03:00). */
    protected static function ofertaNaResposta(?object $oferta, Driver $novo): ?array
    {
        if (!$oferta) {
            return null;
        }

        return [
            'motoboy'  => (string) $novo->public_id,
            'nome'     => (string) $novo->name,
            'vence_em' => (new \DateTimeImmutable(substr((string) $oferta->vence_em, 0, 19)))->format('Y-m-d\TH:i:sP'),
        ];
    }

    /**
     * O pedido que o anterior aceitou fica como o "pedido atual" dele (drivers.current_job_uuid, gravado no aceite): sem
     * limpar, o mapa e as métricas do Fleet-Ops (motoboy ocupado) seguiriam contando o pedido que já passou para outro.
     * Como o console ao desatribuir (Internal\DriverController): só se o atual é este pedido. Não desfaz a troca se falhar.
     */
    protected static function limparPedidoAtualDoAnterior(string $empresaUuid, ?string $anteriorUuid, string $pedidoUuid): void
    {
        if (!$anteriorUuid) {
            return;
        }

        try {
            $anterior = Driver::where('company_uuid', $empresaUuid)->where('uuid', $anteriorUuid)->first();
            if ($anterior && (string) $anterior->current_job_uuid === $pedidoUuid) {
                $anterior->update(['current_job_uuid' => null]);
            }
        } catch (\Throwable $erro) {
            Log::warning('[entregas] líder trocou o motoboy, mas o pedido atual do anterior não foi limpo', ['pedido' => $pedidoUuid, 'erro' => get_class($erro)]);
        }
    }

    /** O push ao motoboy anterior; uma falha aqui não desfaz a troca (fica no log). Devolve o public_id dele. */
    protected static function avisarAnterior(string $empresaUuid, ?string $anteriorUuid, $pedido): ?string
    {
        if (!$anteriorUuid) {
            return null;
        }

        $anterior = Driver::where('company_uuid', $empresaUuid)->where('uuid', $anteriorUuid)->first();
        if (!$anterior) {
            return null;
        }

        try {
            $anterior->notify(new PedidoPassadoParaOutro(static::numero($pedido), (string) $pedido->public_id));
        } catch (\Throwable $erro) {
            Log::warning('[entregas] líder trocou o motoboy, mas o aviso ao anterior falhou', ['pedido' => $pedido->public_id, 'erro' => get_class($erro)]);
        }

        return $anterior->public_id;
    }

    /** O número do pedido no aviso: o do iFood (internal_id); sem ele, o de rastreio; sem os dois, o public_id. */
    protected static function numero($pedido): string
    {
        return (string) ($pedido->internal_id ?: ($pedido->trackingNumber?->tracking_number ?: $pedido->public_id));
    }

    /** Pedido da empresa pelo public_id ou pelo uuid. */
    protected static function pedido(string $empresaUuid, string $id, array $relacoes = [])
    {
        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        return Order::where('company_uuid', $empresaUuid)
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->with($relacoes)
            ->first();
    }

    /**
     * Pedido que a central ainda não despachou (status created e dispatched falso: iFood "[SEM LOCALIZAÇÃO]", agendado antes
     * da hora, pedido criado sem despacho): o startOrder o recusaria ("has not been dispatched"), e o motoboy receberia o
     * alarme à toa. A central despacha primeiro.
     */
    protected static function naoDespachado($pedido): bool
    {
        return $pedido->status === 'created' && !$pedido->dispatched;
    }

    protected static function encerrado($pedido): bool
    {
        return in_array($pedido->status, StatusDoPedido::ENCERRADOS, true);
    }
}
