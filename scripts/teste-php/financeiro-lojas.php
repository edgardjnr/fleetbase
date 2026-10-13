<?php

// Financeiro: a API do console para lojas e faturas (FinanceiroLojasController), o comando entregas:financeiro-rascunhos
// (FinanceiroRascunhos), o agendamento dele e as rotas no RouteServiceProvider.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-lojas.php

namespace Teste {
    // os e-mails da fatura (AvisosDaFatura, tarefa B4) no lugar do real: guarda as chamadas e responde o que o teste mandar
    class AvisosFalsos
    {
        public static array $emitidas = [];
        public static bool $resposta  = true;
        public static ?\Throwable $falha = null;
        /** O que a AvisosDaFatura::emailsDaLoja responderia (o reenviar usa para dizer por que não enviou). */
        public static array $emails = ['ze@pizzaria.test'];

        public static function emailsDaLoja(string $companyUuid, string $vendorUuid): array
        {
            return self::$emails;
        }

        public static function emitida(object $fatura): bool
        {
            self::$emitidas[] = $fatura->uuid;
            if (self::$falha) {
                throw self::$falha;
            }

            return self::$resposta;
        }
    }
}

namespace {
    require __DIR__ . '/stubs-financeiro.php';

    use App\Console\Commands\Entregas\FinanceiroRascunhos;
    use App\Http\Controllers\Entregas\FinanceiroLojasController;
    use App\Support\Entregas\Financeiro\Faturas;
    use App\Support\Entregas\Financeiro\Lancamentos;
    use App\Support\Entregas\Financeiro\Pagamentos;
    use Fleetbase\FleetOps\Models\Vendor;
    use Fleetbase\Support\Auth;
    use Illuminate\Http\Request;
    use Teste\AvisosFalsos;
    use Teste\Banco;
    use Teste\Config;
    use Teste\Relogio;

    /** O usuário do console (admin ou não). */
    function usuario(bool $admin = true): object
    {
        return new class($admin) {
            public string $uuid = 'user-admin';
            public function __construct(public bool $admin) {}
            public function isNotAdmin(): bool { return !$this->admin; }
        };
    }

    function preparar(): void
    {
        reiniciarFinanceiro();
        session(['company' => 'empresa-1']);
        Auth::$usuario = usuario();
        Vendor::$todos[0]->email = 'ze@pizzaria.test';
        Vendor::$todos[] = new Vendor(['uuid' => 'vendor-b', 'public_id' => 'vendor_b', 'company_uuid' => 'empresa-1', 'name' => 'Burger Hub', 'type' => 'customer']);
        Vendor::$todos[] = new Vendor(['uuid' => 'vendor-f', 'public_id' => 'vendor_f', 'company_uuid' => 'empresa-1', 'name' => 'Fornecedor', 'type' => 'vendor']);
        Vendor::$todos[] = new Vendor(['uuid' => 'vendor-z', 'public_id' => 'vendor_z', 'company_uuid' => 'empresa-2', 'name' => 'Outra empresa', 'type' => 'customer']);
        Banco::$tabelas['users'] = [1 => ['id' => 1, 'uuid' => 'user-admin', 'name' => 'Edgard']];
        AvisosFalsos::$emitidas = [];
        AvisosFalsos::$resposta = true;
        AvisosFalsos::$falha    = null;
        AvisosFalsos::$emails   = ['ze@pizzaria.test'];
        FinanceiroLojasController::$avisos = AvisosFalsos::class;
    }

    function corridaDaLoja(string $vendor, float $valor, string $data, string $pedido, string $empresa = 'empresa-1'): int
    {
        return Lancamentos::inserir(['company_uuid' => $empresa, 'conta_tipo' => 'loja', 'conta_uuid' => $vendor, 'tipo' => 'corrida', 'valor' => $valor, 'data_referencia' => $data,
            'order_uuid' => $pedido, 'chave_unica' => 'corrida:loja:' . $pedido, 'detalhes' => ['pedido' => 'order_' . $pedido, 'numero' => strtoupper($pedido), 'km' => 2.5]]);
    }

    function req(array $dados = []): Request
    {
        return new Request($dados);
    }

    function api(): FinanceiroLojasController
    {
        return new FinanceiroLojasController();
    }

    /** A mensagem de erro da resposta (errors[0]) ou ''. */
    function erroDe($resposta): string
    {
        return (string) ($resposta->dados['errors'][0] ?? '');
    }

    echo '== Só administradores' . PHP_EOL;
    preparar();
    Auth::$usuario = usuario(false);
    $chamadas = [
        'lojas'           => fn () => api()->lojas(req()),
        'salvarCiclo'     => fn () => api()->salvarCiclo(req(['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => 3]), 'vendor_a'),
        'extratoDaLoja'   => fn () => api()->extratoDaLoja(req(), 'vendor_a'),
        'faturas'         => fn () => api()->faturas(req()),
        'gerarRascunho'   => fn () => api()->gerarRascunho(req(['loja' => 'vendor_a', 'inicio' => '2026-10-01', 'fim' => '2026-10-04'])),
        'fatura'          => fn () => api()->fatura(req(), 'x'),
        'mudarVencimento' => fn () => api()->mudarVencimento(req(['vencimento' => '2026-10-10']), 'x'),
        'ajuste'          => fn () => api()->ajuste(req(['valor' => 1, 'motivo' => 'x']), 'x'),
        'naoCobrar'       => fn () => api()->naoCobrar(req(['lancamento_id' => 1]), 'x'),
        'emitir'          => fn () => api()->emitir(req(), 'x'),
        'cancelar'        => fn () => api()->cancelar(req(), 'x'),
        'reenviar'        => fn () => api()->reenviar(req(), 'x'),
    ];
    foreach ($chamadas as $metodo => $chamar) {
        $r = $chamar();
        confere($r->status === 403 && erroDe($r) !== '', "{$metodo}: não admin → 403");
    }
    Auth::$usuario = null;
    confere(api()->faturas(req())->status === 403, 'sem usuário: 403');
    confere(count(linhasDe('entregas_faturas')) === 0 && count(linhasDe('entregas_cobranca_lojas')) === 0, 'nada gravado');

    echo '== Lojas e ciclo' . PHP_EOL;
    preparar();
    Relogio::$agora = '2026-10-12 10:00:00'; // segunda-feira
    corridaDaLoja('vendor-a', 10, '2026-10-02', 'p1');
    corridaDaLoja('vendor-a', 12, '2026-10-06', 'p2');
    corridaDaLoja('vendor-z', 50, '2026-10-06', 'p9', 'empresa-2');
    $r = api()->lojas(req());
    $lojas = $r->dados['lojas'] ?? [];
    confere($r->status === 200 && array_column($lojas, 'id') === ['vendor_b', 'vendor_a'], 'só as lojas (type customer) da empresa, pelo nome');
    confere($lojas[1] === [
        'id' => 'vendor_a', 'nome' => 'Pizzaria Ficticia', 'saldo' => 22.0, 'em_aberto' => 0.0, 'vencido' => 0.0, 'nao_faturado' => 22.0,
        'ciclo' => ['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => 3, 'padrao' => true],
        'ciclo_atual' => ['inicio' => '2026-10-12', 'fim' => '2026-10-18'], 'email' => 'ze@pizzaria.test',
    ], 'formato Lojas: saldo, não faturado, ciclo padrão, ciclo em andamento e e-mail');
    confere($lojas[0]['email'] === null && $lojas[0]['saldo'] === 0.0, 'loja sem e-mail e sem lançamentos');

    $r = api()->salvarCiclo(req(['ciclo' => 'mensal', 'dia_fechamento' => 0, 'prazo_dias' => 5]), 'vendor_a');
    confere($r->status === 200 && ($r->dados['loja']['ciclo'] ?? null) === ['ciclo' => 'mensal', 'dia_fechamento' => 0, 'prazo_dias' => 5, 'padrao' => false], 'salva o ciclo e devolve a linha da loja');
    confere(($r->dados['loja']['ciclo_atual'] ?? null) === ['inicio' => '2026-10-01', 'fim' => '2026-10-31'] && ($r->dados['loja']['id'] ?? null) === 'vendor_a', 'com o ciclo em andamento novo');
    $r = api()->salvarCiclo(req(['ciclo' => 'quinzenal', 'prazo_dias' => 2]), 'vendor_a');
    confere($r->status === 200 && $r->dados['loja']['ciclo']['dia_fechamento'] === 0, 'quinzenal sem dia: grava 0');
    $r = api()->salvarCiclo(req(['ciclo' => 'semanal', 'dia_fechamento' => 8, 'prazo_dias' => 3]), 'vendor_a');
    confere($r->status === 422 && erroDe($r) === 'Escolha o dia da semana em que o ciclo fecha.', 'ciclo inválido: 422 com a mensagem do CicloDeCobranca');
    confere(api()->salvarCiclo(req(['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => 3]), 'vendor_z')->status === 404, 'loja de outra empresa: 404');
    confere(api()->salvarCiclo(req(['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => 3]), 'vendor_f')->status === 404, 'fornecedor (não é loja): 404');

    echo '== Extrato da loja' . PHP_EOL;
    confere(api()->extratoDaLoja(req(['inicio' => '2026-10-01', 'fim' => '2026-10-31']), 'vendor_z')->status === 404, 'loja de outra empresa: 404');
    confere(api()->extratoDaLoja(req(['inicio' => '01/10/2026', 'fim' => '2026-10-31']), 'vendor_a')->status === 422, 'data inválida: 422');
    confere(api()->extratoDaLoja(req(['inicio' => '2026-10-31', 'fim' => '2026-10-01']), 'vendor_a')->status === 422, 'início depois do fim: 422');
    confere(api()->extratoDaLoja(req(['inicio' => ['2026-10-01'], 'fim' => '2026-10-31']), 'vendor_a')->status === 422, 'data que não é texto (inicio[]=...): 422, sem TypeError');
    // o extrato com dados (faturas, recebimento) fica depois da lista de faturas

    echo '== Gerar rascunho' . PHP_EOL;
    Faturas::salvarConfig('empresa-1', 'vendor-a', ['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => 3]);
    $r = api()->gerarRascunho(req(['loja' => 'vendor_a', 'inicio' => '2026-09-28', 'fim' => '2026-10-04']));
    $fatura = $r->dados['fatura'] ?? null;
    confere($r->status === 200 && is_array($fatura) && $fatura['situacao'] === 'rascunho' && $fatura['numero'] === null && $fatura['total'] === 10.0 && $fatura['entregas'] === 1, 'cria o rascunho: { fatura }');
    confere($fatura['loja'] === ['id' => 'vendor_a', 'nome' => 'Pizzaria Ficticia'] && $fatura['vencimento'] === '2026-10-07' && array_key_exists('email_enviado_em', $fatura), 'formato Fatura do console');
    $r = api()->gerarRascunho(req(['loja' => 'vendor_a', 'inicio' => '2026-09-28', 'fim' => '2026-10-04']));
    confere($r->status === 200 && $r->dados === ['fatura' => null], 'mesmo período de novo: 200 com fatura null');
    $r = api()->gerarRascunho(req(['loja' => 'vendor_b', 'inicio' => '2026-10-01', 'fim' => '2026-10-31']));
    confere($r->status === 200 && $r->dados === ['fatura' => null], 'sem lançamentos: 200 com fatura null (não 422)');
    confere(api()->gerarRascunho(req(['inicio' => '2026-10-01', 'fim' => '2026-10-31']))->status === 422, 'sem loja: 422');
    confere(api()->gerarRascunho(req(['loja' => 'vendor_z', 'inicio' => '2026-10-01', 'fim' => '2026-10-31']))->status === 404, 'loja de outra empresa: 404');
    $r = api()->gerarRascunho(req(['loja' => 'vendor_a', 'inicio' => '2026-10-31', 'fim' => '2026-10-01']));
    confere($r->status === 422 && erroDe($r) === 'O início do período precisa ser antes do fim.', 'período invertido: 422 em pt-BR');
    confere(api()->gerarRascunho(req(['loja' => 'vendor_a', 'inicio' => '2026-10-01']))->status === 422, 'sem fim: 422');

    echo '== Fatura e conferência' . PHP_EOL;
    $id = $fatura['id'];
    $r  = api()->fatura(req(), $id);
    confere($r->status === 200 && $r->dados['fatura']['id'] === $id && count($r->dados['itens']) === 1 && $r->dados['itens'][0]['tipo'] === 'corrida' && $r->dados['itens'][0]['pedido'] === ['id' => 'order_p1', 'numero' => 'P1'], 'GET fatura: { fatura, itens }');
    confere(api()->fatura(req(), 'nao-existe')->status === 404, 'fatura que não existe: 404');
    session(['company' => 'empresa-2']);
    confere(api()->fatura(req(), $id)->status === 404, 'fatura de outra empresa: 404');
    session(['company' => 'empresa-1']);

    $r = api()->ajuste(req(['valor' => -2.5, 'motivo' => 'Desconto', 'observacao_interna' => 'combinado']), $id);
    confere($r->status === 200 && $r->dados['fatura']['total'] === 7.5 && count($r->dados['itens']) === 2, 'ajuste: devolve { fatura, itens } com o total novo');
    $ajuste = end($r->dados['itens']);
    confere($ajuste['tipo'] === 'ajuste' && $ajuste['valor'] === -2.5 && $ajuste['motivo'] === 'Desconto' && $ajuste['observacao_interna'] === 'combinado' && $ajuste['criado_por'] === ['nome' => 'Edgard'], 'ajuste com motivo, observação e quem lançou');
    confere(api()->ajuste(req(['valor' => '1,50', 'motivo' => 'Taxa']), $id)->dados['fatura']['total'] === 9.0, 'valor com vírgula');
    foreach ([
        'valor zero'   => ['valor' => 0, 'motivo' => 'x'],
        'sem valor'    => ['motivo' => 'x'],
        'valor texto'  => ['valor' => 'abc', 'motivo' => 'x'],
        'milhar 1.500' => ['valor' => '1.500', 'motivo' => 'x'],
        'sem motivo'   => ['valor' => 3],
    ] as $caso => $corpo) {
        $r = api()->ajuste(req($corpo), $id);
        confere($r->status === 422 && erroDe($r) !== '', "ajuste {$caso}: 422 ({$r->dados['errors'][0]})");
    }

    $corrida = Faturas::itens(Faturas::porUuid('empresa-1', $id))[0];
    $r = api()->naoCobrar(req(['lancamento_id' => (int) $corrida->id]), $id);
    confere($r->status === 200 && $r->dados['fatura']['total'] === -1.0, '"Não cobrar": devolve { fatura, itens } (10 − 2,50 + 1,50 − 10)');
    confere(count(array_filter($r->dados['itens'], fn ($i) => $i['tipo'] === 'ajuste' && $i['valor'] === -10.0 && $i['pedido'] === ['id' => 'order_p1', 'numero' => 'P1'])) === 1, 'com o ajuste negativo do pedido');
    $r = api()->naoCobrar(req(['lancamento_id' => (int) $corrida->id]), $id);
    confere($r->status === 422 && erroDe($r) === 'Esta entrega já está marcada como não cobrada.', '"Não cobrar" de novo: 422');
    confere(api()->naoCobrar(req(['lancamento_id' => 99999]), $id)->status === 422, 'lançamento que não existe: 422');
    confere(api()->naoCobrar(req([]), $id)->status === 422, 'sem lançamento: 422');

    $r = api()->mudarVencimento(req(['vencimento' => '2026-10-15']), $id);
    confere($r->status === 200 && $r->dados['fatura']['vencimento'] === '2026-10-15' && isset($r->dados['itens']), 'vencimento: devolve { fatura, itens }');
    confere(api()->mudarVencimento(req(['vencimento' => 'amanhã']), $id)->status === 422, 'vencimento inválido: 422');

    echo '== Emitir' . PHP_EOL;
    // total negativo (−1): não emite
    $r = api()->emitir(req(), $id);
    confere($r->status === 422 && erroDe($r) === 'O total da fatura não pode ser negativo.' && AvisosFalsos::$emitidas === [], 'total negativo: 422, sem e-mail');
    api()->ajuste(req(['valor' => 21, 'motivo' => 'Mensalidade']), $id);
    Relogio::$agora = '2026-10-12 11:00:00';
    $r = api()->emitir(req(), $id);
    confere($r->status === 200 && $r->dados['fatura']['situacao'] === 'emitida' && $r->dados['fatura']['numero'] === 'FAT-0001' && $r->dados['fatura']['total'] === 20.0, 'emite: número e total');
    confere($r->dados['fatura']['pagamento'] === 'aberta' && $r->dados['fatura']['em_aberto'] === 20.0 && $r->dados['fatura']['emitida_em'] === '2026-10-12T11:00:00-03:00' && count($r->dados['itens']) === 5, 'em aberto, emitida em, com os itens');
    confere(AvisosFalsos::$emitidas === [$id], 'manda o e-mail da fatura emitida (AvisosDaFatura::emitida)');
    $r = api()->emitir(req(), $id);
    confere($r->status === 422 && erroDe($r) === 'A fatura já foi emitida.' && count(AvisosFalsos::$emitidas) === 1, 'emitir de novo: 422, sem outro e-mail');
    foreach ([
        'ajuste'      => fn () => api()->ajuste(req(['valor' => 1, 'motivo' => 'x']), $id),
        'não cobrar'  => fn () => api()->naoCobrar(req(['lancamento_id' => (int) $corrida->id]), $id),
        'vencimento'  => fn () => api()->mudarVencimento(req(['vencimento' => '2026-10-20']), $id),
    ] as $caso => $chamar) {
        $r = $chamar();
        confere($r->status === 422 && str_contains(erroDe($r), 'emitida'), "{$caso} em fatura emitida: 422 ({$r->dados['errors'][0]})");
    }

    // e-mail que falha não desfaz a emissão
    corridaDaLoja('vendor-b', 8, '2026-10-03', 'p3');
    $outra = api()->gerarRascunho(req(['loja' => 'vendor_b', 'inicio' => '2026-10-01', 'fim' => '2026-10-11']))->dados['fatura']['id'];
    AvisosFalsos::$falha = new \RuntimeException('SMTP fora do ar');
    $r = api()->emitir(req(), $outra);
    AvisosFalsos::$falha = null;
    confere($r->status === 200 && $r->dados['fatura']['numero'] === 'FAT-0002', 'e-mail com erro: a fatura sai emitida mesmo assim');
    confere(logou('[entregas] financeiro: e-mail da fatura falhou', 'warning'), 'e loga o erro do e-mail');
    FinanceiroLojasController::$avisos = 'App\\Support\\Entregas\\Financeiro\\ClasseQueNaoExiste';
    corridaDaLoja('vendor-b', 4, '2026-10-12', 'p4');
    $terceira = api()->gerarRascunho(req(['loja' => 'vendor_b', 'inicio' => '2026-10-12', 'fim' => '2026-10-12']))->dados['fatura']['id'];
    confere(api()->emitir(req(), $terceira)->status === 200, 'sem a classe dos e-mails (B4 fora): emite sem enviar');

    echo '== Reenviar' . PHP_EOL;
    confere(api()->reenviar(req(), $id)->dados === ['enviado' => false, 'motivo' => 'falha'], 'sem a classe dos e-mails: { enviado: false, motivo: falha }');
    FinanceiroLojasController::$avisos = AvisosFalsos::class;
    $r = api()->reenviar(req(), $id);
    confere($r->status === 200 && $r->dados === ['enviado' => true] && AvisosFalsos::$emitidas[array_key_last(AvisosFalsos::$emitidas)] === $id, 'reenvia: { enviado: true }');
    AvisosFalsos::$resposta = false;
    AvisosFalsos::$emails = [];
    confere(api()->reenviar(req(), $id)->dados === ['enviado' => false, 'motivo' => 'sem_email'], 'loja sem e-mail: { enviado: false, motivo: sem_email }');
    AvisosFalsos::$emails = ['ze@pizzaria.test'];
    confere(api()->reenviar(req(), $id)->dados === ['enviado' => false, 'motivo' => 'falha'], 'com e-mail e sem envio (SMTP, no log): { enviado: false, motivo: falha }');
    Config::$valores['services.entregas.financeiro'] = '';
    confere(api()->reenviar(req(), $id)->dados === ['enviado' => false, 'motivo' => 'desligado'], 'ENTREGAS_FINANCEIRO desligado: { enviado: false, motivo: desligado }');
    Config::$valores['services.entregas.financeiro'] = '1';
    AvisosFalsos::$resposta = true;
    AvisosFalsos::$falha = new \RuntimeException('SMTP fora do ar');
    $r = api()->reenviar(req(), $id);
    AvisosFalsos::$falha = null;
    confere($r->status === 502 && erroDe($r) === 'Não foi possível enviar o e-mail.', 'erro no envio: 502 em pt-BR');
    $rascunho = Faturas::gerarRascunho('empresa-1', 'vendor-a', '2026-10-05', '2026-10-11', null);
    $r = api()->reenviar(req(), $rascunho->uuid);
    confere($r->status === 422 && erroDe($r) !== '', 'rascunho não vai por e-mail: 422');
    confere(api()->reenviar(req(), 'nao-existe')->status === 404, 'fatura que não existe: 404');
    confere(FinanceiroLojasController::AVISOS_PADRAO === 'App\\Support\\Entregas\\Financeiro\\AvisosDaFatura', 'a classe dos e-mails é a AvisosDaFatura (B4)');

    echo '== Lista de faturas' . PHP_EOL;
    Pagamentos::registrar('empresa-1', 'loja', 'vendor-a', 5, 'pix', '2026-10-12', null, null, null);
    $r = api()->faturas(req());
    $lista = $r->dados['faturas'] ?? [];
    confere($r->status === 200 && count($lista) === 4, 'todas as faturas da empresa');
    confere($lista[0]['id'] === $terceira && $lista[1]['id'] === $rascunho->uuid && $lista[3]['id'] === $id, 'mais nova primeiro (fim do período)');
    $porId = array_column($lista, null, 'id');
    confere($porId[$id]['pagamento'] === 'parcial' && $porId[$id]['recebido'] === 5.0 && $porId[$id]['em_aberto'] === 15.0 && $porId[$id]['loja']['nome'] === 'Pizzaria Ficticia', 'com a situação calculada e a loja');
    confere($porId[$rascunho->uuid]['situacao'] === 'rascunho' && $porId[$rascunho->uuid]['total'] === 12.0, 'rascunho com o total atual');
    $ids = fn ($resposta) => array_column($resposta->dados['faturas'] ?? [], 'id');
    confere($ids(api()->faturas(req(['situacao' => 'rascunho']))) === [$rascunho->uuid], 'filtro rascunho');
    confere($ids(api()->faturas(req(['loja' => 'vendor_a']))) === [$rascunho->uuid, $id], 'filtro loja');
    confere($ids(api()->faturas(req(['situacao' => 'aberta', 'loja' => 'vendor_a']))) === [$id], 'filtro aberta (inclui parcial)');
    confere($ids(api()->faturas(req(['situacao' => 'vencida']))) === [], 'filtro vencida: nenhuma ainda');
    Relogio::$agora = '2026-10-16 09:00:00';
    confere($ids(api()->faturas(req(['situacao' => 'vencida']))) === [$terceira, $outra, $id], 'depois dos vencimentos (14 e 15/10): vencidas');
    Pagamentos::registrar('empresa-1', 'loja', 'vendor-b', 12, 'pix', '2026-10-16', null, null, null);
    confere($ids(api()->faturas(req(['situacao' => 'vencida']))) === [$id], 'a loja B pagou: só a da loja A continua vencida');
    confere($ids(api()->faturas(req(['situacao' => 'paga']))) === [$terceira, $outra], 'filtro paga');
    confere(api()->faturas(req(['situacao' => 'qualquer']))->status === 422, 'situação desconhecida: 422');
    confere(api()->faturas(req(['loja' => 'vendor_z']))->status === 404, 'loja de outra empresa: 404');

    echo '== Extrato da loja com dados (ExtratoDaConta)' . PHP_EOL;
    // loja A: FAT-0001 emitida (corrida p1 10, ajustes −2,50, +1,50 e +21, "Não cobrar" −10), corrida p2 12 no rascunho
    // de 05 a 11/10 e o recebimento de 5 em 12/10
    $r = api()->extratoDaLoja(req(['inicio' => '2026-10-01', 'fim' => '2026-10-31']), 'vendor_a');
    $extrato = $r->dados;
    confere($r->status === 200 && $extrato['conta'] === ['tipo' => 'loja', 'id' => 'vendor_a', 'nome' => 'Pizzaria Ficticia'], 'conta da loja: tipo, public_id e nome, sem pix');
    confere($extrato['inicio'] === '2026-10-01' && $extrato['fim'] === '2026-10-31' && $extrato['saldo'] === 27.0 && $extrato['saldo_anterior'] === 0.0, 'saldo de todos os lançamentos (10 − 2,50 + 1,50 − 10 + 21 + 12 − 5)');
    confere($extrato['totais'] === ['creditos' => 44.5, 'debitos' => 17.5], 'totais do período, os dois positivos');
    confere(($extrato['ultimo_pagamento']['valor'] ?? null) === 5.0 && $extrato['ultimo_pagamento']['data'] === '2026-10-12' && $extrato['ultimo_pagamento']['forma'] === 'pix', 'último recebimento');
    $porTipo = fn (string $tipo) => array_values(array_filter($extrato['lancamentos'], fn ($l) => $l['tipo'] === $tipo));
    confere(count($extrato['lancamentos']) === 7 && $extrato['lancamentos'][0]['tipo'] === 'recebimento', '7 lançamentos, o mais novo primeiro');
    $recebimento = $porTipo('recebimento')[0];
    confere($recebimento['valor'] === -5.0 && $recebimento['fatura'] === null && ($recebimento['pagamento']['forma'] ?? null) === 'pix' && $recebimento['pagamento']['tem_comprovante'] === false
        && array_key_exists('comprovante_url', $recebimento['pagamento']) && $recebimento['situacao'] === null, 'recebimento: débito, sem fatura, com o pagamento (console: comprovante_url)');
    $corridas = array_column($porTipo('corrida'), null, 'valor');
    confere(($corridas['10']['fatura'] ?? null) === ['id' => $id, 'numero' => 'FAT-0001'] && ($corridas['12']['fatura'] ?? null) === ['id' => $rascunho->uuid, 'numero' => null], 'coluna Fatura: emitida com número, rascunho sem número');
    confere(($corridas['10']['pedido'] ?? null) === ['id' => 'order_p1', 'numero' => 'P1'] && ($corridas['10']['detalhes']['km'] ?? null) === 2.5, 'corrida com o pedido e os detalhes');
    confere(($corridas['10']['situacao'] ?? null) === 'anulado', 'a corrida do "Não cobrar" fica anulada no extrato');
    confere(array_key_exists('observacao_interna', $extrato['lancamentos'][0]) && array_key_exists('criado_por', $extrato['lancamentos'][0]), 'console: com observação interna e quem lançou');
    $r = api()->extratoDaLoja(req(['inicio' => '2026-10-05', 'fim' => '2026-10-31']), 'vendor_a');
    confere($r->dados['saldo_anterior'] === 20.0 && count($r->dados['lancamentos']) === 2 && $r->dados['saldo'] === 27.0, 'saldo anterior ao início do período; a lista só do período');
    $r = api()->extratoDaLoja(req(), 'vendor_a');
    confere($r->status === 200 && $r->dados['inicio'] === null && count($r->dados['lancamentos']) === 7, 'sem período: a conta inteira');
    $r = api()->extratoDaLoja(req(['inicio' => '2026-10-01', 'fim' => '2026-10-31']), 'vendor_b');
    confere($r->dados['conta']['id'] === 'vendor_b' && count($r->dados['lancamentos']) === 3 && $r->dados['saldo'] === 0.0, 'a loja B: só os dela (2 corridas e o recebimento de 12)');

    echo '== Cancelar' . PHP_EOL;
    $r = api()->cancelar(req(), $id);
    confere($r->status === 200 && $r->dados['fatura']['situacao'] === 'cancelada' && $r->dados['itens'] === [], 'cancela: { fatura, itens } sem itens');
    confere(api()->cancelar(req(), $id)->status === 422, 'cancelar de novo: 422');
    confere($ids(api()->faturas(req(['situacao' => 'cancelada']))) === [$id], 'filtro cancelada');
    $loja = array_column(api()->lojas(req())->dados['lojas'], null, 'id')['vendor_a'];
    confere($loja['nao_faturado'] === 20.0 && $loja['em_aberto'] === 0.0, 'na lista de lojas, os lançamentos soltos voltam ao não faturado');

    echo '== Comando entregas:financeiro-rascunhos' . PHP_EOL;
    preparar();
    Faturas::salvarConfig('empresa-1', 'vendor-b', ['ciclo' => 'mensal', 'dia_fechamento' => 5, 'prazo_dias' => 10]);
    corridaDaLoja('vendor-a', 10, '2026-10-08', 'q1');
    corridaDaLoja('vendor-a', 7, '2026-10-12', 'q2');
    corridaDaLoja('vendor-b', 9, '2026-10-08', 'q3');
    corridaDaLoja('vendor-z', 3, '2026-10-08', 'q4', 'empresa-2');
    Pagamentos::registrar('empresa-1', 'loja', 'vendor-b', 4, 'pix', '2026-10-08', null, null, null);
    Relogio::$agora = '2026-10-12 00:30:00'; // ontem = domingo 11/10: fecha o semanal (padrão), não o mensal do dia 5
    (new FinanceiroRascunhos())->handle();
    $faturas = linhasDe('entregas_faturas');
    confere(count($faturas) === 2, 'cria um rascunho para cada loja cujo ciclo fechou ontem (as duas empresas, ciclo padrão)');
    $daA = array_values(array_filter($faturas, fn ($f) => $f['vendor_uuid'] === 'vendor-a'))[0] ?? null;
    confere($daA && $daA['periodo_inicio'] === '2026-10-05' && $daA['periodo_fim'] === '2026-10-11' && $daA['vencimento'] === '2026-10-14' && $daA['situacao'] === 'rascunho', 'período da semana que fechou ontem, vencimento + 3');
    confere(array_map(fn ($l) => (float) $l->valor, Faturas::itens((object) $daA)) === [10.0], 'só os lançamentos até ontem');
    confere(!array_filter($faturas, fn ($f) => $f['vendor_uuid'] === 'vendor-b'), 'loja com ciclo mensal (dia 5): nada');
    (new FinanceiroRascunhos())->handle();
    confere(count(linhasDe('entregas_faturas')) === 2, 'rodar de novo não duplica');
    Relogio::$agora = '2026-10-13 00:30:00'; // ontem = segunda: ninguém fecha
    (new FinanceiroRascunhos())->handle();
    confere(count(linhasDe('entregas_faturas')) === 2, 'dia que não fecha ciclo: nada');
    Relogio::$agora = '2026-11-06 00:30:00'; // ontem = 05/11: fecha o mensal da loja B
    (new FinanceiroRascunhos())->handle();
    $daB = array_values(array_filter(linhasDe('entregas_faturas'), fn ($f) => $f['vendor_uuid'] === 'vendor-b'))[0] ?? null;
    confere($daB && $daB['periodo_inicio'] === '2026-10-06' && $daB['periodo_fim'] === '2026-11-05' && $daB['vencimento'] === '2026-11-15', 'ciclo mensal fecha no dia 5 (de 06/10 a 05/11)');
    Config::$valores['services.entregas.financeiro'] = '';
    Relogio::$agora = '2026-10-19 00:30:00';
    corridaDaLoja('vendor-a', 1, '2026-10-14', 'q5');
    (new FinanceiroRascunhos())->handle();
    confere(count(linhasDe('entregas_faturas')) === 3, 'com ENTREGAS_FINANCEIRO desligado: não faz nada');
    Config::$valores['services.entregas.financeiro'] = '1';
    Banco::$falhar['entregas_faturas'] = 'banco fora do ar';
    (new FinanceiroRascunhos())->handle();
    unset(Banco::$falhar['entregas_faturas']);
    confere(logou('[entregas] financeiro: rascunho da fatura falhou', 'warning'), 'falha numa loja: loga e segue');

    echo '== Rotas e agenda' . PHP_EOL;
    $provider = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
    confere(str_contains($provider, 'use App\\Http\\Controllers\\Entregas\\FinanceiroLojasController;'), 'RouteServiceProvider importa o controller');
    $rotas = [
        ['get', 'financeiro/lojas', 'lojas'],
        ['put', 'financeiro/lojas/{id}/ciclo', 'salvarCiclo'],
        ['get', 'financeiro/lojas/{id}/extrato', 'extratoDaLoja'],
        ['get', 'financeiro/faturas', 'faturas'],
        ['post', 'financeiro/faturas', 'gerarRascunho'],
        ['get', 'financeiro/faturas/{id}', 'fatura'],
        ['put', 'financeiro/faturas/{id}', 'mudarVencimento'],
        ['post', 'financeiro/faturas/{id}/ajustes', 'ajuste'],
        ['post', 'financeiro/faturas/{id}/nao-cobrar', 'naoCobrar'],
        ['post', 'financeiro/faturas/{id}/emitir', 'emitir'],
        ['post', 'financeiro/faturas/{id}/cancelar', 'cancelar'],
        ['post', 'financeiro/faturas/{id}/reenviar', 'reenviar'],
    ];
    // as rotas do console ficam no grupo int/v1/entregas (fleetbase.protected), antes do grupo do app do motoboy
    $grupoDoConsole = substr($provider, (int) strpos($provider, "Route::prefix('int/v1/entregas')"), max(0, (int) strpos($provider, "Route::prefix('v1/entregas/motoboy')") - (int) strpos($provider, "Route::prefix('int/v1/entregas')")));
    foreach ($rotas as [$verbo, $caminho, $metodo]) {
        $linha = "Route::{$verbo}('{$caminho}', [FinanceiroLojasController::class, '{$metodo}'])";
        confere(str_contains($grupoDoConsole, $linha) && method_exists(FinanceiroLojasController::class, $metodo), strtoupper($verbo) . " {$caminho} → {$metodo}");
    }

    resumo();
}
