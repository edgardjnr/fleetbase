<?php

namespace App\Console\Commands\Entregas\Fuso;

use Carbon\Carbon;
use Fleetbase\FleetOps\Console\Commands\ProcessMaintenanceTriggers;

/**
 * Entregas RestaurantePro: o `fleetops:process-maintenance-triggers` do Fleet-Ops sem o
 * `date_default_timezone_set('UTC')` do início do handle().
 *
 * O comando cria ordens de serviço de manutenção preventiva; tela oculta.
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
class ProcessarGatilhosDeManutencao extends ProcessMaintenanceTriggers
{
    public function handle(): void
    {
        $sandbox = (bool) $this->option('sandbox');
        $dryRun  = (bool) $this->option('dry-run');
        $conn    = $this->connectionName($sandbox);

        $this->info('Processing maintenance schedule triggers' . ($dryRun ? ' [DRY RUN]' : '') . ' at ' . Carbon::now()->toDateTimeString());

        $triggered = 0;

        // ------------------------------------------------------------------
        // Load all active schedules that have at least one threshold set
        // ------------------------------------------------------------------
        $schedules = $this->schedules($conn);

        foreach ($schedules as $schedule) {
            $subject                             = $schedule->subject;
            [$currentOdometer, $currentEngHours] = $this->currentReadingsFromSubject($subject);

            if (!$schedule->isDue($currentOdometer, $currentEngHours)) {
                continue;
            }

            // Build a human-readable reason string for logging
            $reasons = $this->triggerReasons($schedule, $currentOdometer, $currentEngHours);

            $this->line("Triggered: schedule {$schedule->public_id} ({$schedule->name}) — " . implode(', ', $reasons));

            if (!$dryRun) {
                // Check if a pending work order already exists for this schedule
                // to avoid duplicates on repeated runs before the WO is completed
                $existingOpen = $this->openWorkOrderExists($conn, $schedule);

                if ($existingOpen) {
                    $this->line('  → Skipped: open work order already exists for this schedule.');
                    continue;
                }

                // Auto-generate a work order from the schedule defaults
                // Generate a sequential WO code: WO-YYYYMMDD-XXXX
                $woCount = $this->workOrderCount($conn) + 1;
                $woCode  = $this->workOrderCode($woCount, now());

                $workOrder = $this->createWorkOrder($conn, [
                    'company_uuid'    => $schedule->company_uuid,
                    'schedule_uuid'   => $schedule->uuid,
                    'subject'         => $schedule->name,
                    'category'        => 'preventive_maintenance',
                    'code'            => $woCode,
                    'status'          => 'open',
                    'priority'        => $schedule->default_priority ?? 'normal',
                    'target_type'     => $schedule->subject_type,
                    'target_uuid'     => $schedule->subject_uuid,
                    'assignee_type'   => $schedule->default_assignee_type,
                    'assignee_uuid'   => $schedule->default_assignee_uuid,
                    'instructions'    => $schedule->instructions,
                    'due_at'          => $schedule->next_due_date,
                    'opened_at'       => now(),
                    'created_by_uuid' => null, // system-generated
                ]);

                $this->line("  → Created work order {$workOrder->public_id}");

                $this->dispatchTriggeredEvent($schedule, $workOrder);
            }

            $triggered++;
        }

        $this->info($this->processedSummary($triggered, $dryRun));
    }
}
