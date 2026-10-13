<?php

// Financeiro da loja: o portal (PortalLojaController@financeiro e @fatura, FaturasDaLoja), os e-mails das faturas
// (AvisosDaFatura, FaturaEmitida, LembreteDeFatura, FaturaVencida) e a rotina entregas:financeiro-avisos, no banco em
// memória do stubs-ifood.php. A MailMessage é a real do Laravel 10 (scripts/teste-php/laravel10/).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-portal-emails.php

namespace {
    require __DIR__ . '/stubs-financeiro.php';
}

namespace Illuminate\Contracts\Support {
    interface Htmlable { public function toHtml(); }
    interface Renderable { public function render(); }
    interface Arrayable { public function toArray(); }
}

namespace Illuminate\Contracts\Mail {
    interface Attachable {}
    interface Mailable {}
}

namespace Illuminate\Support\Traits {
    trait Conditionable {}
}

namespace Illuminate\Mail {
    class Attachment {}
    class Markdown {}
}

namespace Illuminate\Container {
    class Container
    {
        public static function getInstance() { return new static(); }
    }
}

namespace Illuminate\Notifications {
    class Notification {}
}

namespace Illuminate\Support\Facades {
    // Notification::route('mail', [...])->notify($aviso): guarda o envio; com $falhar, o notify lança (SMTP fora)
    class Notification
    {
        public static array $enviados    = [];
        public static ?\Throwable $falhar = null;

        public static function route($canal, $rota) { return new \Teste\RotaDeAviso($canal, $rota); }
    }
}

namespace Teste {
    class RotaDeAviso
    {
        public function __construct(public string $canal, public $rota) {}

        public function notify($aviso): void
        {
            if (\Illuminate\Support\Facades\Notification::$falhar) {
                throw \Illuminate\Support\Facades\Notification::$falhar;
            }
            \Illuminate\Support\Facades\Notification::$enviados[] = ['canal' => $this->canal, 'para' => $this->rota, 'aviso' => $aviso];
        }
    }

    class Abortado extends \RuntimeException
    {
        public function __construct(public int $status, string $mensagem = '') { parent::__construct($mensagem); }
    }
}

namespace Fleetbase\Support {
    class Utils
    {
        public static function consoleUrl(string $path = '', ?array $queryParams = [], $subdomain = null): string
        {
            return 'https://entregas.restaurantepro.com.br/' . ltrim($path, '/') . ($queryParams ? '?' . http_build_query($queryParams) : '');
        }

        // o delinkify do core-api 1.6.61 (ver stubs-emails.php)
        public static function delinkify(?string $text): string
        {
            if ($text === null || $text === '') {
                return '';
            }

            return preg_replace(['/([A-Za-z0-9])(\.)([A-Za-z]{2,24})(\b)/u', '/(https?:\/\/)/i', '/\b(www\.)/i', '/(@)/'], ['$1&#8203;$2&#8203;$3$4', '$1&#8203;', '$1&#8203;', '&#8203;$1'], e($text));
        }
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
                'usuario-b' => new \Fleetbase\FleetOps\Models\Vendor(['uuid' => 'vendor-b', 'public_id' => 'vendor_b', 'company_uuid' => 'empresa-1', 'name' => 'Burger Hub', 'place_uuid' => null]),
                default     => null,
            };
        }

        public static function coleta($vendor)
        {
            return $vendor?->place_uuid ? (object) ['street1' => 'Rua A, 10', 'street2' => null, 'neighborhood' => 'Centro', 'city' => 'Ribeirão Preto', 'name' => 'Pizzaria do Zé'] : null;
        }

        public static function contato(?string $userUuid) { return null; }
    }
}

namespace {
    use App\Console\Commands\Entregas\FinanceiroAvisos;
    use App\Http\Controllers\Entregas\FinanceiroLojasController;
    use App\Http\Controllers\Entregas\PortalLojaController;
    use App\Notifications\Entregas\Email\FaturaEmitida;
    use App\Notifications\Entregas\Email\FaturaVencida;
    use App\Notifications\Entregas\Email\LembreteDeFatura;
    use App\Support\Entregas\Financeiro\AvisosDaFatura;
    use App\Support\Entregas\Financeiro\FaturasDaLoja;
    use App\Support\Entregas\Financeiro\Lancamentos;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Facades\Notification;
    use Teste\Abortado;
    use Teste\Banco;
    use Teste\Config;
    use Teste\Relogio;

    function e($valor) { return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8', true); }

    function abort_if($condicao, $status, $mensagem = '')
    {
        if ($condicao) {
            throw new Abortado($status, $mensagem);
        }
    }

    function data_get($alvo, $chave, $padrao = null)
    {
        foreach (explode('.', $chave) as $parte) {
            if (is_array($alvo) && array_key_exists($parte, $alvo)) {
                $alvo = $alvo[$parte];
            } elseif (is_object($alvo) && isset($alvo->{$parte})) {
                $alvo = $alvo->{$parte};
            } else {
                return $padrao;
            }
        }

        return $alvo;
    }

    require __DIR__ . '/laravel10/Action.php';
    require __DIR__ . '/laravel10/SimpleMessage.php';
    require __DIR__ . '/laravel10/MailMessage.php';

    /** Uma fatura no banco em memória. */
    function fatura(string $uuid, string $vendor, string $situacao, ?int $numero, string $inicio, string $fim, string $vencimento, ?float $total, array $extra = []): void
    {
        // ids 1, 2, 3... na ordem de criação (o banco em memória é zerado a cada montagem)
        $id = count(Banco::$tabelas['entregas_faturas'] ?? []) + 1;
        Banco::$tabelas['entregas_faturas'][$id] = array_merge([
            'id' => $id, 'uuid' => $uuid, 'company_uuid' => 'empresa-1', 'vendor_uuid' => $vendor, 'numero' => $numero,
            'periodo_inicio' => $inicio, 'periodo_fim' => $fim, 'vencimento' => $vencimento, 'situacao' => $situacao,
            'total' => $total === null ? null : number_format($total, 2, '.', ''), 'emitida_em' => $situacao === 'rascunho' ? null : '2026-10-05 10:00:00',
            'emitida_por' => null, 'cancelada_em' => null, 'cancelada_por' => null, 'email_enviado_em' => null,
            'lembrete_enviado_em' => null, 'aviso_vencida_enviado_em' => null, 'created_at' => '2026-10-05 00:30:00', 'updated_at' => '2026-10-05 00:30:00',
        ], $extra);
        Banco::$proximoId['entregas_faturas'] = $id + 1;
    }

    /** Um lançamento da conta loja (direto, para controlar data e fatura); devolve o id. */
    function lancamentoDaLoja(string $vendor, string $tipo, float $valor, string $data, ?string $fatura = null, array $extra = []): int
    {
        return Lancamentos::inserir(array_merge([
            'company_uuid' => 'empresa-1', 'conta_tipo' => 'loja', 'conta_uuid' => $vendor, 'tipo' => $tipo, 'valor' => $valor,
            'data_referencia' => $data, 'fatura_uuid' => $fatura,
        ], $extra));
    }

    function reiniciarLoja(): void
    {
        reiniciarFinanceiro();
        Relogio::$agora          = '2026-10-14 10:00:00';
        Notification::$enviados  = [];
        Notification::$falhar    = null;
        Banco::$tabelas['companies'] = [1 => ['id' => 1, 'uuid' => 'empresa-1', 'name' => 'Entregas Rápidas']];
        Banco::$tabelas['vendors']   = [
            1 => ['id' => 1, 'uuid' => 'vendor-a', 'public_id' => 'vendor_a', 'company_uuid' => 'empresa-1', 'name' => 'Pizzaria do Zé', 'email' => 'ze@pizzaria.com', 'deleted_at' => null],
            2 => ['id' => 2, 'uuid' => 'vendor-b', 'public_id' => 'vendor_b', 'company_uuid' => 'empresa-1', 'name' => 'Burger Hub', 'email' => null, 'deleted_at' => null],
            3 => ['id' => 3, 'uuid' => 'vendor-c', 'public_id' => 'vendor_c', 'company_uuid' => 'empresa-1', 'name' => 'Terraço', 'email' => 'terraco@x.com', 'deleted_at' => null],
        ];
        Banco::$tabelas['vendor_personnels'] = [
            1 => ['id' => 1, 'vendor_uuid' => 'vendor-a', 'contact_uuid' => 'contato-1', 'status' => 'active', 'deleted_at' => null],
            2 => ['id' => 2, 'vendor_uuid' => 'vendor-a', 'contact_uuid' => 'contato-2', 'status' => 'inactive', 'deleted_at' => null],
            3 => ['id' => 3, 'vendor_uuid' => 'vendor-a', 'contact_uuid' => 'contato-3', 'status' => 'active', 'deleted_at' => null],
            4 => ['id' => 4, 'vendor_uuid' => 'vendor-a', 'contact_uuid' => 'contato-4', 'status' => 'active', 'deleted_at' => null],
            5 => ['id' => 5, 'vendor_uuid' => 'vendor-a', 'contact_uuid' => 'contato-5', 'status' => 'active', 'deleted_at' => '2026-10-01 10:00:00'],
            6 => ['id' => 6, 'vendor_uuid' => 'vendor-a', 'contact_uuid' => 'contato-6', 'status' => 'active', 'deleted_at' => null],
            7 => ['id' => 7, 'vendor_uuid' => 'vendor-a', 'contact_uuid' => 'contato-7', 'status' => 'active', 'deleted_at' => null],
            8 => ['id' => 8, 'vendor_uuid' => 'vendor-c', 'contact_uuid' => 'contato-8', 'status' => 'active', 'deleted_at' => null],
            9 => ['id' => 9, 'vendor_uuid' => 'vendor-a', 'contact_uuid' => 'contato-9', 'status' => 'active', 'deleted_at' => null],
        ];
        Banco::$tabelas['contacts'] = [
            1 => ['id' => 1, 'uuid' => 'contato-1', 'company_uuid' => 'empresa-1', 'type' => 'customer', 'email' => ' Ana@Pizzaria.com ', 'deleted_at' => null],
            2 => ['id' => 2, 'uuid' => 'contato-2', 'company_uuid' => 'empresa-1', 'type' => 'customer', 'email' => 'inativo@pizzaria.com', 'deleted_at' => null],
            3 => ['id' => 3, 'uuid' => 'contato-3', 'company_uuid' => 'empresa-1', 'type' => 'customer', 'email' => 'ZE@pizzaria.com', 'deleted_at' => null],
            4 => ['id' => 4, 'uuid' => 'contato-4', 'company_uuid' => 'empresa-1', 'type' => 'customer', 'email' => '', 'deleted_at' => null],
            5 => ['id' => 5, 'uuid' => 'contato-5', 'company_uuid' => 'empresa-1', 'type' => 'customer', 'email' => 'saiu@pizzaria.com', 'deleted_at' => null],
            6 => ['id' => 6, 'uuid' => 'contato-6', 'company_uuid' => 'empresa-1', 'type' => 'customer', 'email' => 'nao-e-email', 'deleted_at' => null],
            7 => ['id' => 7, 'uuid' => 'contato-7', 'company_uuid' => 'empresa-1', 'type' => 'customer', 'email' => 'apagado@pizzaria.com', 'deleted_at' => '2026-10-01 10:00:00'],
            8 => ['id' => 8, 'uuid' => 'contato-8', 'company_uuid' => 'empresa-1', 'type' => 'customer', 'email' => 'terraco@x.com', 'deleted_at' => null],
            9 => ['id' => 9, 'uuid' => 'contato-9', 'company_uuid' => 'empresa-1', 'type' => 'driver', 'email' => 'motoboy@x.com', 'deleted_at' => null],
        ];
        Banco::$tabelas['orders'] = [
            1 => ['id' => 1, 'uuid' => 'pedido-2', 'company_uuid' => 'empresa-1', 'public_id' => 'order_2', 'internal_id' => 'RP0002'],
        ];
        // o mesmo pedido no model (o ExtratoDaConta::linhas lê o pedido sem detalhes pelo Order)
        \Fleetbase\FleetOps\Models\Order::$todos = [
            new \Fleetbase\FleetOps\Models\Order(['uuid' => 'pedido-2', 'company_uuid' => 'empresa-1', 'public_id' => 'order_2', 'internal_id' => 'RP0002']),
        ];
    }

    /** A loja A com faturas emitidas, cancelada e rascunho, recebimentos e lançamentos ainda sem fatura; a B com uma fatura. */
    function montarLojas(): void
    {
        reiniciarLoja();
        fatura('fat-1', 'vendor-a', 'emitida', 1, '2026-09-21', '2026-09-27', '2026-09-30', 100.0, ['emitida_em' => '2026-09-28 10:00:00', 'email_enviado_em' => '2026-09-28 10:00:05']);
        fatura('fat-3', 'vendor-a', 'emitida', 3, '2026-09-28', '2026-10-04', '2026-10-07', 50.0);
        fatura('fat-2', 'vendor-a', 'cancelada', 2, '2026-09-14', '2026-09-20', '2026-09-23', 30.0, ['cancelada_em' => '2026-09-22 09:00:00']);
        fatura('fat-r', 'vendor-a', 'rascunho', null, '2026-10-05', '2026-10-11', '2026-10-14', null);
        fatura('fat-b', 'vendor-b', 'emitida', 4, '2026-09-28', '2026-10-04', '2026-10-07', 99.0);

        lancamentoDaLoja('vendor-a', 'corrida', 60, '2026-09-22', 'fat-1', [
            'order_uuid' => 'pedido-1', 'observacao_interna' => 'segredo da central', 'criado_por' => 'usuario-admin',
            'detalhes'   => ['pedido' => 'order_1', 'numero' => 'RP0001', 'km' => 3.21, 'faixa' => ['de_km' => 2, 'ate_km' => 4, 'acima' => false], 'loja_nome' => 'Pizzaria do Zé', 'destino' => 'Rua X, 10', 'cancelado_pago' => false, 'cancelado_origem' => null, 'interno' => 'não sai'],
        ]);
        lancamentoDaLoja('vendor-a', 'ajuste', 40, '2026-09-23', 'fat-1', ['order_uuid' => 'pedido-2', 'motivo' => 'Taxa de espera']);
        lancamentoDaLoja('vendor-a', 'corrida', 50, '2026-09-29', 'fat-3', ['order_uuid' => 'pedido-3', 'detalhes' => ['pedido' => 'order_3', 'numero' => 'RP0003', 'km' => 2, 'destino' => 'Rua Y, 5', 'cancelado_pago' => true, 'cancelado_origem' => 'ifood']]);
        lancamentoDaLoja('vendor-a', 'corrida', 20, '2026-10-06', 'fat-r', ['order_uuid' => 'pedido-r']);
        // recebimentos: 120 (fat-1 paga, fat-3 com 20) e um de 10 estornado
        lancamentoDaLoja('vendor-a', 'recebimento', -120, '2026-10-01', null, ['pagamento_uuid' => 'pag-1']);
        $rec = lancamentoDaLoja('vendor-a', 'recebimento', -10, '2026-10-02', null, ['pagamento_uuid' => 'pag-2']);
        lancamentoDaLoja('vendor-a', 'estorno', 10, '2026-10-03', null, ['pagamento_uuid' => 'pag-2', 'estorno_de' => $rec]);
        // ainda sem fatura: uma corrida atrasada (09/10), duas da semana (uma estornada) e um desconto
        lancamentoDaLoja('vendor-a', 'corrida', 10, '2026-10-09', null, ['order_uuid' => 'pedido-4']);
        lancamentoDaLoja('vendor-a', 'corrida', 15, '2026-10-13', null, ['order_uuid' => 'pedido-5']);
        $estornada = lancamentoDaLoja('vendor-a', 'corrida', 7, '2026-10-13', null, ['order_uuid' => 'pedido-6']);
        lancamentoDaLoja('vendor-a', 'estorno', -7, '2026-10-13', null, ['order_uuid' => 'pedido-6', 'estorno_de' => $estornada]);
        lancamentoDaLoja('vendor-a', 'ajuste', -5, '2026-10-13', null, ['motivo' => 'Desconto']);
        // a loja B
        lancamentoDaLoja('vendor-b', 'corrida', 99, '2026-09-30', 'fat-b', ['order_uuid' => 'pedido-b']);
        lancamentoDaLoja('vendor-b', 'corrida', 33, '2026-10-13', null, ['order_uuid' => 'pedido-b2']);
    }

    function comoUsuario(string $usuario): void
    {
        session(['company' => 'empresa-1', 'user' => $usuario]);
    }

    function textoDoEmail($mensagem): string
    {
        return implode("\n", array_merge([$mensagem->subject, $mensagem->greeting], $mensagem->introLines, [$mensagem->actionText, $mensagem->actionUrl], $mensagem->outroLines));
    }

    // ------------------------------------------------------------------------------------------------------------------
    echo '== Portal: loja/financeiro' . PHP_EOL;
    montarLojas();
    comoUsuario('usuario-a');
    $controller = new PortalLojaController();
    $resposta   = $controller->financeiro();
    $dados      = $resposta->dados;
    $json       = json_encode($dados, JSON_UNESCAPED_UNICODE);

    confere($resposta->status === 200 && array_keys($dados) === ['ciclo_atual', 'faturas'], 'resposta { ciclo_atual, faturas }');
    confere(array_column($dados['faturas'], 'id') === ['fat-3', 'fat-1', 'fat-2'], 'só emitidas e canceladas da loja, mais nova primeiro (sem rascunho nem a da outra loja)');
    $f3 = $dados['faturas'][0];
    confere(array_keys($f3) === ['id', 'numero', 'loja', 'periodo_inicio', 'periodo_fim', 'vencimento', 'situacao', 'pagamento', 'vencida', 'total', 'recebido', 'em_aberto', 'entregas', 'emitida_em'],
        'fatura pública: os campos do contrato, sem email_enviado_em');
    confere($f3['numero'] === 'FAT-0003' && $f3['loja'] === ['id' => 'vendor_a', 'nome' => 'Pizzaria do Zé'] && $f3['periodo_inicio'] === '2026-09-28' && $f3['periodo_fim'] === '2026-10-04' && $f3['vencimento'] === '2026-10-07',
        'número FAT-0003, loja, período e vencimento');
    confere($f3['situacao'] === 'emitida' && $f3['pagamento'] === 'parcial' && $f3['vencida'] === true && $f3['total'] === 50.0 && $f3['recebido'] === 20.0 && $f3['em_aberto'] === 30.0 && $f3['entregas'] === 1,
        'FAT-0003: parcial (20 de 50), vencida, 1 entrega');
    $f1 = $dados['faturas'][1];
    confere($f1['pagamento'] === 'paga' && $f1['vencida'] === false && $f1['recebido'] === 100.0 && $f1['em_aberto'] === 0.0 && $f1['entregas'] === 1 && $f1['emitida_em'] === '2026-09-28T10:00:00-03:00',
        'FAT-0001: paga pelo recebido (o estornado não conta), emitida_em em ISO no fuso da empresa');
    $f2 = $dados['faturas'][2];
    confere($f2['situacao'] === 'cancelada' && $f2['pagamento'] === null && $f2['vencida'] === false && $f2['recebido'] === 0.0 && $f2['em_aberto'] === 0.0 && $f2['total'] === 30.0,
        'cancelada: sem pagamento, não vence, nada em aberto');
    confere($dados['ciclo_atual'] === ['inicio' => '2026-10-09', 'fim' => '2026-10-18', 'entregas' => 2, 'valor' => 20.0],
        'ciclo em andamento: desde o lançamento sem fatura mais antigo, até o fim do ciclo; 2 entregas (a estornada não conta) e R$ 20 (sem recebimentos)');
    confere(!str_contains($json, 'segredo') && !str_contains($json, 'observacao_interna') && !str_contains($json, 'criado_por') && !str_contains($json, 'email_enviado_em') && !str_contains($json, 'vendor-b') && !str_contains($json, 'Burger'),
        'nada interno nem da outra loja');

    comoUsuario('usuario-b');
    $daB = $controller->financeiro()->dados;
    confere(array_column($daB['faturas'], 'id') === ['fat-b'] && $daB['ciclo_atual']['valor'] === 33.0 && $daB['ciclo_atual']['entregas'] === 1, 'a loja B só vê a dela');
    confere(($daB['faturas'][0]['pagamento'] ?? null) === 'aberta' && $daB['faturas'][0]['em_aberto'] === 99.0, 'loja B: em aberto (os recebimentos da A não contam)');

    comoUsuario('usuario-sem-loja');
    $erro = excecao(fn () => $controller->financeiro());
    confere($erro instanceof Abortado && $erro->status === 404, 'usuário sem loja: 404');

    echo '== Portal: ciclo em andamento (FaturasDaLoja::cicloAtual)' . PHP_EOL;
    $lancs = [
        (object) ['id' => 1, 'tipo' => 'corrida', 'valor' => '10.00', 'data_referencia' => '2026-10-09', 'fatura_uuid' => null, 'pagamento_uuid' => null, 'estorno_de' => null],
        (object) ['id' => 2, 'tipo' => 'corrida', 'valor' => '0.10', 'data_referencia' => '2026-10-13', 'fatura_uuid' => null, 'pagamento_uuid' => null, 'estorno_de' => null],
        (object) ['id' => 3, 'tipo' => 'ajuste', 'valor' => '0.20', 'data_referencia' => '2026-10-13', 'fatura_uuid' => null, 'pagamento_uuid' => null, 'estorno_de' => null],
        (object) ['id' => 4, 'tipo' => 'corrida', 'valor' => '50.00', 'data_referencia' => '2026-10-01', 'fatura_uuid' => 'fat-x', 'pagamento_uuid' => null, 'estorno_de' => null],
        (object) ['id' => 5, 'tipo' => 'recebimento', 'valor' => '-50.00', 'data_referencia' => '2026-10-02', 'fatura_uuid' => null, 'pagamento_uuid' => 'p', 'estorno_de' => null],
    ];
    confere(FaturasDaLoja::cicloAtual($lancs, '2026-10-14', null) === ['inicio' => '2026-10-09', 'fim' => '2026-10-14', 'entregas' => 2, 'valor' => 10.3],
        'sem o ciclo da loja: do lançamento sem fatura mais antigo até hoje (centavos certos: 10,30)');
    confere(FaturasDaLoja::cicloAtual($lancs, '2026-10-14', ['inicio' => '2026-10-12', 'fim' => '2026-10-18']) === ['inicio' => '2026-10-09', 'fim' => '2026-10-18', 'entregas' => 2, 'valor' => 10.3],
        'com o ciclo: o lançamento atrasado puxa o início para trás');
    confere(FaturasDaLoja::cicloAtual([], '2026-10-14', ['inicio' => '2026-10-12', 'fim' => '2026-10-18']) === ['inicio' => '2026-10-12', 'fim' => '2026-10-18', 'entregas' => 0, 'valor' => 0.0],
        'sem lançamento: o ciclo, zerado');
    confere(FaturasDaLoja::cicloAtual([], '2026-10-14', null) === ['inicio' => '2026-10-14', 'fim' => '2026-10-14', 'entregas' => 0, 'valor' => 0.0], 'sem lançamento e sem ciclo: hoje, zerado');
    confere(FaturasDaLoja::periodoEmAndamento('empresa-1', 'vendor-a', '2026-10-14') === ['inicio' => '2026-10-12', 'fim' => '2026-10-18'], 'loja sem ciclo próprio: o padrão da empresa (semanal, fecha domingo)');
    Banco::$tabelas['entregas_cobranca_lojas'] = [1 => ['id' => 1, 'company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-a', 'ciclo' => 'mensal', 'dia_fechamento' => '5', 'prazo_dias' => '10']];
    confere(FaturasDaLoja::periodoEmAndamento('empresa-1', 'vendor-a', '2026-10-14') === ['inicio' => '2026-10-06', 'fim' => '2026-11-05'], 'loja com ciclo próprio (mensal, dia 5 em texto): o dela');

    echo '== Portal: loja/faturas/{id}' . PHP_EOL;
    montarLojas();
    comoUsuario('usuario-a');
    $resposta = $controller->fatura('fat-1');
    $dados    = $resposta->dados;
    $json     = json_encode($dados, JSON_UNESCAPED_UNICODE);
    confere($resposta->status === 200 && array_keys($dados) === ['fatura', 'itens', 'loja', 'empresa'], 'resposta { fatura, itens, loja, empresa }');
    confere($dados['fatura']['id'] === 'fat-1' && $dados['fatura']['numero'] === 'FAT-0001' && $dados['fatura']['pagamento'] === 'paga' && $dados['fatura']['entregas'] === 1 && !array_key_exists('email_enviado_em', $dados['fatura']),
        'a fatura no formato público');
    confere($dados['loja'] === ['nome' => 'Pizzaria do Zé', 'endereco' => 'Rua A, 10, Centro, Ribeirão Preto'] && $dados['empresa'] === ['nome' => 'Entregas Rápidas'], 'loja (nome e endereço) e empresa');
    confere(count($dados['itens']) === 2 && $dados['itens'][0]['tipo'] === 'corrida' && $dados['itens'][1]['tipo'] === 'ajuste', 'os itens da fatura, mais antigos primeiro');
    $item = $dados['itens'][0];
    confere(array_keys($item) === ['id', 'tipo', 'valor', 'data', 'criado_em', 'pedido', 'detalhes', 'motivo', 'situacao', 'valor_pago', 'pagamento', 'estorno_de', 'estornado', 'fatura'],
        'item: os campos do Lancamento público (sem observacao_interna e criado_por)');
    confere($item['valor'] === 60.0 && $item['data'] === '2026-09-22' && $item['pedido'] === ['id' => 'order_1', 'numero' => 'RP0001'] && $item['fatura'] === ['id' => 'fat-1', 'numero' => 'FAT-0001'] && $item['estornado'] === false && $item['criado_em'] === '2026-10-14T10:00:00-03:00',
        'item: valor, data, pedido dos detalhes, fatura, criado_em em ISO');
    // faixa em números com casas (o ExtratoDaConta converte; no JSON, 2.0 e 2 são o mesmo número para a tela)
    confere($item['detalhes'] === ['km' => 3.21, 'faixa' => ['de_km' => 2.0, 'ate_km' => 4.0, 'acima' => false], 'loja_nome' => 'Pizzaria do Zé', 'destino' => 'Rua X, 10', 'cancelado_pago' => false, 'cancelado_origem' => null],
        'item: só os detalhes públicos');
    confere($dados['itens'][1]['pedido'] === ['id' => 'order_2', 'numero' => 'RP0002'] && $dados['itens'][1]['motivo'] === 'Taxa de espera' && $dados['itens'][1]['detalhes'] === null,
        'ajuste com pedido sem detalhes: o pedido vem da tabela orders; o motivo aparece');
    confere(!str_contains($json, 'segredo') && !str_contains($json, 'usuario-admin') && !str_contains($json, 'não sai'), 'nada interno nos itens');

    $cancelada = $controller->fatura('fat-2');
    confere($cancelada->status === 200 && $cancelada->dados['fatura']['situacao'] === 'cancelada', 'a cancelada também abre');
    foreach (['fat-r' => 'rascunho', 'fat-b' => 'fatura de outra loja', 'nao-existe' => 'fatura inexistente'] as $uuid => $caso) {
        $r = $controller->fatura($uuid);
        confere($r->status === 404 && ($r->dados['errors'][0] ?? null) === 'Fatura não encontrada.', "{$caso}: 404");
    }
    Banco::$tabelas['entregas_faturas'][1]['company_uuid'] = 'empresa-2';
    confere($controller->fatura('fat-1')->status === 404, 'fatura de outra empresa: 404');

    // ------------------------------------------------------------------------------------------------------------------
    echo '== Console × portal: os mesmos números da mesma fatura' . PHP_EOL;
    montarLojas();
    comoUsuario('usuario-a');
    // o console (FinanceiroLojasController, admin) lê as lojas pelo model
    \Fleetbase\FleetOps\Models\Vendor::$todos = [
        new \Fleetbase\FleetOps\Models\Vendor(['uuid' => 'vendor-a', 'public_id' => 'vendor_a', 'company_uuid' => 'empresa-1', 'name' => 'Pizzaria do Zé', 'type' => 'customer', 'email' => 'ze@pizzaria.com']),
        new \Fleetbase\FleetOps\Models\Vendor(['uuid' => 'vendor-b', 'public_id' => 'vendor_b', 'company_uuid' => 'empresa-1', 'name' => 'Burger Hub', 'type' => 'customer']),
    ];
    \Fleetbase\Support\Auth::$usuario = new class {
        public string $uuid = 'usuario-admin';
        public function isNotAdmin(): bool { return false; }
    };
    $console = new FinanceiroLojasController();
    $campos  = array_flip(['numero', 'loja', 'periodo_inicio', 'periodo_fim', 'vencimento', 'situacao', 'pagamento', 'vencida', 'total', 'recebido', 'em_aberto', 'entregas', 'emitida_em']);
    $doConsole = array_column($console->faturas(new \Illuminate\Http\Request([]))->dados['faturas'] ?? [], null, 'id');
    $doPortal  = $controller->financeiro()->dados['faturas'];
    confere(count($doPortal) === 3, 'o portal lista 3 faturas da loja A');
    foreach ($doPortal as $fatura) {
        confere(isset($doConsole[$fatura['id']]) && array_intersect_key($doConsole[$fatura['id']], $campos) === array_intersect_key($fatura, $campos),
            "lista: {$fatura['id']} igual no console e no portal (situação, recebido, em aberto, entregas)");
    }
    foreach (['fat-1', 'fat-3', 'fat-2'] as $uuid) {
        $p = $controller->fatura($uuid)->dados;
        $c = $console->fatura(new \Illuminate\Http\Request([]), $uuid)->dados;
        confere(array_diff_key($c['fatura'], ['email_enviado_em' => true]) === $p['fatura'], "detalhe: {$uuid} igual no console e no portal (menos email_enviado_em)");
        confere(array_map(fn ($item) => array_diff_key($item, ['observacao_interna' => true, 'criado_por' => true]), $c['itens']) === $p['itens'],
            "itens: {$uuid} iguais no console e no portal (menos observação interna e quem lançou)");
    }
    // a lista de lojas e o painel do console somam as mesmas faturas
    $emAbertoDaA = array_sum(array_map(fn ($f) => $f['em_aberto'], $doPortal));
    $vencidoDaA  = array_sum(array_map(fn ($f) => $f['vencida'] ? $f['em_aberto'] : 0, $doPortal));
    $lojaA       = array_column($console->lojas(new \Illuminate\Http\Request([]))->dados['lojas'] ?? [], null, 'id')['vendor_a'] ?? [];
    confere(($lojaA['em_aberto'] ?? null) === round($emAbertoDaA, 2) && ($lojaA['vencido'] ?? null) === round($vencidoDaA, 2), 'Lojas do console: em aberto e vencido da loja A = soma do portal (' . $emAbertoDaA . ' / ' . $vencidoDaA . ')');
    Config::$valores['services.entregas.financeiro'] = ''; // o "sem valor" do painel fica de fora (precisa do CalculoEntregas)
    $painel = \App\Support\Entregas\Financeiro\PainelFinanceiro::montar('empresa-1', '2026-10-01', '2026-10-31');
    comoUsuario('usuario-b');
    $doPortalB = $controller->financeiro()->dados['faturas'];
    $emAbertoTotal = round($emAbertoDaA + array_sum(array_map(fn ($f) => $f['em_aberto'], $doPortalB)), 2);
    $vencidoTotal  = round($vencidoDaA + array_sum(array_map(fn ($f) => $f['vencida'] ? $f['em_aberto'] : 0, $doPortalB)), 2);
    confere($painel['a_receber'] === $emAbertoTotal && $painel['vencido'] === $vencidoTotal, "painel: a receber {$painel['a_receber']} e vencido {$painel['vencido']} = soma das faturas dos portais");
    Config::$valores['services.entregas.financeiro'] = '1';

    // ------------------------------------------------------------------------------------------------------------------
    echo '== E-mails: destinatários (AvisosDaFatura::emailsDaLoja)' . PHP_EOL;
    montarLojas();
    confere(AvisosDaFatura::emailsDaLoja('empresa-1', 'vendor-a') === ['ze@pizzaria.com', 'ana@pizzaria.com'],
        'e-mail da loja + usuários ativos do portal, sem repetir, sem vazio, sem inválido, sem inativo, apagado ou de motoboy');
    confere(AvisosDaFatura::emailsDaLoja('empresa-1', 'vendor-b') === [], 'loja sem e-mail e sem usuário: nenhum');
    confere(AvisosDaFatura::emailsDaLoja('empresa-2', 'vendor-a') === [], 'loja de outra empresa: nenhum');

    echo '== E-mails: fatura emitida' . PHP_EOL;
    montarLojas();
    $f3 = FaturasDaLoja::porUuid('empresa-1', 'fat-3');
    confere(AvisosDaFatura::emitida($f3) === true, 'emitida: envia');
    $envio = Notification::$enviados[0] ?? null;
    confere(count(Notification::$enviados) === 1 && $envio['canal'] === 'mail' && $envio['para'] === ['ze@pizzaria.com', 'ana@pizzaria.com'] && $envio['aviso'] instanceof FaturaEmitida,
        'um envio, por e-mail, para a loja e os usuários do portal');
    $mensagem = $envio['aviso']->toMail(null);
    confere($mensagem->subject === 'Fatura FAT-0003 emitida', 'assunto "Fatura FAT-0003 emitida"');
    $texto = textoDoEmail($mensagem);
    confere(str_contains($texto, 'Olá, Pizzaria do Zé!') && str_contains($texto, '28/09/2026 a 04/10/2026') && str_contains($texto, 'R$ 50,00') && str_contains($texto, '07/10/2026') && str_contains($texto, 'Entregas Rápidas'),
        'texto com a loja, o período, o valor, o vencimento e a empresa');
    confere($mensagem->actionText === 'Ver a fatura' && $mensagem->actionUrl === 'https://entregas.restaurantepro.com.br/customer-portal/financeiro/fat-3', 'botão para a fatura no portal (customer-portal/financeiro/<uuid>)');
    confere(Banco::$tabelas['entregas_faturas'][2]['email_enviado_em'] === '2026-10-14 10:00:00', 'grava email_enviado_em (hora de Brasília)');
    confere(!preg_match('/[A-Za-z]{4,} (the|your|invoice)\b/i', $texto) && !str_contains($texto, '&#'), 'sem inglês e sem entidade HTML');
    confere(logsSem(['ze@pizzaria.com', 'ana@pizzaria.com']), 'o log não traz os e-mails');

    Notification::$enviados = [];
    confere(AvisosDaFatura::emitida(FaturasDaLoja::porUuid('empresa-1', 'fat-3')) === true && count(Notification::$enviados) === 1, 'reenviar (B3) manda de novo');

    Notification::$enviados = [];
    $fb = FaturasDaLoja::porUuid('empresa-1', 'fat-b');
    confere(AvisosDaFatura::emitida($fb) === false && Notification::$enviados === [] && Banco::$tabelas['entregas_faturas'][5]['email_enviado_em'] === null,
        'loja sem e-mail: false, nada enviado, email_enviado_em continua null');
    confere(logou('[entregas] financeiro: fatura sem e-mail para enviar', 'info'), 'loja sem e-mail: aviso no log');

    confere(AvisosDaFatura::emitida(FaturasDaLoja::porUuid('empresa-1', 'fat-r')) === false && Notification::$enviados === [], 'rascunho: não envia');
    confere(AvisosDaFatura::emitida(FaturasDaLoja::porUuid('empresa-1', 'fat-2')) === false && Notification::$enviados === [], 'cancelada: não envia');

    Config::$valores['services.entregas.financeiro'] = '';
    confere(AvisosDaFatura::emitida(FaturasDaLoja::porUuid('empresa-1', 'fat-1')) === false && Notification::$enviados === [], 'ENTREGAS_FINANCEIRO desligado: não envia');
    Config::$valores['services.entregas.financeiro'] = '1';

    Notification::$falhar = new \RuntimeException('SMTP fora do ar');
    Banco::$tabelas['entregas_faturas'][2]['email_enviado_em'] = null;
    confere(AvisosDaFatura::emitida(FaturasDaLoja::porUuid('empresa-1', 'fat-3')) === false && Banco::$tabelas['entregas_faturas'][2]['email_enviado_em'] === null, 'falha no envio: false e email_enviado_em continua null');
    confere(logou('[entregas] financeiro: falha ao enviar o e-mail da fatura', 'warning'), 'falha no envio: warning no log');
    Notification::$falhar = null;

    echo '== E-mails: lembrete e vencida' . PHP_EOL;
    montarLojas();
    confere(AvisosDaFatura::lembrete(FaturasDaLoja::porUuid('empresa-1', 'fat-3')) === true, 'lembrete com valor em aberto: envia');
    $lembrete = Notification::$enviados[0]['aviso'] ?? null;
    confere($lembrete instanceof LembreteDeFatura && $lembrete->toMail(null)->subject === 'Fatura FAT-0003 vence amanhã', 'assunto "Fatura FAT-0003 vence amanhã"');
    $texto = textoDoEmail($lembrete->toMail(null));
    confere(str_contains($texto, 'R$ 30,00') && str_contains($texto, '07/10/2026') && str_contains($texto, 'customer-portal/financeiro/fat-3'), 'lembrete: em aberto, vencimento e link');
    confere(Banco::$tabelas['entregas_faturas'][2]['lembrete_enviado_em'] === '2026-10-14 10:00:00', 'grava lembrete_enviado_em');
    Notification::$enviados = [];
    confere(AvisosDaFatura::lembrete(FaturasDaLoja::porUuid('empresa-1', 'fat-3')) === false && Notification::$enviados === [], 'lembrete: uma vez só');

    confere(AvisosDaFatura::vencida(FaturasDaLoja::porUuid('empresa-1', 'fat-3')) === true, 'vencida com valor em aberto: envia');
    $vencida = Notification::$enviados[0]['aviso'] ?? null;
    confere($vencida instanceof FaturaVencida && $vencida->toMail(null)->subject === 'Fatura FAT-0003 está vencida', 'assunto "Fatura FAT-0003 está vencida"');
    confere(str_contains(textoDoEmail($vencida->toMail(null)), 'R$ 30,00'), 'vencida: o valor em aberto');
    confere(Banco::$tabelas['entregas_faturas'][2]['aviso_vencida_enviado_em'] === '2026-10-14 10:00:00', 'grava aviso_vencida_enviado_em');
    Notification::$enviados = [];
    confere(AvisosDaFatura::vencida(FaturasDaLoja::porUuid('empresa-1', 'fat-3')) === false && Notification::$enviados === [], 'vencida: uma vez só');

    confere(AvisosDaFatura::lembrete(FaturasDaLoja::porUuid('empresa-1', 'fat-1')) === false && AvisosDaFatura::vencida(FaturasDaLoja::porUuid('empresa-1', 'fat-1')) === false && Notification::$enviados === [],
        'fatura paga: nem lembrete nem vencida');
    confere(Banco::$tabelas['entregas_faturas'][1]['lembrete_enviado_em'] === null, 'fatura paga: nada gravado');

    // ------------------------------------------------------------------------------------------------------------------
    echo '== Rotina entregas:financeiro-avisos' . PHP_EOL;
    reiniciarLoja();
    Relogio::$agora = '2026-10-14 09:00:00';
    fatura('fa', 'vendor-a', 'emitida', 11, '2026-10-05', '2026-10-11', '2026-10-15', 10.0);
    fatura('fb', 'vendor-a', 'emitida', 12, '2026-09-28', '2026-10-04', '2026-10-13', 20.0);
    fatura('fc', 'vendor-a', 'emitida', 13, '2026-09-21', '2026-09-27', '2026-10-10', 30.0, ['aviso_vencida_enviado_em' => '2026-10-11 09:00:00']);
    fatura('fd', 'vendor-c', 'emitida', 14, '2026-10-05', '2026-10-11', '2026-10-15', 40.0);
    fatura('fe', 'vendor-a', 'cancelada', 15, '2026-10-05', '2026-10-11', '2026-10-15', 50.0);
    fatura('ff', 'vendor-a', 'emitida', 16, '2026-10-05', '2026-10-11', '2026-10-16', 60.0);
    fatura('fg', 'vendor-a', 'emitida', 17, '2026-10-05', '2026-10-11', '2026-10-14', 70.0);
    fatura('fh', 'vendor-a', 'rascunho', null, '2026-10-05', '2026-10-11', '2026-10-15', null);
    lancamentoDaLoja('vendor-c', 'recebimento', -40, '2026-10-12', null, ['pagamento_uuid' => 'pag-c']);

    $comando = new FinanceiroAvisos();
    confere($comando->handle() === 0, 'roda sem erro');
    $avisos = array_map(fn ($e) => [get_class($e['aviso']), $e['aviso']->fatura['numero']], Notification::$enviados);
    confere($avisos === [[FaturaVencida::class, 'FAT-0012'], [LembreteDeFatura::class, 'FAT-0011']],
        'vencida (venceu ontem) e lembrete (vence amanhã); nada para a paga, a cancelada, o rascunho, a já avisada, a de hoje e a de depois de amanhã');
    Notification::$enviados = [];
    $comando->handle();
    confere(Notification::$enviados === [], 'na segunda rodada, nada (uma vez só)');

    Relogio::$agora = '2026-10-15 09:00:00';
    $comando->handle();
    $avisos = array_map(fn ($e) => [get_class($e['aviso']), $e['aviso']->fatura['numero']], Notification::$enviados);
    confere($avisos === [[FaturaVencida::class, 'FAT-0017'], [LembreteDeFatura::class, 'FAT-0016']],
        'no dia seguinte: a que venceu ontem ganha o aviso de vencida e a de amanhã o lembrete; a que vence hoje, nada');

    reiniciarLoja();
    fatura('fa', 'vendor-a', 'emitida', 11, '2026-10-05', '2026-10-11', '2026-10-15', 10.0);
    Config::$valores['services.entregas.financeiro'] = '';
    (new FinanceiroAvisos())->handle();
    confere(Notification::$enviados === [], 'ENTREGAS_FINANCEIRO desligado: a rotina não manda nada');
    Config::$valores['services.entregas.financeiro'] = '1';

    Notification::$falhar = new \RuntimeException('SMTP fora do ar');
    confere((new FinanceiroAvisos())->handle() === 0 && Banco::$tabelas['entregas_faturas'][1]['lembrete_enviado_em'] === null, 'falha no envio: a rotina segue e tenta de novo amanhã');
    Notification::$falhar = null;

    confere(str_contains(file_get_contents('/repo/api/app/Console/Commands/Entregas/FinanceiroAvisos.php'), "'entregas:financeiro-avisos'"), 'assinatura do comando');

    echo '== Rotas' . PHP_EOL;
    $rotas = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
    confere(str_contains($rotas, "Route::get('loja/financeiro', [PortalLojaController::class, 'financeiro'])->middleware('throttle:60,1');")
        && str_contains($rotas, "Route::get('loja/faturas/{id}', [PortalLojaController::class, 'fatura'])->middleware('throttle:60,1');"), 'loja/financeiro e loja/faturas/{id}, com throttle:60,1');

    resumo();
}
