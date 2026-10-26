<?php

// Números de hoje do portal da loja (ResumoDoDiaDaLoja; Início e a antiga gaveta): pedidos em andamento, entregues hoje,
// pedidos de hoje, % no prazo e tempo médio da loja e os motoboys online da empresa. Só a loja da sessão; "hoje" no
// horário de Brasília.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/resumo-do-dia.php

namespace Illuminate\Support {
    class Carbon extends \DateTimeImmutable
    {
        public function subHours(int $horas): static { return $this->modify("-{$horas} hours"); }
        public function startOfDay(): static { return $this->setTime(0, 0, 0); }
        public function endOfDay(): static { return $this->setTime(23, 59, 59); }
    }
}

namespace Teste {
    class Colecao
    {
        public function __construct(private array $itens) {}
        public function filter(): static { return new static(array_filter($this->itens)); }
        public function unique(): static { return new static(array_unique($this->itens)); }
        public function values(): static { return new static(array_values($this->itens)); }
        public function all(): array { return $this->itens; }
    }

    /** Consulta em memória: aplica os filtros que o código usa e guarda as chamadas. */
    class Consulta
    {
        public array $chamadas = [];

        public function __construct(private array $linhas) {}

        public function where($coluna, $operador = null, $valor = null): static
        {
            if (func_num_args() === 2) {
                [$operador, $valor] = ['=', $operador];
            }
            $this->chamadas[] = "where {$coluna} {$operador}";
            $this->linhas = array_filter($this->linhas, function ($linha) use ($coluna, $operador, $valor) {
                $atual = $linha->$coluna ?? null;

                return match ($operador) {
                    '='  => $atual == $valor,
                    '>=' => $atual >= $valor,
                };
            });

            return $this;
        }

        public function whereIn($coluna, array $valores): static
        {
            $this->linhas = array_filter($this->linhas, fn ($linha) => in_array($linha->$coluna ?? null, $valores, true));

            return $this;
        }

        public function whereNotIn($coluna, array $valores): static
        {
            // como no SQL: nulo não passa no NOT IN
            $this->linhas = array_filter($this->linhas, fn ($linha) => ($linha->$coluna ?? null) !== null && !in_array($linha->$coluna, $valores, true));

            return $this;
        }

        public function whereNull($coluna): static
        {
            $this->linhas = array_filter($this->linhas, fn ($linha) => ($linha->$coluna ?? null) === null);

            return $this;
        }

        public function whereBetween($coluna, array $faixa): static
        {
            $this->linhas = array_filter($this->linhas, fn ($linha) => $linha->$coluna >= $faixa[0] && $linha->$coluna <= $faixa[1]);

            return $this;
        }

        public function pluck($coluna, $chave = null): Colecao
        {
            if ($chave === null) {
                return new Colecao(array_map(fn ($linha) => $linha->$coluna ?? null, array_values($this->linhas)));
            }
            $mapa = [];
            foreach ($this->linhas as $linha) {
                $mapa[(string) ($linha->$chave ?? '')] = $linha->$coluna ?? null;
            }

            return new Colecao($mapa);
        }

        public function get(array $colunas = ['*']): Colecao { return new Colecao(array_values($this->linhas)); }
        public function count(): int { return count($this->linhas); }
    }

    abstract class Modelo
    {
        public static array $todos = [];

        public static function where(...$argumentos) { return (new Consulta(static::$todos))->where(...$argumentos); }
    }
}

namespace Illuminate\Support\Facades {
    class DB
    {
        public static array $tabelas = [];

        public static function table(string $tabela) { return new \Teste\Consulta(static::$tabelas[$tabela] ?? []); }
    }
}

namespace Fleetbase\FleetOps\Models {
    class Order extends \Teste\Modelo { public static array $todos = []; }
    class Driver extends \Teste\Modelo { public static array $todos = []; }
}

namespace {
    use App\Support\Entregas\ResumoDoDiaDaLoja;
    use Fleetbase\FleetOps\Models\Driver;
    use Fleetbase\FleetOps\Models\Order;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\DB;

    require '/repo/api/app/Support/Entregas/StatusDoPedido.php';
    require '/repo/api/app/Support/Entregas/SituacaoDoMotoboy.php';
    require '/repo/api/app/Support/Entregas/PrazoDaEntrega.php';
    require '/repo/api/app/Support/Entregas/Painel/AgregadorDoPainel.php';
    require '/repo/api/app/Support/Entregas/ResumoDoDiaDaLoja.php';

    // como na produção: o PHP no fuso do app (America/Sao_Paulo), o mesmo da sessão do MySQL
    date_default_timezone_set('America/Sao_Paulo');

    const EMPRESA = 'empresa-a';
    $agora = new Carbon('2026-10-21 00:30:00');

    function now() { global $agora; return $agora; }

    $falhas = 0;
    function confere(bool $ok, string $caso): void
    {
        global $falhas;
        if (!$ok) {
            $falhas++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $caso . PHP_EOL;
    }

    function pedido(string $id, ?string $status, string $dono, string $atualizado, ?string $rastreio = null, string $empresa = EMPRESA, ?string $criado = null, ?string $aceito = null, ?string $prazo = null): object
    {
        return (object) ['public_id' => $id, 'company_uuid' => $empresa, 'status' => $status, 'customer_uuid' => $dono,
            'updated_at' => new Carbon($atualizado), 'tracking_number_uuid' => $rastreio,
            'created_at' => new Carbon($criado ?? '2026-10-20 10:00:00'), 'started_at' => $aceito ? new Carbon($aceito) : null,
            'dispatched_at' => null, 'meta' => $prazo ? ['entregas_prazo_em' => $prazo] : []];
    }

    function conclusao(string $rastreio, string $quando, string $empresa = EMPRESA, ?string $apagado = null): object
    {
        return (object) ['tracking_number_uuid' => $rastreio, 'company_uuid' => $empresa, 'code' => 'COMPLETED', 'created_at' => new Carbon($quando), 'deleted_at' => $apagado];
    }

    $donos = ['vendor-a', 'contato-a'];

    Order::$todos = [
        // em andamento
        pedido('o_criado', 'created', 'vendor-a', '2026-10-21 00:10:00', null, EMPRESA, '2026-10-21 00:09:00'),
        pedido('o_a_caminho', 'enroute', 'contato-a', '2026-10-20 23:50:00'),
        pedido('o_velho', 'started', 'vendor-a', '2026-10-20 12:00:00'),
        pedido('o_outra_loja', 'started', 'vendor-b', '2026-10-21 00:10:00'),
        pedido('o_outra_empresa', 'created', 'vendor-a', '2026-10-21 00:10:00', null, 'empresa-b'),
        pedido('o_cancelado', 'canceled', 'vendor-a', '2026-10-21 00:10:00'),
        pedido('o_sem_status', null, 'vendor-a', '2026-10-21 00:10:00'),
        // entregues
        // no prazo (00:15 antes das 00:20), 20 min do aceite à conclusão; criado ontem
        pedido('o_hoje', 'completed', 'vendor-a', '2026-10-21 00:20:00', 'rastreio-hoje', EMPRESA, '2026-10-20 23:40:00', '2026-10-20 23:55:00', '2026-10-21T00:20:00-03:00'),
        // atrasado (00:01 depois das 00:00), 40 min do aceite; criado hoje
        pedido('o_hoje_contato', 'completed', 'contato-a', '2026-10-21 00:25:00', 'rastreio-hoje-2', EMPRESA, '2026-10-21 00:00:30', '2026-10-20 23:21:00', '2026-10-21T00:00:00-03:00'),
        pedido('o_ontem_tarde', 'completed', 'vendor-a', '2026-10-21 00:05:00', 'rastreio-ontem'),
        pedido('o_hoje_outra_loja', 'completed', 'vendor-b', '2026-10-21 00:20:00', 'rastreio-b'),
        pedido('o_conclusao_apagada', 'completed', 'vendor-a', '2026-10-21 00:20:00', 'rastreio-apagado'),
        pedido('o_reaberto', 'enroute', 'vendor-a', '2026-10-21 00:20:00', 'rastreio-reaberto'),
    ];
    DB::$tabelas['tracking_statuses'] = [
        conclusao('rastreio-hoje', '2026-10-21 00:15:00'),
        conclusao('rastreio-hoje-2', '2026-10-21 00:01:00'),
        // 23:59 de Brasília do dia anterior: ainda é ontem (em UTC já seria 02:59 de hoje)
        conclusao('rastreio-ontem', '2026-10-20 23:59:00'),
        conclusao('rastreio-b', '2026-10-21 00:15:00'),
        conclusao('rastreio-apagado', '2026-10-21 00:15:00', EMPRESA, '2026-10-21 00:16:00'),
        conclusao('rastreio-reaberto', '2026-10-21 00:15:00'),
        conclusao('rastreio-hoje', '2026-10-21 00:15:00', 'empresa-b'),
    ];
    Driver::$todos = [
        (object) ['company_uuid' => EMPRESA, 'online' => 1],
        (object) ['company_uuid' => EMPRESA, 'online' => true],
        (object) ['company_uuid' => EMPRESA, 'online' => 0],
        (object) ['company_uuid' => 'empresa-b', 'online' => 1],
    ];

    echo '== ResumoDoDiaDaLoja::contar' . PHP_EOL;
    $resumo = ResumoDoDiaDaLoja::contar(EMPRESA, $donos);
    confere(array_keys($resumo) === ['em_andamento', 'entregues_hoje', 'motoboys_online', 'pedidos_hoje', 'no_prazo', 'tempo_medio_min'], 'só os seis números, nesta ordem');
    confere($resumo['em_andamento'] === 3, 'em andamento: os não encerrados da loja e do contato mexidos nas últimas 12 h (veio ' . $resumo['em_andamento'] . ')');
    confere($resumo['entregues_hoje'] === 2, 'entregues hoje: concluídos da loja e do contato com a conclusão de hoje em Brasília (veio ' . $resumo['entregues_hoje'] . ')');
    confere($resumo['motoboys_online'] === 2, 'motoboys online: os da empresa (veio ' . $resumo['motoboys_online'] . ')');
    confere($resumo['pedidos_hoje'] === 2, 'pedidos hoje: os da loja e do contato criados hoje em Brasília, em qualquer situação (veio ' . $resumo['pedidos_hoje'] . ')');
    confere($resumo['no_prazo'] === 50, 'no prazo: 1 de 2 entregues hoje dentro do prazo (veio ' . var_export($resumo['no_prazo'], true) . ')');
    confere($resumo['tempo_medio_min'] === 30, 'tempo médio: média de 20 e 40 min do aceite à conclusão (veio ' . var_export($resumo['tempo_medio_min'], true) . ')');

    echo '== Sem donos (usuário sem loja nem contato)' . PHP_EOL;
    $vazio = ResumoDoDiaDaLoja::contar(EMPRESA, []);
    confere($vazio['em_andamento'] === 0 && $vazio['entregues_hoje'] === 0 && $vazio['pedidos_hoje'] === 0, 'sem donos, nenhum pedido conta (não vira "todos os pedidos")');
    confere($vazio['no_prazo'] === null && $vazio['tempo_medio_min'] === null, 'sem entregues, no prazo e tempo médio vazios (null, não 0)');

    echo '== Nenhuma conclusão hoje' . PHP_EOL;
    DB::$tabelas['tracking_statuses'] = [conclusao('rastreio-ontem', '2026-10-20 23:59:00')];
    $semHoje = ResumoDoDiaDaLoja::contar(EMPRESA, $donos);
    confere($semHoje['entregues_hoje'] === 0 && $semHoje['no_prazo'] === null, 'sem conclusão hoje, zero e sem % no prazo');

    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
}
