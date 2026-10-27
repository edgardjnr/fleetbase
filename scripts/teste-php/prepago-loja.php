<?php

// Loja pré-paga: o modelo de cobrança (PrePago: CPF/CNPJ, definir), a carteira (CarteiraDaLoja: crédito, reservado,
// bloqueio do pedido novo), o efeito no Financeiro (sem fatura nem rascunho; modelo na lista das lojas), o controller do
// portal (CarteiraDaLojaController: só a loja da sessão), o cadastro no LojasController, as rotas e o RegrasPortalLoja.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/prepago-loja.php

namespace {
    require __DIR__ . '/stubs-saque.php';
}

namespace Teste {
    class Abortado extends \RuntimeException
    {
        public function __construct(public int $status, string $mensagem = '') { parent::__construct($mensagem); }
    }
}

namespace App\Support\Entregas {
    // a loja do usuário do portal: usuario-a → vendor-a, usuario-b → vendor-b, os demais sem loja
    class LojaDoUsuario
    {
        public static function vendor(?string $userUuid)
        {
            return match ($userUuid) {
                'usuario-a' => new \Fleetbase\FleetOps\Models\Vendor(['uuid' => 'vendor-a', 'public_id' => 'vendor_a', 'company_uuid' => 'empresa-1', 'name' => 'Pizzaria do Zé', 'place_uuid' => 'place-a']),
                'usuario-b' => new \Fleetbase\FleetOps\Models\Vendor(['uuid' => 'vendor-b', 'public_id' => 'vendor_b', 'company_uuid' => 'empresa-1', 'name' => 'Burger Hub', 'place_uuid' => 'place-b']),
                default     => null,
            };
        }

        public static function contato(?string $userUuid) { return null; }
    }
}

namespace {
    use App\Console\Commands\Entregas\FinanceiroRascunhos;
    use App\Http\Controllers\Entregas\CarteiraDaLojaController;
    use App\Http\Controllers\Entregas\LojasController;
    use App\Support\Entregas\Financeiro\Faturas;
    use App\Support\Entregas\Financeiro\Lancamentos;
    use App\Support\Entregas\Financeiro\Pagamentos;
    use App\Support\Entregas\PrePago\CarteiraDaLoja;
    use App\Support\Entregas\PrePago\PrePago;
    use Fleetbase\FleetOps\Models\Vendor;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;
    use Teste\Abortado;
    use Teste\Http as HttpFalso;

    function abort_if($condicao, $status, $mensagem = '')
    {
        if ($condicao) {
            throw new Abortado($status, $mensagem);
        }
    }

    function erroDe(callable $fazer): ?string
    {
        try {
            $fazer();
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    function corrida(string $loja, string $pedido, float $valor, string $dia = '2026-10-26'): void
    {
        Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'loja', 'conta_uuid' => $loja, 'tipo' => 'corrida', 'valor' => $valor, 'data_referencia' => $dia, 'order_uuid' => $pedido, 'chave_unica' => 'corrida:loja:' . $pedido]);
    }

    function recebimento(string $loja, float $valor): object
    {
        return Pagamentos::registrar('empresa-1', 'loja', $loja, $valor, 'pix', '2026-10-26', null, 'Recarga Pix pelo portal', null);
    }

    /** Pedido da loja em andamento no banco em memória, com o valor congelado (loja) de $valor (null = sem congelado). */
    function pedidoEmAndamento(string $uuid, string $loja, ?float $valor, string $status = 'started', string $mexido = '2026-10-27 09:30:00'): void
    {
        DB::table('orders')->insert(['uuid' => $uuid, 'company_uuid' => 'empresa-1', 'customer_uuid' => $loja, 'status' => $status, 'updated_at' => $mexido, 'deleted_at' => null]);
        if ($valor !== null) {
            DB::table('entregas_valores_pedido')->insert(['company_uuid' => 'empresa-1', 'order_uuid' => $uuid, 'valor_loja' => $valor, 'valor_motoboy' => 1, 'created_at' => $mexido, 'updated_at' => $mexido]);
        }
    }

    function faixas(array $precos): void
    {
        \Fleetbase\Models\Setting::$valores['company.empresa-1.entregas.faixas'] = array_map(fn ($p, $i) => ['ate_km' => ($i + 1) * 3, 'motoboy' => 5, 'loja' => $p], $precos, array_keys($precos));
    }

    function comoUsuario(string $usuario): void
    {
        session(['company' => 'empresa-1', 'user' => $usuario]);
    }

    function iniciar(): void
    {
        centralComSaque();
        \Teste\Config::$valores['services.entregas.recarga'] = '1';
        faixas([8.0, 12.0]);
        comoUsuario('usuario-a');
        HttpFalso::$chamadas = [];
    }

    echo '== CPF e CNPJ' . PHP_EOL;
    foreach (['529.982.247-25' => '52998224725', '11.222.333/0001-81' => '11222333000181', '11222333000181' => '11222333000181', ' 52998224725 ' => '52998224725'] as $entrada => $saida) {
        confere(PrePago::documento($entrada) === $saida, "válido: {$entrada}");
    }
    foreach (['52998224724', '11222333000182', '11111111111', '00000000000000', '1234567890', '123', '', null, ['52998224725'], 52998224725.5] as $entrada) {
        confere(PrePago::documento($entrada) === null, 'inválido: ' . json_encode($entrada));
    }

    echo '== Definir o modelo' . PHP_EOL;
    iniciar();
    confere(PrePago::modelo('empresa-1', 'vendor-a') === 'pos', 'sem linha: pós-pago (o padrão)');
    confere(erroDe(fn () => PrePago::definir('empresa-1', 'vendor-a', 'pre', null, 'user-admin')) === 'Informe o CPF ou CNPJ da loja: o Pix da recarga sai no nome dela.', 'pré sem documento: recusa');
    confere(erroDe(fn () => PrePago::definir('empresa-1', 'vendor-a', 'pre', '11222333000182', 'user-admin')) === 'CPF ou CNPJ inválido.', 'documento inválido: recusa');
    confere(erroDe(fn () => PrePago::definir('empresa-1', 'vendor-a', 'gratis', null, 'user-admin')) === 'Escolha o modelo de cobrança: pré-pago ou pós-pago.', 'modelo desconhecido: recusa');
    PrePago::definir('empresa-1', 'vendor-a', 'pos', null, 'user-admin');
    confere(DB::table('entregas_carteiras_lojas')->count() === 0, 'pós-pago sem documento: nem cria linha');
    \Teste\Relogio::$agora = '2026-10-27 10:00:00';
    PrePago::definir('empresa-1', 'vendor-a', 'pre', '11.222.333/0001-81', 'user-admin');
    $linha = PrePago::linha('empresa-1', 'vendor-a');
    confere($linha->modelo === 'pre' && $linha->documento === '11222333000181' && $linha->modelo_alterado_por === 'user-admin' && $linha->modelo_alterado_em === '2026-10-27 10:00:00', 'pré-pago gravado com os dígitos e quem mudou');
    confere(PrePago::ehPrePaga('empresa-1', 'vendor-a') && !PrePago::ehPrePaga('empresa-1', 'vendor-b') && !PrePago::ehPrePaga('empresa-2', 'vendor-a'), 'modelo por loja e por empresa');
    \Teste\Relogio::$agora = '2026-10-27 11:00:00';
    PrePago::definir('empresa-1', 'vendor-a', 'pre', '', 'user-outro');
    $linha = PrePago::linha('empresa-1', 'vendor-a');
    confere($linha->documento === '11222333000181' && $linha->modelo_alterado_por === 'user-admin', 'documento vazio mantém o gravado; sem mudança de modelo, quem mudou fica');
    PrePago::definir('empresa-1', 'vendor-a', 'pos', null, 'user-outro');
    confere(PrePago::linha('empresa-1', 'vendor-a')->modelo === 'pos' && PrePago::linha('empresa-1', 'vendor-a')->modelo_alterado_por === 'user-outro' && DB::table('entregas_carteiras_lojas')->count() === 1, 'volta ao pós-pago: a linha fica (com o documento)');
    confere(PrePago::lojasPrePagas('empresa-1') === [], 'lista das pré-pagas vazia');
    PrePago::definir('empresa-1', 'vendor-a', 'pre', null, 'user-admin');
    confere(PrePago::lojasPrePagas('empresa-1') === ['vendor-a' => true], 'pré de novo, com o documento já gravado');
    \Teste\Banco::$falharAoConsultar['entregas_carteiras_lojas'] = new \RuntimeException('tabela não existe');
    confere(PrePago::modelo('empresa-1', 'vendor-a') === 'pos' && PrePago::lojasPrePagas('empresa-1') === [], 'sem a tabela (API antes da migration): pós-pago, como era');
    unset(\Teste\Banco::$falharAoConsultar['entregas_carteiras_lojas']);

    echo '== Carteira: crédito, reservado e bloqueio' . PHP_EOL;
    iniciar();
    confere(CarteiraDaLoja::resumo('empresa-1', 'vendor-a') === ['modelo' => 'pos'] && CarteiraDaLoja::motivoDoBloqueio('empresa-1', 'vendor-a') === null, 'pós-pago: só o modelo, nunca bloqueia');
    PrePago::definir('empresa-1', 'vendor-a', 'pre', '11222333000181', 'user-admin');
    $c = CarteiraDaLoja::resumo('empresa-1', 'vendor-a');
    confere($c === ['modelo' => 'pre', 'credito' => 0.0, 'reservado' => 0.0, 'disponivel' => 0.0, 'minimo_para_pedir' => 8.0, 'pode_pedir' => false, 'recarga' => ['ligada' => true, 'minimo' => 100.0, 'maximo' => 5000.0]], 'pré sem crédito: ' . json_encode($c));
    confere(CarteiraDaLoja::motivoDoBloqueio('empresa-1', 'vendor-a') === 'Crédito insuficiente. Recarregue a carteira (Minha carteira) para pedir entregas.', 'sem crédito: bloqueia o pedido novo');
    recebimento('vendor-a', 50);
    corrida('vendor-a', 'pedido-1', 12.5);
    pedidoEmAndamento('pedido-2', 'vendor-a', 10);
    pedidoEmAndamento('pedido-3', 'vendor-a', null);
    pedidoEmAndamento('pedido-4', 'vendor-a', 99, 'completed');
    pedidoEmAndamento('pedido-5', 'vendor-a', 99, 'started', '2026-10-26 20:00:00');
    pedidoEmAndamento('pedido-6', 'vendor-b', 99);
    $c = CarteiraDaLoja::resumo('empresa-1', 'vendor-a');
    confere($c['credito'] === 37.5 && $c['reservado'] === 18.0 && $c['disponivel'] === 19.5 && $c['pode_pedir'] === true, 'R$ 50 − 12,50 da corrida = 37,50; reservado = R$ 10 congelado + R$ 8 (a mais barata) do pedido ainda sem valor, só os em andamento da loja nas últimas 12 h: ' . json_encode($c));
    pedidoEmAndamento('pedido-7', 'vendor-a', 15);
    $c = CarteiraDaLoja::resumo('empresa-1', 'vendor-a');
    confere($c['disponivel'] === 4.5 && $c['pode_pedir'] === false && CarteiraDaLoja::motivoDoBloqueio('empresa-1', 'vendor-a') !== null, 'disponível abaixo da entrega mais barata (R$ 8): bloqueia');
    faixas([5.0, 12.0]);
    confere(CarteiraDaLoja::resumo('empresa-1', 'vendor-a')['disponivel'] === 7.5 && CarteiraDaLoja::resumo('empresa-1', 'vendor-a')['pode_pedir'] === true, 'com a entrega mais barata a R$ 5 (o sem valor reserva 5): libera');
    session(['company' => null]);
    confere(CarteiraDaLoja::resumo('empresa-1', 'vendor-a')['minimo_para_pedir'] === 5.0, 'a tabela de km sai da empresa informada, sem depender da sessão (o RegrasPortalLoja roda antes dela)');
    comoUsuario('usuario-a');
    $seguidos = 0;
    for ($i = 0; $i < 10 && CarteiraDaLoja::motivoDoBloqueio('empresa-1', 'vendor-a') === null; $i++) {
        pedidoEmAndamento('seguido-' . $i, 'vendor-a', null);
        $seguidos++;
    }
    confere($seguidos === 1, 'pedidos em sequência sem motoboy: cada um reserva a entrega mais barata, e só passa o que o crédito cobre');
    DB::table('orders')->where('uuid', 'seguido-0')->update(['status' => 'canceled']);
    faixas([]);
    confere(CarteiraDaLoja::resumo('empresa-1', 'vendor-a')['minimo_para_pedir'] === 0.0 && CarteiraDaLoja::resumo('empresa-1', 'vendor-a')['pode_pedir'] === true, 'sem tabela de km: basta ter crédito');
    corrida('vendor-a', 'pedido-8', 40);
    $c = CarteiraDaLoja::resumo('empresa-1', 'vendor-a');
    confere($c['credito'] === -2.5 && $c['pode_pedir'] === false, 'iFood passou do crédito: devendo R$ 2,50, bloqueia');

    echo '== Financeiro: pré-paga sem fatura' . PHP_EOL;
    iniciar();
    PrePago::definir('empresa-1', 'vendor-a', 'pre', '11222333000181', 'user-admin');
    // as duas no ciclo semanal que fechou no domingo 25/10
    corrida('vendor-a', 'pedido-1', 12.5, '2026-10-20');
    corrida('vendor-b', 'pedido-2', 9, '2026-10-20');
    confere(erroDe(fn () => Faturas::gerarRascunho('empresa-1', 'vendor-a', '2026-10-20', '2026-10-26', null)) === 'Loja pré-paga não recebe fatura: as corridas saem do crédito da carteira.', 'rascunho à mão da pré-paga: recusa');
    \Teste\Relogio::$agora = '2026-10-27 00:30:00';
    (new FinanceiroRascunhos())->handle();
    $faturas = DB::table('entregas_faturas')->get()->all();
    confere(count($faturas) === 1 && $faturas[0]->vendor_uuid === 'vendor-b', 'a rotina gera o rascunho só da pós-paga: ' . json_encode(array_column($faturas, 'vendor_uuid')));
    confere(!in_array('[entregas] financeiro: rascunho da fatura falhou', array_column(\Illuminate\Support\Facades\Log::$registros, 1), true), 'sem warning de falha para a pré-paga (ela é pulada)');
    $vendors = [new Vendor(['uuid' => 'vendor-a', 'public_id' => 'vendor_a', 'company_uuid' => 'empresa-1', 'name' => 'Pizzaria']), new Vendor(['uuid' => 'vendor-b', 'public_id' => 'vendor_b', 'company_uuid' => 'empresa-1', 'name' => 'Burger', 'email' => 'b@x.com'])];
    $linhas = Faturas::linhasDasLojas('empresa-1', $vendors);
    confere(array_column($linhas, 'modelo', 'id') === ['vendor_b' => 'pos', 'vendor_a' => 'pre'] && array_column($linhas, 'saldo', 'id')['vendor_a'] === 12.5, 'lista das lojas com o modelo (e o saldo da pré-paga)');

    echo '== Portal: carteira' . PHP_EOL;
    iniciar();
    $portal = new CarteiraDaLojaController();
    $r = $portal->index();
    confere($r->status === 200 && $r->dados === ['carteira' => ['modelo' => 'pos'], 'em_andamento' => null, 'recargas' => []], 'loja pós-paga: só o modelo');
    $r = $portal->pedir(new Request(['valor' => 150]));
    confere($r->status === 422 && $r->dados === ['errors' => ['Esta loja não usa a carteira pré-paga.']] && HttpFalso::$chamadas === [], 'pós-paga pedindo Pix: 422 sem chamar o Asaas');
    PrePago::definir('empresa-1', 'vendor-a', 'pre', '11222333000181', 'user-admin');
    asaas(200, ['data' => [['id' => 'wallet-1']]]);
    asaas(200, ['id' => 'cus_1']);
    HttpFalso::$respostas[] = fn () => [200, ['id' => 'pay_1', 'customer' => 'cus_1', 'value' => 150, 'billingType' => 'PIX', 'status' => 'PENDING', 'externalReference' => DB::table('entregas_recargas')->value('external_reference')]];
    asaas(200, ['encodedImage' => 'iVBORw0KGgo=', 'payload' => '000201pix']);
    $r = $portal->pedir(new Request(['valor' => '150,00']));
    $recarga = $r->dados['recarga'] ?? [];
    confere($r->status === 201 && $recarga['situacao'] === 'aguardando' && $recarga['pix'] === ['copia_e_cola' => '000201pix', 'imagem' => 'iVBORw0KGgo='] && ($r->dados['carteira']['modelo'] ?? null) === 'pre', 'pedir: 201 com o QR e a carteira: ' . json_encode($r->dados));
    confere(DB::table('entregas_recargas')->value('pedida_por') === 'usuario-a', 'quem pediu fica gravado');
    $id = $recarga['id'];
    confere($portal->mostrar($id)->dados['recarga']['id'] === $id, 'mostrar a da própria loja');
    $chamadas = count(HttpFalso::$chamadas);
    $index = $portal->index()->dados;
    confere(($index['em_andamento']['id'] ?? null) === $id && $index['em_andamento']['pix'] !== null && array_key_exists('pix', $index['recargas'][0] ?? []) && $index['recargas'][0]['pix'] === null && count(HttpFalso::$chamadas) === $chamadas, 'index: a aguardando com o QR, a lista sem o QR, sem consultar o Asaas em menos de 5 s');
    comoUsuario('usuario-b');
    confere($portal->mostrar($id)->status === 404 && $portal->mostrar($id)->dados === ['errors' => ['Recarga não encontrada.']], 'outra loja: 404');
    confere($portal->cancelar($id)->status === 404, 'outra loja não cancela: 404');
    comoUsuario('usuario-a');
    foreach (['../../x', 'pay_1', '', str_repeat('a', 300), 'AAAAAAAA-0000-4000-8000-00000000000Z'] as $ruim) {
        confere($portal->mostrar($ruim)->status === 404, 'id malformado: 404 (' . substr($ruim, 0, 20) . ')');
    }
    DB::table('entregas_recargas')->update(['company_uuid' => 'empresa-2']);
    confere($portal->mostrar($id)->status === 404, 'recarga de outra empresa: 404');
    DB::table('entregas_recargas')->update(['company_uuid' => 'empresa-1']);
    asaas(200, ['deleted' => true]);
    $r = $portal->cancelar($id);
    confere($r->status === 200 && $r->dados['recarga']['situacao'] === 'expirada' && $r->dados['recarga']['motivo'] === 'Pix cancelado.' && $r->dados['recarga']['pix'] === null, 'cancelar: expirada, sem o QR');
    comoUsuario('usuario-sem-loja');
    $erro = null;
    try {
        $portal->index();
    } catch (Abortado $e) {
        $erro = $e->status;
    }
    confere($erro === 404, 'usuário sem loja: 404');
    comoUsuario('usuario-a');
    asaas(200, ['data' => [['id' => 'wallet-1']]]);
    asaas(400, ['errors' => [['description' => 'Conta sem chave Pix.']]]);
    $r = $portal->pedir(new Request(['valor' => 130]));
    confere($r->status === 502 && $r->dados === ['errors' => ['Não foi possível gerar o Pix agora. Tente de novo ou fale com a central.']], 'o Asaas não gerou: 502 com a frase nossa (o texto do Asaas não vai para a loja)');
    unlink(CHAVE_DO_COFRE);
    confere($portal->pedir(new Request(['valor' => 130]))->status === 503, 'sem o secret: 503');

    echo '== Cadastro da loja (LojasController)' . PHP_EOL;
    iniciar();
    \Fleetbase\Support\Auth::$usuario = new \Teste\UsuarioDoSaque('user-admin', 'admin');
    $lojas = new class extends LojasController {
        public function cobranca(Vendor $vendor, array $dados): void { $this->salvarCobranca($vendor, $dados, new Request([])); }
        public function formato(Vendor $vendor): array { return $this->cobrancaDaLoja($vendor); }
        public function emLote(): array { return $this->carteirasDaEmpresa(); }
    };
    $vendorA = new Vendor(['uuid' => 'vendor-a', 'public_id' => 'vendor_a', 'company_uuid' => 'empresa-1', 'name' => 'Pizzaria']);
    confere($lojas->formato($vendorA) === ['modelo' => 'pos', 'documento' => null], 'loja sem linha: pós-pago sem documento');
    $lojas->cobranca($vendorA, ['nome' => 'Pizzaria']);
    confere(DB::table('entregas_carteiras_lojas')->count() === 0, 'sem cobranca.modelo no corpo: não muda nada');
    $lojas->cobranca($vendorA, ['cobranca' => ['modelo' => 'pre', 'documento' => '529.982.247-25']]);
    confere($lojas->formato($vendorA) === ['modelo' => 'pre', 'documento' => '52998224725'] && PrePago::linha('empresa-1', 'vendor-a')->modelo_alterado_por === 'user-admin', 'pré com CPF: gravado com quem mudou');
    confere(array_keys($lojas->emLote()) === ['vendor-a'], 'as linhas da empresa de uma vez (index)');
    $codigo = file_get_contents(dirname(__DIR__, 2) . '/api/app/Http/Controllers/Entregas/LojasController.php');
    confere(substr_count($codigo, '$this->salvarCobranca($vendor, $dados, $request);') === 2 && str_contains($codigo, "'cobranca.modelo'       => ['nullable', 'in:pos,pre']") && str_contains($codigo, "'cobranca' => \$this->cobrancaDaLoja(\$vendor)"), 'store e update gravam a cobrança na mesma transação, validada, e a loja sai com ela');

    echo '== Rotas, portal e implantação' . PHP_EOL;
    $raiz   = dirname(__DIR__, 2);
    $rotas  = file_get_contents($raiz . '/api/app/Providers/RouteServiceProvider.php');
    confere(str_contains($rotas, "use App\\Http\\Controllers\\Entregas\\CarteiraDaLojaController;"), 'use do controller no RouteServiceProvider (sem ele a API cai)');
    foreach ([
        "Route::get('loja/carteira', [CarteiraDaLojaController::class, 'index'])->middleware('throttle:entregas-loja-carteira')",
        "Route::get('loja/carteira/recargas/{id}', [CarteiraDaLojaController::class, 'mostrar'])->middleware('throttle:entregas-loja-carteira')",
        "Route::post('loja/carteira/recargas', [CarteiraDaLojaController::class, 'pedir'])->middleware('throttle:entregas-loja-recarga')",
        "Route::delete('loja/carteira/recargas/{id}', [CarteiraDaLojaController::class, 'cancelar'])->middleware('throttle:entregas-loja-recarga')",
        "RateLimiter::for('entregas-loja-recarga'",
    ] as $trecho) {
        confere(str_contains($rotas, $trecho), 'rota/limite: ' . substr($trecho, 0, 70));
    }
    $regras = file_get_contents($raiz . '/api/app/Http/Middleware/RegrasPortalLoja.php');
    confere(str_contains($regras, 'return $this->criarComCredito((string) $usuario->company_uuid, (string) $vendor->uuid, $criar);'), 'o pedido novo do portal (depois das conferências do destino) passa pelo criarComCredito');

    echo '== RegrasPortalLoja: pedido novo da loja pré-paga' . PHP_EOL;
    iniciar();
    \Teste\Trava::$ocupadas = [];
    \Teste\Trava::$validades = [];
    $metodo  = new ReflectionMethod(\App\Http\Middleware\RegrasPortalLoja::class, 'criarComCredito');
    $regrasP = new \App\Http\Middleware\RegrasPortalLoja();
    $criados = 0;
    $criar   = function () use (&$criados) {
        $criados++;

        return 'criado';
    };
    confere($metodo->invoke($regrasP, 'empresa-1', 'vendor-b', $criar) === 'criado' && \Teste\Trava::$validades === [], 'pós-paga: cria direto, sem trava');
    PrePago::definir('empresa-1', 'vendor-a', 'pre', '11222333000181', 'user-admin');
    $r = $metodo->invoke($regrasP, 'empresa-1', 'vendor-a', $criar);
    confere($r->status === 422 && $r->dados === ['errors' => [CarteiraDaLoja::SEM_CREDITO]] && $criados === 1, 'pré-paga sem crédito: 422 e o pedido não chega ao Fleetbase');
    recebimento('vendor-a', 20);
    confere($metodo->invoke($regrasP, 'empresa-1', 'vendor-a', $criar) === 'criado' && $criados === 2 && (\Teste\Trava::$validades['entregas:carteira:vendor-a'] ?? null) === 30, 'com crédito: cria, com a trava da carteira da loja');
    \Teste\Trava::$ocupadas['entregas:carteira:vendor-a'] = true;
    $r = $metodo->invoke($regrasP, 'empresa-1', 'vendor-a', $criar);
    confere($r->status === 409 && $criados === 2, 'outro pedido da loja sendo criado (trava ocupada): 409 sem criar');
    $portalCtl = file_get_contents($raiz . '/api/app/Http/Controllers/Entregas/PortalLojaController.php');
    confere(str_contains($portalCtl, "'carteira' => CarteiraDaLoja::resumo((string) session('company'), (string) \$vendor->uuid)"), 'o resumo-do-dia traz a carteira (aba e Início do portal)');
    $proteger = file_get_contents($raiz . '/api/app/Http/Middleware/ProtegerPortalLoja.php');
    confere(str_contains($proteger, "'#^[A-Z]+ entregas/loja/.+$#'"), 'as rotas da carteira passam no ProtegerPortalLoja por estarem em entregas/loja/');
    $env    = file_get_contents($raiz . '/deploy/stack.env.example');
    $stack  = file_get_contents($raiz . '/deploy/docker-stack.yml');
    $config = file_get_contents($raiz . '/api/config/services.php');
    confere(preg_match('/^ENTREGAS_RECARGA=\r?$/m', $env) === 1 &&str_contains($stack, 'ENTREGAS_RECARGA: ${ENTREGAS_RECARGA:-}') && str_contains($config, "'recarga' => env('ENTREGAS_RECARGA')"), 'ENTREGAS_RECARGA no stack, no exemplo e no config');
    $migration = file_get_contents($raiz . '/api/database/migrations/2026_10_27_200000_create_entregas_financeiro_prepago_tables.php');
    confere(str_contains($migration, "\$table->string('payment_id', 64)->nullable()->unique();") && str_contains($migration, "\$table->unique(['company_uuid', 'loja_uuid'], 'ent_cart_loja');"), 'migration: id da cobrança único e uma carteira por loja');

    resumo();
}
