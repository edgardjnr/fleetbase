<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: comandos que os pacotes do Fleetbase agendam e o Entregas não usa. Saem da agenda do Laravel
 * (Schedule) depois que todos os providers sobem (App\Console\Kernel::bootstrap): o schedule:run não os roda, e o
 * schedule:list e o schedule-monitor:sync não os mostram.
 *
 * Os pacotes agendam no booted() deles (CoreServiceProvider::scheduleCommands), depois do Kernel::schedule() do app, que
 * roda quando a agenda é criada. Por isso o filtro não fica no schedule(): ali os comandos dos pacotes ainda não estão.
 *
 * Ao atualizar o core-api ou o fleetops-api, confira se os nomes continuam os mesmos (scheduleCommands no
 * CoreServiceProvider e no FleetOpsServiceProvider; teste scripts/teste-php/agendados-desligados.php).
 */
final class AgendadosDesligados
{
    /** Comando artisan => por que não roda. */
    public const COMANDOS = [
        // a cada minuto: puxa posições e eventos dos rastreadores veiculares cadastrados (telemática). O Entregas não tem
        // rastreador: a posição do motoboy vem do app
        'fleetops:sync-telematics' => 'sem telemetria veicular',
        // de hora em hora: copia usuários e empresas para o banco sandbox (modo de teste do console), que o Entregas não usa
        'sandbox:sync' => 'sem modo de teste',
    ];

    /**
     * Tira da agenda os eventos dos COMANDOS. Devolve quantos tirou. Se a agenda não tiver a lista `events` (outra versão do
     * Laravel), os eventos ficam e ganham um skip() que sempre pula: não rodam, mas aparecem no schedule:list.
     *
     * @param object $agenda o Illuminate\Console\Scheduling\Schedule
     */
    public static function aplicar(object $agenda): int
    {
        $eventos = method_exists($agenda, 'events') ? (array) $agenda->events() : [];
        $tirar   = array_filter($eventos, fn ($evento) => is_object($evento) && static::ehDesligado($evento));
        if (!$tirar) {
            return 0;
        }

        if (property_exists($agenda, 'events')) {
            $ficam = array_values(array_filter($eventos, fn ($evento) => !in_array($evento, $tirar, true)));
            \Closure::bind(function () use ($ficam) {
                $this->events = $ficam;
            }, $agenda, get_class($agenda))();

            return count($tirar);
        }

        foreach ($tirar as $evento) {
            if (method_exists($evento, 'skip')) {
                $evento->skip(fn () => true);
            }
        }

        return count($tirar);
    }

    /**
     * O evento é de um dos COMANDOS? O `command` do evento é a linha do artisan (`'/usr/local/bin/php' 'artisan'
     * fleetops:sync-telematics`); o nome tem de aparecer inteiro, como palavra (sandbox:sync não pega sandbox:sync-x).
     */
    public static function ehDesligado(object $evento): bool
    {
        $comando = property_exists($evento, 'command') && is_string($evento->command) ? $evento->command : '';
        foreach (array_keys(static::COMANDOS) as $nome) {
            if (preg_match('/(^|[\s\'"])' . preg_quote($nome, '/') . '($|[\s\'"])/', $comando) === 1) {
                return true;
            }
        }

        return false;
    }
}
