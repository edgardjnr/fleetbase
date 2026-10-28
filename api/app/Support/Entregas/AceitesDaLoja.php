<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Order;

/**
 * Entregas RestaurantePro: os aceites recentes dos pedidos da loja, para a buzina do portal (serviço entregas-buzina,
 * GET int/v1/entregas/loja/aceites). Aceito = com motoboy e started_at nas últimas HORAS. Cada aceite grava outro
 * started_at (a troca com aceite devolve o pedido à fila, e o aceite do novo motoboy grava de novo), então o par (pedido,
 * aceito_em) identifica o aceite; a troca direta depois da coleta não muda o started_at e não toca. Só o primeiro nome do
 * motoboy: nada de id, telefone ou posição. started_at é DATETIME em hora de Brasília (ver "Fuso" em api/CLAUDE.md).
 */
class AceitesDaLoja
{
    public const HORAS  = 2;
    public const LIMITE = 50;

    /**
     * @param array<string> $donos customer_uuid da loja e do contato do usuário (PortalLojaController::donosDosPedidos)
     *
     * @return array<int, array{pedido: string, numero: string, aceito_em: string, motoboy: ?string}>
     */
    public static function listar(string $empresa, array $donos): array
    {
        if ($donos === []) {
            return [];
        }

        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $pedidos = Order::where('company_uuid', $empresa)
            ->whereIn('customer_uuid', $donos)
            ->whereNotNull('driver_assigned_uuid')
            ->whereNull('deleted_at')
            ->where('started_at', '>=', now()->subHours(static::HORAS)->format('Y-m-d H:i:s'))
            ->with(['driverAssigned.user', 'trackingNumber'])
            ->orderBy('started_at', 'desc')
            ->limit(static::LIMITE)
            ->get();

        $aceites = [];
        foreach ($pedidos as $pedido) {
            $aceitoEm = static::data($pedido->started_at);
            if (!$aceitoEm) {
                continue;
            }
            $aceites[] = [
                'pedido'    => (string) $pedido->public_id,
                'numero'    => (string) ($pedido->internal_id ?: ($pedido->trackingNumber?->tracking_number ?: $pedido->public_id)),
                'aceito_em' => PrazoDaEntrega::formatar($aceitoEm),
                'motoboy'   => static::primeiroNome($pedido->driverAssigned?->name),
            ];
        }

        return $aceites;
    }

    public static function primeiroNome(?string $nome): ?string
    {
        $partes = preg_split('/\s+/', trim((string) $nome));

        return ($partes[0] ?? '') === '' ? null : $partes[0];
    }

    /** O started_at: Carbon (cast do model) ou texto DATETIME sem fuso, lido no fuso do app. */
    protected static function data($valor): ?\DateTimeInterface
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor;
        }
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable(substr(trim($valor), 0, 19), new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }
}
