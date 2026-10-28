<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\AceitesDaLoja;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\Financeiro\Faturas;
use App\Support\Entregas\Financeiro\FaturasDaLoja;
use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Ifood\PedidosIfood;
use App\Support\Entregas\Integracao\PedidosDaIntegracao;
use App\Support\Entregas\ItensDoPedido;
use App\Support\Entregas\LojaDoUsuario;
use App\Support\Entregas\MotoboysNoMapaDaLoja;
use App\Support\Entregas\PedidosNoMapa;
use App\Support\Entregas\Portal\PedidosDoPortal;
use App\Support\Entregas\PrePago\CarteiraDaLoja;
use App\Support\Entregas\ResumoDoDiaDaLoja;
use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\ValorDaEntrega;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: dados do portal da loja (usuário type=customer).
 * Tudo filtrado pela loja do usuário da sessão; a loja nunca vem por parâmetro.
 */
class PortalLojaController extends Controller
{
    /** Rotas novas calculadas por consulta do extrato (o portal não repete a chamada como a tela da central). */
    public const LIMITE_CALCULOS_PORTAL = 10;

    /**
     * Maior período do extrato, em dias entre o início e o fim: 3 meses (do dia 1 até o dia 1 três meses depois são, no
     * máximo, 92 dias). O portal confere o mesmo limite antes de consultar (MAX_DIAS no controller do extrato).
     */
    public const MAX_DIAS_EXTRATO = 92;

    public function minhaLoja(CalculoEntregas $calculo)
    {
        $vendor = $this->lojaDaSessao();
        $coleta = LojaDoUsuario::coleta($vendor);

        return response()->json([
            'loja' => [
                'id'       => $vendor->public_id,
                'nome'     => $vendor->name,
                'endereco' => $coleta ? $calculo->enderecoCurto($coleta) : null,
                'cidade'   => $coleta?->city,
                // o portal mostra a coleta e desenha a rota com isto; quem grava a coleta no pedido é o servidor
                'coleta'   => $coleta ? [
                    'id'           => $coleta->public_id,
                    'uuid'         => $coleta->uuid,
                    'public_id'    => $coleta->public_id,
                    'name'         => $vendor->name,
                    'street1'      => $coleta->street1,
                    'street2'      => $coleta->street2,
                    'neighborhood' => $coleta->neighborhood,
                    'city'         => $coleta->city,
                    'latitude'     => $coleta->location?->getLat(),
                    'longitude'    => $coleta->location?->getLng(),
                ] : null,
            ],
        ]);
    }

    public function extrato(Request $request, CalculoEntregas $calculo)
    {
        $vendor = $this->lojaDaSessao();

        $request->validate([
            'inicio' => ['required', 'date_format:Y-m-d'],
            'fim'    => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
        ]);

        // até 3 meses por consulta: o extrato carrega de uma vez todos os pedidos do período (cerca de 7 models por pedido) e
        // calcula rotas, e a loja pode chamar quando quiser. Um ano de loja movimentada passa de 36 mil entregas, o que estoura
        // a memória do PHP e o tempo do Octane
        $dias = Carbon::parse($request->input('inicio'), 'UTC')->diffInDays(Carbon::parse($request->input('fim'), 'UTC'));
        if ($dias > static::MAX_DIAS_EXTRATO) {
            return response()->json(['errors' => ['Escolha um período de até 3 meses.']], 422);
        }

        $companyUuid = session('company');
        $fuso        = Company::where('uuid', $companyUuid)->value('timezone') ?: 'America/Sao_Paulo';
        $inicio      = Carbon::createFromFormat('Y-m-d', $request->input('inicio'), $fuso)->startOfDay()->setTimezone(date_default_timezone_get());
        $fim         = Carbon::createFromFormat('Y-m-d', $request->input('fim'), $fuso)->endOfDay()->setTimezone(date_default_timezone_get());

        $pedidos = $calculo->pedidosConcluidos($companyUuid, $inicio, $fim, function ($query) use ($vendor) {
            $query->where('orders.customer_uuid', $vendor->uuid);
        });
        [$entregas, $pendentes] = $calculo->entregas($pedidos, $fuso, static::LIMITE_CALCULOS_PORTAL);

        $entregas = array_map(fn ($e) => static::entregaDoExtrato($e), $entregas);

        return response()->json([
            'inicio'    => $request->input('inicio'),
            'fim'       => $request->input('fim'),
            'pendentes' => $pendentes,
            'totais'    => [
                'entregas' => count($entregas),
                'km'       => round(collect($entregas)->sum(fn ($e) => $e['km'] ?? 0), 2),
                'valor'    => round(collect($entregas)->sum(fn ($e) => $e['valor'] ?? 0), 2),
            ],
            'entregas'  => $entregas,
        ]);
    }

    /**
     * Uma entrega do extrato como a loja vê: sem o valor pago ao motoboy nem quem levou. Recebe uma linha de
     * CalculoEntregas::entregas().
     */
    public static function entregaDoExtrato(array $e): array
    {
        return [
            'pedido'       => $e['pedido'],
            'id_interno'   => $e['id_interno'],
            'concluido_em' => $e['concluido_em'],
            'destino'      => $e['destino'],
            'km'           => $e['km'],
            'faixa'        => $e['faixa'] ? ['de_km' => $e['faixa']['de_km'], 'ate_km' => $e['faixa']['ate_km'], 'acima' => $e['faixa']['acima'] ?? false] : null,
            'valor'        => $e['valor_loja'],
            // pedido do iFood cancelado pelo iFood depois da coleta: a entrega é cobrada pela data do cancelamento
            'cancelado_pago' => (bool) ($e['cancelado_pago'] ?? false),
            // quem cancelou: 'ifood' ou 'integracao' (o sistema de origem); null no concluído
            'cancelado_origem' => $e['cancelado_origem'] ?? null,
            // entrega junto e multiplicador: o selo da loja só quando o valor dela mudou (fator ≠ 1); nunca o do motoboy
            'junto'         => ($e['tabela'] ?? null) === 'junto' && !empty($e['posicao']) ? (int) $e['posicao'] : null,
            'multiplicador' => ValorDaEntrega::daLoja($e['multiplicador'] ?? null),
        ];
    }

    /** Motoboy do pedido em andamento (nome, foto e aceite). Só pedido que o portal mostra a este usuário. */
    public function motoboy(string $id)
    {
        $vendor = $this->lojaDaSessao();

        // name e photo_url do Driver leem o usuário dele e o avatar do usuário: vêm junto, e não sob demanda dentro dos accessors
        $pedido = Order::where('company_uuid', session('company'))
            ->whereIn('customer_uuid', $this->donosDosPedidos($vendor))
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
            ->with('driverAssigned.user.avatar')
            ->firstOrFail();

        $motoboy = $pedido->driverAssigned;
        if (!$motoboy || in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
            return response()->json(['motoboy' => null]);
        }

        // a posição fica no mapa de motoboys (motoboysNoMapa), que mostra todos os motoboys online
        return response()->json([
            'motoboy' => [
                'nome'    => $motoboy->name,
                'foto'    => $motoboy->photo_url,
                'aceitou' => (bool) $pedido->started,
            ],
        ]);
    }

    /**
     * Os produtos do pedido (iFood ou sistema da loja), os mesmos que o motoboy vê na comanda (ItensDoPedido), e a
     * observação do cliente. Só pedido da loja da sessão; pedido feito no portal (sem itens; a observação do chamar
     * motoboy vem da entregas_portal_pedidos) ou antigo: itens null. Decisão do Edgard de 2026-10-24.
     */
    public function itens(string $id)
    {
        $vendor = $this->lojaDaSessao();

        $pedido = Order::where('company_uuid', session('company'))
            ->whereIn('customer_uuid', $this->donosDosPedidos($vendor))
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
            ->firstOrFail();

        $linha = PedidosIfood::doPedido((string) $pedido->uuid) ?? PedidosDaIntegracao::doPedido((string) $pedido->uuid) ?? PedidosDoPortal::doPedido((string) $pedido->uuid);
        $observacao = $linha ? trim((string) ($linha->observacoes ?? '')) : '';

        return response()->json([
            'itens'      => $linha ? ItensDoPedido::doBanco($linha->itens ?? null, ['pedido' => $pedido->public_id]) : null,
            'observacao' => $observacao !== '' ? $observacao : null,
        ]);
    }

    /**
     * Motoboys no mapa do portal (todos os online e os offline com pedido aceito), consultado a cada 5 s com o mapa aberto.
     * Sem id, telefone nem pedido de outra loja (MotoboysNoMapaDaLoja). Traz também os alfinetes dos pedidos em andamento
     * da loja (PedidosNoMapa::daLoja): só os dela, nunca os das outras lojas.
     */
    public function motoboysNoMapa()
    {
        $vendor = $this->lojaDaSessao();
        $donos  = $this->donosDosPedidos($vendor);

        return response()->json([
            'motoboys' => MotoboysNoMapaDaLoja::listar(session('company'), $donos),
            'pedidos'  => PedidosNoMapa::daLoja(session('company'), $donos),
        ]);
    }

    /**
     * Resumo do dia do topo e do Início: pedidos em andamento e entregues hoje da loja, os motoboys online (só números) e
     * a `carteira` (CarteiraDaLoja::resumo: o modelo de cobrança e, na pré-paga, o crédito), que troca a aba Financeiro
     * pela Minha carteira.
     */
    public function resumoDoDia()
    {
        $vendor = $this->lojaDaSessao();

        return response()->json(ResumoDoDiaDaLoja::contar(session('company'), $this->donosDosPedidos($vendor)) + [
            'carteira' => CarteiraDaLoja::resumo((string) session('company'), (string) $vendor->uuid),
        ]);
    }

    /**
     * Os aceites recentes dos pedidos da loja (AceitesDaLoja), para a buzina do portal, que lê a cada 10 s em qualquer
     * tela (serviço entregas-buzina). Usuário sem loja: 404.
     */
    public function aceites()
    {
        $vendor = $this->lojaDaSessao();

        return response()->json(['aceites' => AceitesDaLoja::listar((string) session('company'), $this->donosDosPedidos($vendor))]);
    }

    /**
     * Financeiro da loja: o ciclo em andamento (lançamentos ainda sem fatura) e as faturas emitidas e canceladas, mais nova
     * primeiro (formatos "Portal loja/financeiro" e "Fatura" pública do contrato do Financeiro). Rascunho não aparece. A
     * fatura sai pelo mesmo Faturas::resumo do console (público: sem email_enviado_em): os números são os mesmos.
     */
    public function financeiro()
    {
        $vendor      = $this->lojaDaSessao();
        $companyUuid = (string) session('company');
        $lancamentos = Lancamentos::daConta($companyUuid, Financeiro::CONTA_LOJA, $vendor->uuid);
        $hoje        = Financeiro::hoje($companyUuid);
        $situacoes   = Faturas::situacoesDaLoja($companyUuid, (string) $vendor->uuid);

        // os itens de cada fatura, dos lançamentos já lidos (o resumo só conta as entregas)
        $itens = [];
        foreach ($lancamentos as $lancamento) {
            if (($lancamento->fatura_uuid ?? null) !== null) {
                $itens[(string) $lancamento->fatura_uuid][] = $lancamento;
            }
        }

        $faturas = array_map(
            fn ($fatura) => Faturas::resumo($fatura, $situacoes, (string) $vendor->name, true, (string) $vendor->public_id, $itens[(string) $fatura->uuid] ?? []),
            FaturasDaLoja::daLoja($companyUuid, $vendor->uuid, FaturasDaLoja::VISIVEIS)
        );

        return response()->json([
            'ciclo_atual' => FaturasDaLoja::cicloAtual($lancamentos, $hoje, FaturasDaLoja::periodoEmAndamento($companyUuid, $vendor->uuid, $hoje)),
            'faturas'     => $faturas,
        ]);
    }

    /**
     * Uma fatura da loja (o :id é o uuid) com os itens, para a tela e a impressão. Rascunho, fatura de outra loja ou de
     * outra empresa → 404 (a mesma resposta de inexistente, para não revelar que existe). Fatura e itens pelos mesmos
     * Faturas::resumo e Faturas::itensFormatados do console, no formato público.
     */
    public function fatura(string $id)
    {
        $vendor      = $this->lojaDaSessao();
        $companyUuid = (string) session('company');
        $fatura      = FaturasDaLoja::porUuid($companyUuid, $id);

        if (!$fatura || $fatura->vendor_uuid !== $vendor->uuid || !in_array($fatura->situacao, FaturasDaLoja::VISIVEIS, true)) {
            return response()->json(['errors' => ['Fatura não encontrada.']], 404);
        }

        $situacoes = $fatura->situacao === 'emitida' ? Faturas::situacoesDaLoja($companyUuid, (string) $vendor->uuid) : [];
        $coleta    = LojaDoUsuario::coleta($vendor);
        $endereco  = $coleta ? implode(', ', array_filter([$coleta->street1 ?? null, $coleta->street2 ?? null, $coleta->neighborhood ?? null, $coleta->city ?? null])) : '';

        return response()->json([
            'fatura'  => Faturas::resumo($fatura, $situacoes, (string) $vendor->name, true, (string) $vendor->public_id),
            'itens'   => Faturas::itensFormatados($fatura, true),
            'loja'    => ['nome' => $vendor->name, 'endereco' => $endereco === '' ? null : $endereco],
            'empresa' => ['nome' => DB::table('companies')->where('uuid', $companyUuid)->value('name')],
        ]);
    }

    /**
     * Os donos de pedido que o portal mostra a este usuário (PortalOrderService::accountCustomerUuids): a loja e o contato
     * do usuário (a central pode lançar o pedido no contato; só com a loja, esse pedido aparece no portal).
     *
     * @return array<int, string>
     */
    protected function donosDosPedidos(Vendor $vendor): array
    {
        return array_values(array_filter([
            $vendor->uuid,
            LojaDoUsuario::contato(session('user'))?->uuid,
        ]));
    }

    protected function lojaDaSessao(): Vendor
    {
        $vendor = LojaDoUsuario::vendor(session('user'));
        abort_if(!$vendor, 404, 'Usuário sem loja.');

        return $vendor;
    }
}
