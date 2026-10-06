<?php

namespace App\Console;

use App\Support\Entregas\Ifood\ClienteIfood;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Saída dos comandos agendados do Entregas: o stdout do container do scheduler. O go-crond é o PID 1 e roda como
     * root, então dá para escrever em /proc/1/fd/1. Sem isso o Laravel joga a saída do comando agendado em /dev/null e os
     * logs "[entregas] ifood:" (LOG_CHANNEL=stdout) não apareceriam no `docker service logs entregas_scheduler`.
     */
    public const SAIDA_DO_CONTAINER = '/proc/1/fd/1';

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Entregas RestaurantePro: integração iFood. when(ClienteIfood::ligada()): com ENTREGAS_IFOOD desligado (ou sem as
        // credenciais) nem sobe o processo; cada comando confere de novo e sai sem fazer nada.
        // everyThirtySeconds é do Laravel 10.15+ (o composer.lock está no 10.x de 2026-08). O schedule:run que o go-crond
        // chama a cada minuto roda primeiro, em primeiro plano, os eventos da rodada que não são em segundo plano (os
        // comandos do Fleet-Ops); só depois fica esperando para repetir o polling aos 30 s. Por isso a repetição sai aos
        // 30 s do minuto ou depois, se essa passada demorar mais (e nem sai, se ela passar do fim do minuto: aí o polling
        // roda só na rodada seguinte). runInBackground nos três: nenhum deles segura os
        // comandos do Fleet-Ops da mesma rodada (ex.: o fleetops:dispatch-orders, que só pega quem cai a ±1 min do
        // scheduled_at). withoutOverlapping com validade curta (minutos): se o processo morrer segurando a trava, ela
        // some logo (o padrão é 24 h).
        $ligada = fn () => ClienteIfood::ligada();

        $schedule->command('entregas:ifood-polling')->everyThirtySeconds()->when($ligada)->withoutOverlapping(5)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);
        $schedule->command('entregas:ifood-agendados')->everyMinute()->when($ligada)->withoutOverlapping(5)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);
        $schedule->command('entregas:ifood-tokens')->everyThirtyMinutes()->when($ligada)->withoutOverlapping(10)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}
