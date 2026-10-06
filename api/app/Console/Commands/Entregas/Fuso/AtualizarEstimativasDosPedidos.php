<?php

namespace App\Console\Commands\Entregas\Fuso;

use Fleetbase\FleetOps\Console\Commands\TrackOrderDistanceAndTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Entregas RestaurantePro: o `fleetops:update-estimations` do Fleet-Ops sem o
 * `date_default_timezone_set('UTC')` do início do handle().
 *
 * O comando atualiza a distância e o tempo dos pedidos em andamento e grava o updated_at.
 *
 * O servidor roda no horário de Brasília e a sessão do MySQL em -03:00: com o PHP em UTC dentro do comando, as datas
 * sem fuso lidas do banco (DATETIME, como o scheduled_at) viravam instantes 3 h errados e o now() gravado ia 3 h para
 * o futuro. Ver CLAUDE.md, "Fuso (horário de Brasília)".
 *
 * Roda no lugar do original, com o mesmo nome e o mesmo agendamento (a troca fica no AppServiceProvider). O handle()
 * é cópia do original (fleetops-api 0.6.65) sem a linha do fuso.
 *
 * Ao atualizar o fleetops-api, confira se o handle() do original mudou (o teste scripts/teste-php/fuso.php compara os
 * dois, com a cópia de packages/fleetops) e se outro comando agendado passou a chamar date_default_timezone_set.
 */
class AtualizarEstimativasDosPedidos extends TrackOrderDistanceAndTime
{
    public function handle(): int
    {
        $provider = $this->option('provider') ?: (string) config('fleetops.distance_matrix.provider');
        $days     = max(1, (int) $this->option('days'));           // guardrail
        $perChunk = max(50, (int) $this->option('chunk'));         // sane lower bound
        $dryRun   = (bool) $this->option('dry');
        $useLock  = !$this->option('no-lock');

        $this->info("Using provider: {$provider}");
        $this->info("Looking back: last {$days} day(s)");
        $this->info("Chunk size: {$perChunk}" . ($dryRun ? ' (dry-run)' : ''));

        // Prevent overlapping executions (e.g., cron overlap)
        $lock = null;
        if ($useLock) {
            $lock = Cache::lock('fleetops:update-estimations', 3600); // 1h lock window
            if (!$lock->get()) {
                $this->warn('Another run appears to be in progress (lock active). Use --no-lock to bypass.');

                return self::SUCCESS;
            }
        }

        try {
            $cutoff = Carbon::now()->subDays($days);

            // Build the base query (no data loaded yet)
            $base = $this->activeOrdersQuery($cutoff);

            // Count total to set up a progress bar without loading all rows
            $total = (clone $base)->count('id');
            if ($total === 0) {
                $this->info('No qualifying orders found. Exiting.');

                return self::SUCCESS;
            }

            $this->alert('Found ' . number_format($total) . ' orders to update. Current Time: ' . Carbon::now()->toDateTimeString());
            $bar = $this->createProgressBar($total);
            $bar->start();

            $updated = 0;
            $errors  = 0;

            // Stream through the dataset in stable primary-key order
            $base->orderBy('id')->chunkById($perChunk, function ($orders) use ($provider, $dryRun, $bar, &$updated, &$errors) {
                // Eager-load relationships per chunk to avoid N+1
                $orders->load(['payload', 'payload.waypoints', 'payload.pickup', 'payload.dropoff']);

                foreach ($orders as $order) {
                    try {
                        if (!$dryRun) {
                            $order->setDistanceAndTime(['provider' => $provider]);
                        }
                        $updated++;
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->error("Order {$order->id} failed: {$e->getMessage()}");
                    } finally {
                        $bar->advance();
                    }
                }

                // Optional: small micro-sleep can smooth out DB/API burstiness
                // usleep(20000); // 20ms
            });

            $bar->finish();
            $this->newLine(2);

            $this->info(($dryRun ? '[Dry Run] ' : '') . "Updated {$updated}/" . number_format($total) . ' orders.');
            if ($errors > 0) {
                $this->warn("Encountered {$errors} error(s). Check logs for details.");
            }

            return self::SUCCESS;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }
}
