<?php

namespace App\Support\Entregas;

use Fleetbase\Support\SocketCluster\SocketClusterService;

/**
 * Entregas RestaurantePro: transmite um evento no socket e diz se deu certo.
 *
 * O broadcast() do Laravel não serve para o aviso que não pode se perder: o SocketClusterBroadcaster do Fleetbase
 * chama SocketClusterService::send() e ignora o retorno, e o send() captura toda exceção, guarda a mensagem em
 * `error` e devolve false. Com o socket fora do ar, o broadcast() parece ter funcionado e a falha some sem log. Aqui o
 * retorno é conferido e a mensagem de erro volta para quem chamou, que registra no log e tenta de novo.
 *
 * O corpo enviado é o broadcastWith() do evento: o SocketCluster ignora o broadcastAs() e o console lê o `event` de
 * dentro do corpo. É o mesmo que o BroadcastEvent do Laravel manda.
 *
 * Ao atualizar o core-api, confira se o SocketClusterService ainda tem send($canal, array $dados): bool e error().
 */
class TransmissaoNoSocket
{
    /**
     * Envia o evento a cada canal de broadcastOn(). Devolve null se todos os envios deram certo, ou a mensagem de erro
     * do primeiro que falhou. Nunca lança.
     */
    public static function enviar(object $evento): ?string
    {
        try {
            $dados = $evento->broadcastWith();

            foreach ($evento->broadcastOn() as $canal) {
                // uma instância por envio, como o broadcaster do Fleetbase faz: cada uma abre a própria conexão
                $servico = new SocketClusterService();

                if (!$servico->send($canal->name, $dados)) {
                    return $servico->error() ?: 'falha sem mensagem';
                }
            }
        } catch (\Throwable $e) {
            return $e->getMessage() ?: 'falha sem mensagem';
        }

        return null;
    }
}
