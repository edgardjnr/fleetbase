<?php

// Financeiro: multiplicadores por período (Multiplicadores e MultiplicadoresController): regras do formulário,
// sobreposição, edição por situação, ativoEm nas bordas, a segunda tabela de faixas (PagamentoMotoboysController), a
// migration e as rotas. O valor da entrega com o multiplicador fica no entrega-junto.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-multiplicadores.php

namespace {
    require __DIR__ . '/stubs-financeiro.php';
    date_default_timezone_set('America/Sao_Paulo');

    use App\Support\Entregas\Financeiro\Multiplicadores;
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

    resumo();
}
