<?php

// Comandos agendados pelos pacotes que o Entregas não usa (fleetops:sync-telematics e sandbox:sync): saem da agenda no
// App\Console\Kernel::bootstrap (AgendadosDesligados). Confere o filtro numa agenda falsa com a mesma forma da do Laravel
// 10 (Schedule com `protected $events` e Event com `public $command`), o Kernel e os nomes nos providers (cópias em
// packages/).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/agendados-desligados.php

require '/repo/api/app/Support/Entregas/AgendadosDesligados.php';

use App\Support\Entregas\AgendadosDesligados;

$falhas = 0;
function confere(bool $ok, string $descricao): void
{
    if (!$ok) {
        $GLOBALS['falhas']++;
    }
    echo ($ok ? 'PASSA ' : 'FALHA ') . $descricao . PHP_EOL;
}

/** Como o Illuminate\Console\Scheduling\Event: o command é a linha do artisan (Application::formatCommandString). */
class EventoFalso
{
    public $command;
    public $description;
    public array $rejeicoes = [];

    public function __construct(?string $comando, ?string $descricao = null)
    {
        $this->command     = $comando === null ? null : "'/usr/local/bin/php' 'artisan' " . $comando;
        $this->description = $descricao;
    }

    public function skip($callback)
    {
        $this->rejeicoes[] = $callback;

        return $this;
    }
}

/** Como o Illuminate\Console\Scheduling\Schedule do Laravel 10: a lista protegida e o events(). */
class AgendaFalsa
{
    protected $events = [];

    public function adicionar(EventoFalso $evento): EventoFalso
    {
        return $this->events[] = $evento;
    }

    public function events()
    {
        return $this->events;
    }
}

/** Uma agenda sem a propriedade `events` (outra versão do Laravel). */
class AgendaSemLista
{
    public function __construct(private array $lista)
    {
    }

    public function events()
    {
        return $this->lista;
    }
}

echo '== AgendadosDesligados::aplicar' . PHP_EOL;
$agenda    = new AgendaFalsa();
$nossos    = $agenda->adicionar(new EventoFalso('entregas:ifood-polling'));
$despacho  = $agenda->adicionar(new EventoFalso('fleetops:dispatch-orders'));
$telemetria = $agenda->adicionar(new EventoFalso('fleetops:sync-telematics'));
$alertas   = $agenda->adicionar(new EventoFalso('fleetops:process-operational-alerts'));
$sandbox   = $agenda->adicionar(new EventoFalso('sandbox:sync', 'sandbox-sync'));
$job       = $agenda->adicionar(new EventoFalso(null, 'materialize-schedules'));
$parecido  = $agenda->adicionar(new EventoFalso('sandbox:sync-x'));
$comOpcao  = $agenda->adicionar(new EventoFalso('fleetops:sync-telematics --provider=x'));

confere(AgendadosDesligados::aplicar($agenda) === 3, 'tira 3 eventos (sync-telematics, sync-telematics com opção e sandbox:sync)');
confere($agenda->events() === [$nossos, $despacho, $alertas, $job, $parecido], 'os outros ficam, na mesma ordem (inclusive o job sem command e o process-operational-alerts)');
confere(AgendadosDesligados::aplicar($agenda) === 0 && count($agenda->events()) === 5, 'de novo (o bootstrap pode rodar mais de uma vez): não tira mais nada');
confere(AgendadosDesligados::ehDesligado(new EventoFalso('sandbox:migrate')) === false, 'sandbox:migrate não é o sandbox:sync');

$t1 = new EventoFalso('fleetops:sync-telematics');
$o1 = new EventoFalso('fleetops:dispatch-adhoc');
$semLista = new AgendaSemLista([$t1, $o1]);
confere(AgendadosDesligados::aplicar($semLista) === 1 && count($t1->rejeicoes) === 1 && ($t1->rejeicoes[0])() === true && $o1->rejeicoes === [], 'agenda sem a lista events: o evento fica com um skip() que sempre pula');
confere(AgendadosDesligados::aplicar(new AgendaFalsa()) === 0, 'agenda vazia: nada');

echo '== Kernel' . PHP_EOL;
$kernel = str_replace("\r\n", "\n", file_get_contents('/repo/api/app/Console/Kernel.php'));
preg_match('/public function bootstrap\(\)\s*\{(.*?)\n    \}/s', $kernel, $bootstrap);
$corpo = $bootstrap[1] ?? '';
confere(str_contains($corpo, 'parent::bootstrap();') && str_contains($corpo, 'AgendadosDesligados::aplicar($this->app->make(Schedule::class));'), 'bootstrap(): o do Laravel e depois o filtro na agenda');
confere(strpos($corpo, 'parent::bootstrap();') < strpos($corpo, 'AgendadosDesligados::aplicar'), 'o filtro depois do parent::bootstrap() (os pacotes agendam no booted)');
confere(str_contains($corpo, 'catch (\Throwable $e)'), 'uma falha no filtro não derruba o comando artisan');
foreach (['use App\Support\Entregas\AgendadosDesligados;', 'use Illuminate\Support\Facades\Log;', 'use Illuminate\Console\Scheduling\Schedule;'] as $uso) {
    confere(str_contains($kernel, $uso), "import: {$uso}");
}

echo '== nomes nos pacotes (cópias em packages/)' . PHP_EOL;
$core     = file_get_contents('/repo/packages/core-api/src/Providers/CoreServiceProvider.php');
$fleetops = file_get_contents('/repo/packages/fleetops/server/src/Providers/FleetOpsServiceProvider.php');
confere(str_contains($fleetops, "\$schedule->command('fleetops:sync-telematics')"), 'o Fleet-Ops agenda o fleetops:sync-telematics');
confere(str_contains($core, "\$schedule->command('sandbox:sync')"), 'o core agenda o sandbox:sync');
confere(str_contains($fleetops, "\$schedule->command('fleetops:process-operational-alerts')") && !AgendadosDesligados::ehDesligado(new EventoFalso('fleetops:process-operational-alerts')), 'o process-operational-alerts continua');
confere(array_keys(AgendadosDesligados::COMANDOS) === ['fleetops:sync-telematics', 'sandbox:sync'], 'só esses dois: ' . implode(', ', array_keys(AgendadosDesligados::COMANDOS)));

echo PHP_EOL . 'FALHAS: ' . $falhas . PHP_EOL;
