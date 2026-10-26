<?php

namespace App\Support\Entregas\Painel;

use App\Support\Entregas\Ifood\CobrancaIfood;
use App\Support\Entregas\Integracao\CobrancaDaIntegracao;
use App\Support\Entregas\ItensDoPedido;
use App\Support\Entregas\StatusDoPedido;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Entregas RestaurantePro: a folha do pedido no console, no estilo da Comanda do app do motoboy
 * (GET int/v1/entregas/pedidos/{id}/comanda, PedidoAbertoController@comanda). Junta numa resposta o que o app lê por
 * quatro rotas (ifood, integracao, rota e valor): cliente, cobrança, observações, produtos, loja, motoboy, linha do
 * tempo, rota no mapa, valores e a conclusão.
 *
 * Função pura sobre o Order já carregado (payload.pickup, payload.dropoff, driverAssigned, trackingNumber) e as linhas
 * que o controller leu; só o ItensDoPedido grava o warning dos itens ilegíveis (com o public_id).
 *
 * - Sem o 0800/localizador do iFood e sem o telefone do cliente da integração: só o motoboy do pedido os recebe, no app.
 * - Valores (dinheiro) só para admin; para os outros, `valores: null`.
 * - Datas em ISO de Brasília (o fuso do app; nunca UTC). Texto sem fuso do banco é lido no fuso do app.
 */
final class ComandaDaCentral
{
    public const STATUS_CONCLUIDO = ['completed', 'done'];

    /** Nome que o iFood põe no Local quando o cliente não tem nome: vale como "sem nome" (mesma regra do app). */
    public const SEM_NOME = 'cliente ifood';

    /**
     * @param object      $pedido       o Order (ou objeto com os mesmos campos e relações)
     * @param array       $noMapa       PedidosNoMapa::umDoLider($pedido, true): o mesmo item do Ao vivo e do mapa do líder
     * @param object|null $ifood        a linha da entregas_ifood_pedidos, ou null
     * @param object|null $integracao   a linha da entregas_integracao_pedidos, ou null
     * @param object|null $loja         o Vendor dono do pedido (customer_uuid) ou dono do Local de coleta, ou null
     * @param array       $statuses     linhas da tracking_statuses do pedido (code, status, details, created_at), do mais antigo ao mais novo
     * @param mixed       $finalizadaEm entregas_corridas_finalizadas.finalizada_em (texto em Brasília), ou null
     * @param array|null  $rota         RotaDoPedido::doPedido, ou null
     * @param array|null  $valor        CalculoEntregas::valorDoPedido (só admin), ou null
     */
    public static function resposta(
        object $pedido,
        array $noMapa,
        ?object $ifood,
        ?object $integracao,
        ?object $loja,
        array $statuses,
        $finalizadaEm,
        ?array $rota,
        ?array $valor,
        bool $admin,
    ): array {
        $status    = (string) ($pedido->status ?? '');
        $encerrado = in_array($status, StatusDoPedido::ENCERRADOS, true);
        $final     = in_array($status, static::STATUS_CONCLUIDO, true) ? 'concluido' : (in_array($status, StatusDoPedido::CANCELADOS, true) ? 'cancelado' : null);
        $linha     = $ifood ?? $integracao;
        $motoboy   = $pedido->driverAssigned ?? null;

        return [
            'pedido'         => $noMapa,
            'encerrado'      => $encerrado,
            'situacao_final' => $final,
            'origem'         => $ifood ? 'ifood' : ($integracao ? 'integracao' : null),
            'sistema'        => $ifood ? null : static::texto($integracao->sistema ?? null),
            'numero'         => static::texto($linha->numero ?? null) ?? (string) ($noMapa['numero'] ?? $pedido->public_id ?? ''),
            'teste'          => (bool) ($ifood->teste ?? false),
            'cliente'        => static::cliente(static::destino($pedido), $linha),
            'observacoes'    => static::texto($linha->observacoes ?? null),
            'cobranca'       => static::cobranca($ifood, $integracao),
            'itens'          => $linha ? ItensDoPedido::doBanco($linha->itens ?? null, ['pedido' => $pedido->public_id ?? null]) : null,
            'loja'           => static::loja($loja, static::coleta($pedido)),
            'motoboy'        => $motoboy ? ['id' => (string) $motoboy->public_id, 'nome' => (string) ($motoboy->name ?? '')] : null,
            'linha_do_tempo' => static::linhaDoTempo($statuses),
            'rota'           => $rota,
            'valores'        => $admin ? static::valores($valor) : null,
            'conclusao'      => $final === 'concluido' ? static::conclusao($pedido, $statuses, $finalizadaEm) : null,
        ];
    }

    /**
     * O nome do cliente cru (o Local de entrega guarda em maiúsculas; o front põe em caixa normal), ou null sem nome:
     * vazio, "Cliente iFood" ou igual à rua (um começa com o outro), a regra do app e do portal (nomeDoCliente).
     */
    public static function nomeDoCliente(?object $destino): ?string
    {
        $nome = static::texto($destino->name ?? null);
        if ($nome === null) {
            return null;
        }

        $comparavel = mb_strtolower($nome, 'UTF-8');
        if ($comparavel === static::SEM_NOME) {
            return null;
        }

        foreach ([$destino->street1 ?? null, $destino->address ?? null] as $endereco) {
            $endereco = static::texto($endereco);
            if ($endereco === null) {
                continue;
            }
            $endereco = mb_strtolower($endereco, 'UTF-8');
            // o address do Local vem como "NOME - RUA, 123, ...": o nome na frente é o do cliente, não a rua
            if (str_starts_with($endereco, $comparavel . ' - ')) {
                continue;
            }
            if ($endereco === $comparavel || str_starts_with($endereco, $comparavel) || str_starts_with($comparavel, $endereco)) {
                return null;
            }
        }

        return $nome;
    }

    protected static function cliente(?object $destino, ?object $linha): array
    {
        return [
            'nome'        => static::nomeDoCliente($destino),
            // o número já vem na street1 ("RUA X, 123"): o Local do Fleetbase não tem campo de número
            'endereco'    => static::texto($destino->street1 ?? null) ?? static::texto($destino->address ?? null),
            'bairro'      => static::texto($destino->neighborhood ?? null),
            'cidade'      => static::texto($destino->city ?? null),
            // com a linha do iFood ou da integração, o complemento e a referência separados; sem ela, o street2 do Local
            // (no iFood o street2 junta os dois, "APTO 12 · Ref.: ...", e repetiria)
            'complemento' => $linha ? static::texto($linha->complemento ?? null) : static::texto($destino->street2 ?? null),
            'referencia'  => $linha ? static::texto($linha->referencia ?? null) : null,
        ];
    }

    /** A mesma cobrança do app (CobrancaIfood / CobrancaDaIntegracao). `texto` null = nada a cobrar na entrega. */
    protected static function cobranca(?object $ifood, ?object $integracao): ?array
    {
        if ($ifood) {
            $centavos = (int) $ifood->cobrar_centavos;
            $troco    = $ifood->troco_para_centavos !== null ? (int) $ifood->troco_para_centavos : null;

            return [
                'texto'               => CobrancaIfood::texto($centavos, $ifood->forma_pagamento, $troco),
                'centavos'            => $centavos,
                'forma'               => CobrancaIfood::forma($ifood->forma_pagamento),
                'troco_para_centavos' => $troco,
            ];
        }

        if ($integracao) {
            $centavos = (int) $integracao->cobranca_centavos;
            $troco    = $integracao->troco_para_centavos !== null ? (int) $integracao->troco_para_centavos : null;

            return [
                'texto'               => CobrancaDaIntegracao::texto($integracao->cobranca_forma, $centavos, $troco),
                'centavos'            => $centavos,
                'forma'               => CobrancaDaIntegracao::forma($integracao->cobranca_forma),
                'troco_para_centavos' => $troco,
            ];
        }

        return null;
    }

    /** A loja: nome do Vendor (sem ele, o do Local de coleta), endereço do Local de coleta e o telefone. */
    protected static function loja(?object $loja, ?object $coleta): array
    {
        return [
            'nome'     => static::texto($loja->name ?? null) ?? static::texto($coleta->name ?? null),
            'endereco' => static::texto($coleta->address ?? null) ?? static::texto($coleta->street1 ?? null) ?? static::texto($loja?->place?->address ?? null),
            'telefone' => static::texto($loja->phone ?? null) ?? static::texto($coleta->phone ?? null),
        ];
    }

    protected static function linhaDoTempo(array $statuses): array
    {
        $linha = [];
        foreach ($statuses as $status) {
            $linha[] = [
                'codigo'   => static::texto($status->code ?? null),
                'texto'    => static::texto($status->status ?? null),
                'detalhes' => static::texto($status->details ?? null),
                'em'       => static::iso($status->created_at ?? null),
            ];
        }

        return $linha;
    }

    /** O valor do CalculoEntregas para a central: km, valor do motoboy, valor da loja (faixa × multiplicador da loja). */
    protected static function valores(?array $valor): array
    {
        $faixa         = is_array($valor['faixa'] ?? null) ? $valor['faixa'] : null;
        $multiplicador = is_array($valor['multiplicador'] ?? null) ? $valor['multiplicador'] : null;

        return [
            'km'            => isset($valor['km']) ? (float) $valor['km'] : null,
            'aproximado'    => ($valor['fonte'] ?? null) === 'estimativa',
            'faixa'         => $faixa ? ['de_km' => (float) $faixa['de_km'], 'ate_km' => (float) $faixa['ate_km'], 'acima' => !empty($faixa['acima'])] : null,
            'valor_motoboy' => isset($valor['valor_motoboy']) ? (float) $valor['valor_motoboy'] : null,
            // a mesma conta do ValorDaEntrega::final (o valorDoPedido devolve a faixa com o valor da loja, sem o multiplicador)
            'valor_loja'    => $faixa && isset($faixa['loja']) ? round((float) $faixa['loja'] * (float) ($multiplicador['loja'] ?? 1.0), 2) : null,
            'junto'         => ($valor['tabela'] ?? null) === 'junto' && !empty($valor['posicao']) ? (int) $valor['posicao'] : null,
            'multiplicador' => $multiplicador,
        ];
    }

    /** Quando concluiu: a corrida finalizada; sem ela, o status COMPLETED; sem ele, o updated_at (como o relatório). */
    protected static function conclusao(object $pedido, array $statuses, $finalizadaEm): array
    {
        $em = static::data($finalizadaEm);
        if ($em === null) {
            foreach (array_reverse($statuses) as $status) {
                if (strtoupper((string) ($status->code ?? '')) === 'COMPLETED') {
                    $em = static::data($status->created_at ?? null);
                    break;
                }
            }
        }
        $em ??= static::data($pedido->updated_at ?? null);

        $criado  = static::data($pedido->created_at ?? null);
        $minutos = $em && $criado && $em >= $criado ? intdiv($em->getTimestamp() - $criado->getTimestamp(), 60) : null;

        return ['em' => $em?->format(DATE_ATOM), 'minutos' => $minutos];
    }

    /** O Local de entrega (dropoff; sem ele, a última parada, como o RotaDoPedido). */
    protected static function destino(object $pedido): ?object
    {
        $payload = $pedido->payload ?? null;
        if (!is_object($payload)) {
            return null;
        }

        return $payload->dropoff ?? (method_exists($payload, 'getDropoffOrLastWaypoint') ? $payload->getDropoffOrLastWaypoint() : null);
    }

    /** O Local de coleta (pickup; sem ele, a primeira parada). */
    protected static function coleta(object $pedido): ?object
    {
        $payload = $pedido->payload ?? null;
        if (!is_object($payload)) {
            return null;
        }

        return $payload->pickup ?? (method_exists($payload, 'getPickupOrFirstWaypoint') ? $payload->getPickupOrFirstWaypoint() : null);
    }

    /** Data (objeto ou texto sem fuso do banco, lido no fuso do app) no fuso do app; null se vazia ou ilegível. */
    protected static function data($valor): ?DateTimeImmutable
    {
        $fuso = new DateTimeZone(date_default_timezone_get());
        if ($valor instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($valor)->setTimezone($fuso);
        }
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable(substr(trim($valor), 0, 19), $fuso))->setTimezone($fuso);
        } catch (\Exception) {
            return null;
        }
    }

    protected static function iso($valor): ?string
    {
        return static::data($valor)?->format(DATE_ATOM);
    }

    /** Texto sem espaços sobrando; vazio = null. */
    protected static function texto($valor): ?string
    {
        if ($valor === null || is_array($valor) || is_object($valor)) {
            return null;
        }
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
