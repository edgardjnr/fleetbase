<?php

namespace App\Console;

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
        // Entregas RestaurantePro: integração iFood (cada comando sai sem fazer nada com ENTREGAS_IFOOD desligado).
        // everyThirtySeconds é do Laravel 10.15+ (o composer.lock está no 10.x de 2026-08): o schedule:run que o go-crond
        // chama a cada minuto fica rodando o minuto inteiro e repete o polling aos 30 s. runInBackground: o polling não
        // espera os comandos do Fleet-Ops da mesma rodada. withoutOverlapping com validade curta (minutos): se o processo
        // morrer segurando a trava, ela some logo (o padrão é 24 h).
        $schedule->command('entregas:ifood-polling')->everyThirtySeconds()->withoutOverlapping(5)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);
        $schedule->command('entregas:ifood-agendados')->everyMinute()->withoutOverlapping(5)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);
        $schedule->command('entregas:ifood-tokens')->everyThirtyMinutes()->withoutOverlapping(10)->appendOutputTo(static::SAIDA_DO_CONTAINER);
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
