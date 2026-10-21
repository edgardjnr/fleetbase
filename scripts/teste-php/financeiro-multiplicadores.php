<?php

// Financeiro: multiplicadores por período (Multiplicadores e MultiplicadoresController): regras do formulário,
// sobreposição, edição por situação, ativoEm nas bordas, a segunda tabela de faixas (PagamentoMotoboysController), a
// migration e as rotas. O valor da entrega com o multiplicador fica no entrega-junto.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-multiplicadores.php

namespace {
    require __DIR__ . '/stubs-financeiro.php';
    date_default_timezone_set('America/Sao_Paulo');

    use App\Http\Controllers\Entregas\MultiplicadoresController;
    use App\Http\Controllers\Entregas\PagamentoMotoboysController;
    use App\Support\Entregas\CalculoEntregas;
    use App\Support\Entregas\Financeiro\Multiplicadores;
    use Fleetbase\Support\Auth;
    use Illuminate\Http\Request;
    use Teste\Relogio;

    function preparar(): void
    {
        reiniciarFinanceiro();
        session(['company' => 'empresa-1']);
        Relogio::$agora = '2026-10-21 17:00:00';
    }

    /** O formulário da chuva de hoje, 18:00 às 22:00 (como o campo datetime-local do console manda). */
    function chuva(array $troca = []): array
    {
        return $troca + ['nome' => 'Chuva', 'mult_motoboy' => '1,2', 'mult_loja' => '1', 'inicio' => '2026-10-21T18:00', 'fim' => '2026-10-21T22:00'];
    }

    function agora(): string
    {
        return Relogio::$agora;
    }

    function usuario(bool $admin = true): object
    {
        return new class($admin) {
            public string $uuid = 'user-admin';
            public function __construct(public bool $admin) {}
            public function isNotAdmin(): bool { return !$this->admin; }
        };
    }

    function api(): MultiplicadoresController
    {
        return new MultiplicadoresController();
    }

    function req(array $dados = []): Request
    {
        return new Request($dados);
    }

    function erroDe($resposta): string
    {
        return (string) ($resposta->dados['errors'][0] ?? '');
    }

    echo '== Criar' . PHP_EOL;
    preparar();
    $m = Multiplicadores::criar('empresa-1', chuva(), 'user-admin', agora());
    confere(is_array($m) && $m['nome'] === 'Chuva' && $m['mult_motoboy'] === 1.2 && $m['mult_loja'] === 1.0, 'cria com os fatores em número (1,2 e 1)');
    confere(is_array($m) && $m['inicio'] === '2026-10-21T18:00:00-03:00' && $m['fim'] === '2026-10-21T22:00:00-03:00' && $m['situacao'] === 'agendado', 'horários em ISO com o fuso de Brasília; ainda não começou');
    $linha = linhasDe('entregas_multiplicadores')[0] ?? [];
    confere(($linha['company_uuid'] ?? null) === 'empresa-1' && ($linha['criado_por'] ?? null) === 'user-admin' && ($linha['inicio'] ?? null) === '2026-10-21 18:00:00' && array_key_exists('deleted_at', $linha) && $linha['deleted_at'] === null, 'grava a empresa, quem criou e o início em Brasília, sem fuso');

    foreach ([
        'sem nome'            => chuva(['nome' => '  ']),
        'nome com 41 letras'  => chuva(['nome' => str_repeat('a', 41)]),
        'fator abaixo de 1'   => chuva(['mult_motoboy' => '0,9']),
        'fator acima de 5'    => chuva(['mult_loja' => '5,01']),
        'três casas'          => chuva(['mult_motoboy' => '1,234']),
        'fator em texto'      => chuva(['mult_motoboy' => 'abc']),
        'sem fim'             => chuva(['fim' => null]),
        'data que não existe' => chuva(['inicio' => '2026-02-30T18:00']),
        'fim antes do início' => chuva(['fim' => '2026-10-21T17:30']),
        'mais de 7 dias'      => chuva(['fim' => '2026-10-28T18:01']),
    ] as $caso => $dados) {
        $r = Multiplicadores::criar('empresa-1', $dados, 'user-admin', agora());
        confere(is_string($r) && $r !== '', "{$caso}: erro (" . (is_string($r) ? $r : 'criou') . ')');
    }

    $r = Multiplicadores::criar('empresa-1', chuva(['nome' => 'Temporal', 'inicio' => '2026-10-21T21:00', 'fim' => '2026-10-21T23:00']), null, agora());
    confere($r === 'Já existe o período "Chuva" de 21/10 18:00 a 21/10 22:00 nesse horário.', 'sobreposto: erro que cita o outro período');
    $noite = Multiplicadores::criar('empresa-1', chuva(['nome' => 'Noite', 'inicio' => '2026-10-21T22:00', 'fim' => '2026-10-21T23:00']), null, agora());
    confere(is_array($noite), 'encostado no fim do outro (22:00): pode');
    confere(is_array(Multiplicadores::criar('empresa-2', chuva(), null, agora())), 'outra empresa no mesmo horário: pode');

    echo '== Começar agora' . PHP_EOL;
    preparar();
    $agora = Multiplicadores::criar('empresa-1', chuva(['inicio' => '2026-10-21T16:00', 'fim' => '2026-10-21T20:00']), null, agora());
    confere(is_array($agora) && $agora['inicio'] === '2026-10-21T17:00:00-03:00' && $agora['situacao'] === 'em_andamento', 'início no passado vira agora (17:00): já em andamento');

    echo '== Vale no instante (ativoEm)' . PHP_EOL;
    preparar();
    Multiplicadores::criar('empresa-1', chuva(), null, agora());
    Multiplicadores::criar('empresa-1', chuva(['nome' => 'Noite', 'inicio' => '2026-10-21T22:00', 'fim' => '2026-10-21T23:00']), null, agora());
    confere(Multiplicadores::ativoEm('empresa-1', '2026-10-21 17:59:59') === null, '17:59:59: nenhum');
    confere(Multiplicadores::ativoEm('empresa-1', '2026-10-21 18:00:00')?->nome === 'Chuva', '18:00: a chuva (o início vale)');
    confere(Multiplicadores::ativoEm('empresa-1', '2026-10-21 22:00:00')?->nome === 'Noite', '22:00: o seguinte (o fim da chuva não vale)');
    confere(Multiplicadores::ativoEm('empresa-2', '2026-10-21 19:00:00') === null, 'outra empresa: nenhum');
    $periodos = Multiplicadores::doPeriodo('empresa-1', '2026-10-21 21:00:00', '2026-10-21 21:30:00');
    confere(count($periodos) === 1 && $periodos[0]->nome === 'Chuva', 'doPeriodo: só os que tocam o intervalo');
    confere(Multiplicadores::noInstante($periodos, '2026-10-21 21:10:00')?->nome === 'Chuva' && Multiplicadores::noInstante($periodos, '2026-10-21 22:10:00') === null, 'noInstante escolhe da lista');

    echo '== Lista' . PHP_EOL;
    $lista = Multiplicadores::lista('empresa-1', agora());
    confere(array_column($lista, 'nome') === ['Noite', 'Chuva'], 'mais recente primeiro (pelo início)');

    echo '== Rotas do console (MultiplicadoresController)' . PHP_EOL;
    preparar();
    Auth::$usuario = usuario(false);
    foreach ([
        'lista'    => fn () => api()->lista(req()),
        'criar'    => fn () => api()->criar(req(chuva())),
        'alterar'  => fn () => api()->alterar(req(chuva()), 'x'),
        'encerrar' => fn () => api()->encerrar(req(), 'x'),
        'apagar'   => fn () => api()->apagar(req(), 'x'),
    ] as $metodo => $chamar) {
        $r = $chamar();
        confere($r->status === 403 && erroDe($r) !== '', "{$metodo}: não admin → 403");
    }
    confere(linhasDe('entregas_multiplicadores') === [], 'nada gravado');

    Auth::$usuario = usuario();
    $r = api()->criar(req(chuva()));
    $chuva = $r->dados['multiplicador'] ?? null;
    confere($r->status === 200 && ($chuva['nome'] ?? null) === 'Chuva' && (linhasDe('entregas_multiplicadores')[0]['criado_por'] ?? null) === 'user-admin', 'POST cria com quem criou: { multiplicador }');
    confere(api()->criar(req(chuva(['mult_motoboy' => '9'])))->status === 422, 'POST inválido: 422');
    $noite = api()->criar(req(chuva(['nome' => 'Noite', 'inicio' => '2026-10-21T22:00', 'fim' => '2026-10-21T23:00'])))->dados['multiplicador']['id'];
    $r = api()->lista(req());
    confere($r->status === 200 && array_column($r->dados['multiplicadores'], 'nome') === ['Noite', 'Chuva'] && $r->dados['agora'] === '2026-10-21T17:00:00-03:00', 'GET: a lista e a hora do servidor');

    $id = $chuva['id'];
    $r  = api()->alterar(req(chuva(['mult_motoboy' => '1,5'])), $id);
    confere($r->status === 200 && $r->dados['multiplicador']['mult_motoboy'] === 1.5, 'agendado: PUT muda o fator');

    Relogio::$agora = '2026-10-21 19:00:00';
    $r = api()->alterar(req(['fim' => '2026-10-21T21:30', 'mult_motoboy' => '3']), $id);
    confere($r->status === 200 && $r->dados['multiplicador']['fim'] === '2026-10-21T21:30:00-03:00' && $r->dados['multiplicador']['mult_motoboy'] === 1.5, 'em andamento: só o fim muda (o fator enviado é ignorado)');
    $r = api()->alterar(req(['fim' => '2026-10-21T22:30']), $id);
    confere($r->status === 422 && str_contains(erroDe($r), 'Noite'), 'prorrogar por cima do seguinte: 422 citando o outro');
    confere(api()->apagar(req(), $id)->status === 422, 'em andamento não se apaga');
    $r = api()->encerrar(req(), $id);
    confere($r->status === 200 && $r->dados['multiplicador']['situacao'] === 'encerrado' && $r->dados['multiplicador']['fim'] === '2026-10-21T19:00:00-03:00', 'encerrar agora: fim = agora');
    $linhaDaChuva = array_values(array_filter(linhasDe('entregas_multiplicadores'), fn ($l) => $l['uuid'] === $id))[0] ?? [];
    confere(($linhaDaChuva['encerrado_por'] ?? null) === 'user-admin', 'grava quem encerrou');
    confere(api()->alterar(req(chuva()), $id)->status === 422 && api()->encerrar(req(), $id)->status === 422, 'encerrado não muda nem encerra de novo');

    confere(api()->encerrar(req(), $noite)->status === 422, 'agendado: não se encerra (apague)');
    $r = api()->apagar(req(), $noite);
    confere($r->status === 200 && $r->dados === ['apagado' => true], 'agendado: DELETE apaga');
    confere(Multiplicadores::ativoEm('empresa-1', '2026-10-21 22:30:00') === null && count(api()->lista(req())->dados['multiplicadores']) === 1, 'o apagado não vale nem aparece');

    $deOutra = Multiplicadores::criar('empresa-2', chuva(['inicio' => '2026-10-21T20:00']), null, agora());
    foreach (['alterar' => fn () => api()->alterar(req(chuva()), $deOutra['id']), 'encerrar' => fn () => api()->encerrar(req(), $deOutra['id']), 'apagar' => fn () => api()->apagar(req(), $deOutra['id']), 'inexistente' => fn () => api()->apagar(req(), 'nao-existe')] as $caso => $chamar) {
        confere($chamar()->status === 404, "{$caso}: período de outra empresa ou que não existe → 404");
    }

    echo '== Segunda tabela de faixas (PagamentoMotoboysController)' . PHP_EOL;
    preparar();
    Auth::$usuario = usuario();
    $faixas = new PagamentoMotoboysController();
    $r = $faixas->salvarFaixasJuntas(req(['faixas' => [['ate_km' => 6, 'motoboy' => '6', 'loja' => 9], ['ate_km' => 3, 'motoboy' => 4, 'loja' => 6]]]), new CalculoEntregas());
    confere($r->status === 200 && $r->dados['faixas'] === [['ate_km' => 3.0, 'motoboy' => 4.0, 'loja' => 6.0], ['ate_km' => 6.0, 'motoboy' => 6.0, 'loja' => 9.0]], 'grava em ordem de km, em números');
    confere(\Fleetbase\Models\Setting::$valores['company.empresa-1.entregas.faixas_juntas'] === $r->dados['faixas'] && !isset(\Fleetbase\Models\Setting::$valores['company.empresa-1.entregas.faixas']), 'na chave da segunda tabela, sem mexer na normal');
    confere($faixas->salvarFaixasJuntas(req(['faixas' => [['ate_km' => 3, 'motoboy' => 4, 'loja' => 6], ['ate_km' => 3, 'motoboy' => 5, 'loja' => 7]]]), new CalculoEntregas())->status === 422, 'dois "até km" iguais: 422');
    $r = $faixas->salvarFaixasJuntas(req(['faixas' => []]), new CalculoEntregas());
    confere($r->status === 200 && $r->dados['faixas'] === [] && \Fleetbase\Models\Setting::$valores['company.empresa-1.entregas.faixas_juntas'] === [], 'lista vazia desliga');
    Auth::$usuario = usuario(false);
    confere($faixas->salvarFaixasJuntas(req(['faixas' => []]), new CalculoEntregas())->status === 403, 'não admin: 403');

    echo '== Rotas' . PHP_EOL;
    $provider = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
    confere(str_contains($provider, 'use App\\Http\\Controllers\\Entregas\\MultiplicadoresController;'), 'RouteServiceProvider importa o controller');
    $grupo = substr($provider, (int) strpos($provider, "Route::prefix('int/v1/entregas')"), max(0, (int) strpos($provider, "Route::prefix('v1/entregas/motoboy')") - (int) strpos($provider, "Route::prefix('int/v1/entregas')")));
    foreach ([
        ['get', 'financeiro/multiplicadores', MultiplicadoresController::class, 'MultiplicadoresController', 'lista'],
        ['post', 'financeiro/multiplicadores', MultiplicadoresController::class, 'MultiplicadoresController', 'criar'],
        ['put', 'financeiro/multiplicadores/{id}', MultiplicadoresController::class, 'MultiplicadoresController', 'alterar'],
        ['post', 'financeiro/multiplicadores/{id}/encerrar', MultiplicadoresController::class, 'MultiplicadoresController', 'encerrar'],
        ['delete', 'financeiro/multiplicadores/{id}', MultiplicadoresController::class, 'MultiplicadoresController', 'apagar'],
        ['put', 'pagamento-motoboys/faixas-juntas', PagamentoMotoboysController::class, 'PagamentoMotoboysController', 'salvarFaixasJuntas'],
    ] as [$verbo, $caminho, $classe, $curta, $metodo]) {
        confere(str_contains($grupo, "Route::{$verbo}('{$caminho}', [{$curta}::class, '{$metodo}'])") && method_exists($classe, $metodo), strtoupper($verbo) . " {$caminho} → {$metodo}");
    }

    echo '== Migration da tabela' . PHP_EOL;
    \Illuminate\Support\Facades\Schema::$criadas = [];
    $migration = require '/repo/api/database/migrations/2026_10_21_100000_create_entregas_financeiro_multiplicadores_table.php';
    $migration->up();
    $nomes = array_filter(array_map(fn ($c) => $c->argumentos[0] ?? $c->tipo, \Illuminate\Support\Facades\Schema::$criadas['entregas_multiplicadores']->colunas ?? []), 'is_string'); // o index([...]) tem lista, não nome
    confere(array_diff(['uuid', 'company_uuid', 'nome', 'mult_motoboy', 'mult_loja', 'inicio', 'fim', 'criado_por', 'encerrado_por', 'deleted_at'], $nomes) === [], 'as colunas que a classe usa');
    $migration->down();
    confere(!isset(\Illuminate\Support\Facades\Schema::$criadas['entregas_multiplicadores']), 'o down apaga a tabela');

    resumo();
}
