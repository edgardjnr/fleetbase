<?php

namespace App\Notifications\Entregas;

use Fleetbase\FleetOps\Notifications\OrderAssigned;
use Fleetbase\FleetOps\Notifications\OrderCanceled;
use Fleetbase\FleetOps\Notifications\OrderCompleted;
use Fleetbase\FleetOps\Notifications\OrderDispatched;
use Fleetbase\FleetOps\Notifications\OrderFailed;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Notifications\WaypointCompleted;
use Fleetbase\Notifications\ChatMessageReceived;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\CartaoDoAlarme;
use App\Support\Entregas\Ifood\CancelamentoPeloIfood;
use App\Support\Entregas\Ifood\PedidosIfood;
use Fleetbase\Notifications\TestPushNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as NotificacaoFcm;

/**
 * Entregas RestaurantePro: o push que o motoboy recebe, em pt-BR e no formato que o app trata. O CanalFcmEntregas
 * passa todo push por aqui.
 *
 * - Texto: os avisos do Fleet-Ops e do core saem em inglês; cada classe conhecida ganha título e texto em pt-BR. O
 *   código do pedido vem do título original ("Order X …" / "New order X …"); sem ele, a frase sai sem o código. Classe
 *   sem tradução segue como veio e fica registrada no log.
 * - Canal: cada tipo vai para um canal do app (alarme, mensagens, avisos). O APK sem o canal usa o padrão "pedidos".
 * - Alarme (pedido novo, reenvio, atribuído, liberado): vira push de dados de alta prioridade, e o app (APK 16+) toca o
 *   alarme em loop com o cartão em tela cheia (AlarmePedidoActivity): acende a tela com o celular bloqueado e, com a
 *   permissão de sobrepor, abre por cima do app em uso. Os dados levam o cartão do pedido (CartaoDoAlarme: loja,
 *   destino, km e valor do motoboy). Ligado por padrão desde 2026-10-04 (antes era preciso ENTREGAS_ALARME_POR_DADOS=1;
 *   sem ele, o push comum do Android não acende a tela com o app fechado). ENTREGAS_ALARME_POR_DADOS=0 volta ao push
 *   comum no canal de alarme. Nos dois casos o aviso vale só por 15 min (VALIDADE_ALARME). No APK anterior ao 16, tocar
 *   no push de dados não abre o pedido.
 */
class AvisosDoMotoboy
{
    /** Tipos (data.type) que tocam o alarme de pedido no app. */
    public const TIPOS_DE_ALARME = ['order_ping', 'order_assigned', 'order_dispatched'];

    /** Canal do app por tipo de aviso (criados no MainApplication do app). */
    public const CANAIS = [
        'order_ping'            => 'alarme_pedido',
        'order_assigned'        => 'alarme_pedido',
        'order_dispatched'      => 'alarme_pedido',
        'chat_message_received' => 'mensagens',
        'order_canceled'        => 'avisos',
        'order_failed'          => 'avisos', // se o Fleet-Ops corrigir o tipo do OrderFailed (hoje manda order_canceled)
        'order_completed'       => 'avisos',
        'waypoint_completed'    => 'avisos',
        'test'                  => 'avisos',
    ];

    /** Canal do push de dados no APK antigo (a biblioteca de push mostra nele; o APK 16 usa o canal do alarme). */
    public const CANAL_PADRAO = 'pedidos';

    /**
     * Validade de todo push de alarme, de dados ou comum: o FCM descarta depois disso, e um alarme velho não toca quando
     * o celular volta a ter rede (no APK 16 o canal de alarme toca até no silencioso).
     */
    public const VALIDADE_ALARME = '900s';

    /**
     * Quem monta os dados do cartão do pedido (fn (Notification): array). Nulo = CartaoDoAlarme com o pedido da
     * notificação; o teste troca por dados fixos (o cálculo real usa o banco e o OSRM).
     */
    public static ?\Closure $cartao = null;

    /**
     * Adapta o push montado pela notificação: texto em pt-BR, canal e formato. Altera e devolve a mesma instância (o
     * CanalFcmEntregas passa uma cópia da mensagem e, se a adaptação falhar, envia a original).
     */
    public static function adaptar(Notification $notificacao, FcmMessage $mensagem): FcmMessage
    {
        $tipo  = (string) ($mensagem->data['type'] ?? '');
        $texto = static::texto($notificacao);

        if ($texto === null) {
            Log::warning('[entregas] aviso push sem tradução', ['notificacao' => get_class($notificacao), 'tipo' => $tipo]);

            return $mensagem;
        }

        [$titulo, $corpo] = $texto;

        if (in_array($tipo, self::TIPOS_DE_ALARME, true) && static::alarmePorDados()) {
            return static::comoDados($mensagem, $titulo, $corpo, static::cartao($notificacao));
        }

        $mensagem->notification = new NotificacaoFcm(title: $titulo, body: $corpo);

        if (isset(self::CANAIS[$tipo])) {
            $mensagem->custom['android']['notification']['channel_id'] = self::CANAIS[$tipo];
        }

        // alarme comum também vence em 15 min: um pedido velho não pode tocar horas depois, quando o celular volta
        if (in_array($tipo, self::TIPOS_DE_ALARME, true)) {
            $mensagem->custom['android']['ttl'] = self::VALIDADE_ALARME;
        }

        return $mensagem;
    }

    /**
     * Título e texto em pt-BR do aviso, ou null se a classe não tem tradução.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function texto(Notification $notificacao): ?array
    {
        $codigo = static::codigo((string) ($notificacao->title ?? ''));

        return match (true) {
            $notificacao instanceof LembretePedidoAberto => [$notificacao->title, $notificacao->message],
            $notificacao instanceof OrderPing            => ['Novo pedido disponível', static::textoDaColeta($notificacao->distance)],
            $notificacao instanceof OrderAssigned        => ['Novo pedido para você', static::textoDoAtribuido($notificacao, $codigo)],
            $notificacao instanceof OrderDispatched      => [static::comCodigo('Pedido %s liberado para você', 'Pedido liberado para você', $codigo), 'Toque para ver e iniciar a entrega.'],
            $notificacao instanceof OrderFailed          => [static::comCodigo('Entrega do pedido %s não concluída', 'Entrega não concluída', $codigo), static::comCodigo('A entrega do pedido %s falhou.', 'A entrega do pedido falhou.', $codigo)],
            $notificacao instanceof OrderCanceled        => static::canceladoPeloIfood($notificacao) ?? [static::comCodigo('Pedido %s cancelado', 'Pedido cancelado', $codigo), static::comCodigo('O pedido %s foi cancelado.', 'O pedido foi cancelado.', $codigo)],
            $notificacao instanceof OrderCompleted       => [static::comCodigo('Pedido %s concluído', 'Pedido concluído', $codigo), static::comCodigo('O pedido %s foi concluído.', 'O pedido foi concluído.', $codigo)],
            $notificacao instanceof WaypointCompleted    => [static::comCodigo('Pedido %s: parada concluída', 'Parada concluída', $codigo), static::comCodigo('Uma parada do pedido %s foi concluída.', 'Uma parada do pedido foi concluída.', $codigo)],
            $notificacao instanceof ChatMessageReceived  => [static::tituloDoChat((string) $notificacao->title), (string) $notificacao->message],
            $notificacao instanceof TestPushNotification => [(string) $notificacao->title, (string) $notificacao->message],
            default                                      => null,
        };
    }

    /**
     * Pedido iFood cancelado pelo iFood (CAN): "Pedido #4821 cancelado pelo iFood", e "Você recebe por esta entrega…"
     * quando o dispatch já tinha saído (CancelamentoPeloIfood::textoDoPush). null para os outros cancelamentos, ou se a
     * consulta falhar (sai o texto comum).
     */
    protected static function canceladoPeloIfood(Notification $notificacao): ?array
    {
        try {
            $uuid  = $notificacao->order->uuid ?? null;
            $linha = $uuid ? PedidosIfood::doPedido((string) $uuid) : null;

            return $linha ? CancelamentoPeloIfood::textoDoPush($linha) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** "Coleta a 1,2 km de você. Toque para ver o pedido." (sem distância, só o convite). */
    public static function textoDaColeta($distancia): string
    {
        $metros = (int) round((float) $distancia);
        if ($metros < 1) {
            return 'Toque para ver o pedido.';
        }

        $texto = $metros < 1000 ? $metros . ' m' : number_format($metros / 1000, 1, ',', '.') . ' km';

        return 'Coleta a ' . $texto . ' de você. Toque para ver o pedido.';
    }

    /** O alarme vai como push de dados, a não ser com ENTREGAS_ALARME_POR_DADOS desligada (0, false ou off). Vazia = ligada. */
    public static function alarmePorDados(): bool
    {
        $valor = getenv('ENTREGAS_ALARME_POR_DADOS');

        return $valor === false || !in_array(strtolower(trim($valor)), ['0', 'false', 'off'], true);
    }

    /**
     * Os dados do cartão do pedido para o alarme. Uma falha (banco, OSRM) não impede o alarme: vai sem o cartão, e o app
     * mostra só o título e o texto.
     */
    protected static function cartao(Notification $notificacao): array
    {
        try {
            if (static::$cartao) {
                return (static::$cartao)($notificacao);
            }

            $pedido = $notificacao->order ?? null;

            return $pedido ? CartaoDoAlarme::doPedido($pedido, app(CalculoEntregas::class)) : [];
        } catch (\Throwable $erro) {
            Log::warning('[entregas] alarme sem o cartão do pedido', ['notificacao' => get_class($notificacao), 'erro' => $erro->getMessage()]);

            return [];
        }
    }

    /** O código de rastreamento que o Fleet-Ops põe no título original ("Order X …" / "New order X …"). */
    protected static function codigo(string $tituloOriginal): ?string
    {
        return preg_match('/^(?:New order|Order) (\S+)/', $tituloOriginal, $achado) ? $achado[1] : null;
    }

    protected static function comCodigo(string $comCodigo, string $semCodigo, ?string $codigo): string
    {
        return $codigo === null ? $semCodigo : str_replace('%s', $codigo, $comCodigo);
    }

    protected static function textoDoAtribuido(OrderAssigned $notificacao, ?string $codigo): string
    {
        $agendado = str_starts_with((string) $notificacao->message, 'You have a new order scheduled for');
        $quando   = $agendado ? static::quandoAgendado($notificacao) : null;

        if ($quando !== null) {
            return static::comCodigo('Pedido %s agendado para ' . $quando . '.', 'Pedido agendado para ' . $quando . '.', $codigo);
        }

        return static::comCodigo('Pedido %s. Toque para ver os detalhes.', 'Toque para ver os detalhes.', $codigo);
    }

    /** "03/10 às 15:00", no fuso da organização do pedido (America/Sao_Paulo se faltar). */
    protected static function quandoAgendado(OrderAssigned $notificacao): ?string
    {
        try {
            $agendado = $notificacao->order?->scheduled_at;
            if (!$agendado instanceof \DateTimeInterface) {
                return null;
            }

            $fuso  = $notificacao->order?->company?->timezone ?: 'America/Sao_Paulo';
            $local = \DateTimeImmutable::createFromInterface($agendado)->setTimezone(new \DateTimeZone($fuso));

            return $local->format('d/m') . ' às ' . $local->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function tituloDoChat(string $tituloOriginal): string
    {
        $prefixo = 'Message from ';

        return str_starts_with($tituloOriginal, $prefixo) ? 'Mensagem de ' . substr($tituloOriginal, strlen($prefixo)) : 'Nova mensagem';
    }

    /**
     * Push de dados: sem bloco de notificação (o app monta a notificação), título e texto nos dados, todos os dados como
     * texto (exigência do FCM), prioridade alta e validade curta.
     */
    protected static function comoDados(FcmMessage $mensagem, string $titulo, string $corpo, array $cartao = []): FcmMessage
    {
        $dados = [];
        foreach ((array) $mensagem->data as $chave => $valor) {
            if ($valor !== null) {
                $dados[$chave] = match (true) {
                    is_scalar($valor)              => (string) $valor,
                    $valor instanceof \Stringable  => (string) $valor,
                    default                        => json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '',
                };
            }
        }

        $mensagem->data         = array_merge($dados, $cartao, ['title' => $titulo, 'body' => $corpo, 'android_channel_id' => self::CANAL_PADRAO]);
        $mensagem->notification = null;

        $android = (array) ($mensagem->custom['android'] ?? []);
        unset($android['notification']);
        $mensagem->custom['android'] = array_merge($android, ['priority' => 'high', 'ttl' => self::VALIDADE_ALARME]);

        return $mensagem;
    }
}
