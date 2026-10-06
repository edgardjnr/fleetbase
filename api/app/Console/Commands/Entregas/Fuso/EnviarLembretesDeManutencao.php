<?php

namespace App\Console\Commands\Entregas\Fuso;

use Fleetbase\FleetOps\Console\Commands\SendMaintenanceReminders;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: o `fleetops:send-maintenance-reminders` do Fleet-Ops sem o
 * `date_default_timezone_set('UTC')` do início do handle().
 *
 * O comando envia lembretes de manutenção; tela oculta.
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
class EnviarLembretesDeManutencao extends SendMaintenanceReminders
{
    public function handle(): void
    {
        $sandbox = (bool) $this->option('sandbox');
        $dryRun  = (bool) $this->option('dry-run');
        $conn    = $sandbox ? 'sandbox' : 'mysql';

        $this->info('Sending maintenance schedule reminders' . ($dryRun ? ' [DRY RUN]' : '') . ' at ' . Carbon::now()->toDateTimeString());

        $sent = 0;

        // Load all active schedules that have:
        //   - a non-null next_due_date (reminders are date-based only)
        //   - a non-null reminder_offsets JSON array
        //   - a default_assignee configured
        $schedules = $this->schedules($conn);

        foreach ($schedules as $schedule) {
            $offsets     = $schedule->reminder_offsets;
            $nextDueDate = $schedule->next_due_date;

            if (empty($offsets) || !$nextDueDate) {
                continue;
            }

            // Resolve the recipient email from the polymorphic defaultAssignee
            $assignee = $schedule->defaultAssignee;
            $email    = $assignee?->email ?? null;

            if (!$email) {
                $this->line("  → Skipped schedule {$schedule->public_id}: no email on default assignee.");
                continue;
            }

            $dueDateSnapshot = $nextDueDate->toDateString();

            foreach ($offsets as $offsetDays) {
                $offsetDays = (int) $offsetDays;

                // The reminder window: today must be on or after (due_date - offset_days)
                $reminderDate = $nextDueDate->copy()->subDays($offsetDays);

                if (Carbon::today()->lt($reminderDate)) {
                    // Not yet within the reminder window for this offset
                    continue;
                }

                // Check whether we already sent this reminder for this cycle
                $alreadySent = $this->reminderAlreadySent($conn, $schedule, $offsetDays, $dueDateSnapshot);

                if ($alreadySent) {
                    continue;
                }

                $this->line("Sending reminder: schedule {$schedule->public_id} ({$schedule->name}) — {$offsetDays} days before {$dueDateSnapshot} → {$email}");

                if (!$dryRun) {
                    $this->sendReminder($email, $schedule, $offsetDays);
                    $this->recordReminder($conn, $schedule, $offsetDays, $dueDateSnapshot);
                }

                $sent++;
            }
        }

        $this->info("Sent {$sent} reminder(s)" . ($dryRun ? ' (dry run — no emails sent)' : '.'));
    }
}
