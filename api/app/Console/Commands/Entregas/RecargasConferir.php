<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\PrePago\PrePago;
use App\Support\Entregas\PrePago\Recargas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: confere no Asaas os Pix de recarga das lojas pré-pagas, a cada minuto (Kernel). Roda mesmo
 * com ENTREGAS_RECARGA desligada: um Pix já gerado precisa virar crédito. Cada recarga no seu try/catch (uma falha não
 * para as outras):
 *
 * - criando e aguardando: Recargas::conferir (paga → credita; QR vencido → apaga a cobrança e expira; criando há mais de
 *   2 min → procura pelo externalReference);
 * - expiradas há mais de 1 h (nos últimos 2 dias), uma vez: Recargas::conferirDepois (paga no limite → credita).
 */
class RecargasConferir extends Command
{
    /** Recargas por volta (de cada tipo). */
    public const LIMITE = 200;

    protected $signature   = 'entregas:recargas-conferir';
    protected $description = 'Entregas: confere no Asaas os Pix de recarga das lojas pré-pagas';

    public function handle(): int
    {
        try {
            $abertas = DB::table(PrePago::RECARGAS)->whereIn('situacao', PrePago::EM_ANDAMENTO)->orderBy('id')->limit(static::LIMITE)->get()->all();
            $tardias = DB::table(PrePago::RECARGAS)
                ->where('situacao', PrePago::EXPIRADA)
                ->whereNotNull('payment_id')
                ->whereNull('conferida_depois_em')
                ->where('updated_at', '<=', now()->subMinutes(PrePago::MINUTOS_CONFERENCIA_TARDIA)->format('Y-m-d H:i:s'))
                ->where('created_at', '>=', now()->subDays(2)->format('Y-m-d H:i:s'))
                ->orderBy('id')
                ->limit(static::LIMITE)
                ->get()
                ->all();
        } catch (\Throwable $e) {
            // sem a tabela (API nova antes da migration): nada a conferir
            Log::warning('[entregas] recarga: rotina sem as recargas', ['erro' => get_class($e)]);

            return self::SUCCESS;
        }

        $pagas = 0;
        foreach ($abertas as $recarga) {
            try {
                $pagas += Recargas::conferir($recarga)->situacao === PrePago::PAGA ? 1 : 0;
            } catch (\Throwable $e) {
                Log::warning('[entregas] recarga: conferência falhou', ['recarga' => $recarga->uuid, 'erro' => get_class($e)]);
            }
        }
        foreach ($tardias as $recarga) {
            try {
                $pagas += Recargas::conferirDepois($recarga) ? 1 : 0;
            } catch (\Throwable $e) {
                Log::warning('[entregas] recarga: conferência tardia falhou', ['recarga' => $recarga->uuid, 'erro' => get_class($e)]);
            }
        }
        if ($pagas > 0) {
            Log::info('[entregas] recarga: rotina creditou recargas', ['recargas' => $pagas]);
        }

        return self::SUCCESS;
    }
}
