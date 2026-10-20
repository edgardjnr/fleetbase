<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Integracao\CancelamentoPelaOrigem;
use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao;
use App\Support\Entregas\Integracao\ErroDaIntegracao;
use App\Support\Entregas\Integracao\Integracao;
use App\Support\Entregas\Integracao\PedidoDaIntegracao;
use App\Support\Entregas\Integracao\PedidosDaIntegracao;
use App\Support\Entregas\Integracao\UrlDoWebhook;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a API dos sistemas de origem (v1/entregas/integracao/*), autenticada pela chave da loja no
 * Bearer (ChavesDeIntegracao), fora do grupo fleetbase.api (que exige a chave do Fleetbase). A chave só enxerga os
 * pedidos da loja dela. Erros sempre {"errors": ["…"]}: 401 (chave), 409 (desligada, loja sem coleta ou apagada,
 * concluído), 422 (validação), 404, 503 (trava ocupada). Contrato: spec 2026-10-12-integracao-restaurantepro-design.md.
 *
 * - conexao: registra o sistema e a URL dos avisos; devolve o nome da loja e o segredo (só aqui);
 * - criar: 201 com o pedido novo; 200 com o mesmo pedido quando o pedido_externo_id repete;
 * - consultar: status, motoboy (depois do aceite), código e link de rastreio, cancelamento;
 * - cancelar: cancelado | cancelado_pago, ou 409 ja_concluido.
 */
class IntegracaoController extends Controller
{
    public function conexao(Request $request)
    {
        [$loja, $erro] = $this->loja($request);
        if ($erro) {
            return $erro;
        }

        $sistema = $request->input('sistema');
        if (!is_string($sistema) || trim($sistema) === '' || mb_strlen(trim($sistema)) > 40) {
            return response()->json(['errors' => ['sistema é obrigatório (até 40 caracteres).']], 422);
        }
        $url    = $request->input('webhook_url');
        $motivo = is_string($url) ? UrlDoWebhook::recusa(trim($url)) : 'informe a URL';
        if ($motivo !== null) {
            return response()->json(['errors' => ['webhook_url recusada: ' . $motivo . '.']], 422);
        }

        $segredo = ChavesDeIntegracao::conectar($loja, $sistema, trim($url));
        $vendor  = Vendor::where('uuid', $loja->vendor_uuid)->first();

        return response()->json(['loja' => ['nome' => $vendor?->name], 'webhook_segredo' => $segredo]);
    }

    public function criar(Request $request, CriadorDoPedidoDaIntegracao $criador)
    {
        [$loja, $erro] = $this->loja($request);
        if ($erro) {
            return $erro;
        }

        $validacao = PedidoDaIntegracao::validar((array) $request->all());
        if ($validacao['erros']) {
            return response()->json(['errors' => $validacao['erros']], 422);
        }

        static::avisarItensDescartados($loja, (array) $request->all(), $validacao['dados']);

        try {
            [$linha, $novo] = $criador->criar($loja, $validacao['dados']);
        } catch (ErroDaIntegracao $e) {
            return response()->json(['errors' => [$e->getMessage()]], $e->status);
        }

        return response()->json(static::recurso($linha, $this->order($linha)), $novo ? 201 : 200);
    }

    /**
     * Os `itens` são um extra e nunca recusam o pedido; se a origem mandou e a normalização aceitou menos do que veio
     * (inclusive zero), fica um log só com as contagens (nunca o conteúdo) para a central achar o motivo.
     */
    protected static function avisarItensDescartados(object $loja, array $corpo, array $dados): void
    {
        $bruto = PedidoDaIntegracao::campo($corpo, 'itens');
        if ($bruto === null) {
            return;
        }

        $recebidos = is_array($bruto) ? count($bruto) : 1;
        $aceitos   = count($dados['itens'] ?? []);
        if ($aceitos < $recebidos) {
            Log::info('[entregas] integração: itens descartados', [
                'loja'           => $loja->vendor_uuid,
                'pedido_externo' => $dados['pedido_externo_id'],
                'recebidos'      => $recebidos,
                'aceitos'        => $aceitos,
            ]);
        }
    }

    public function consultar(Request $request, string $id)
    {
        [$loja, $erro] = $this->loja($request);
        if ($erro) {
            return $erro;
        }

        $linha = PedidosDaIntegracao::daLoja($loja, $id);
        if (!$linha) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        return response()->json(static::recurso($linha, $this->order($linha)));
    }

    public function cancelar(Request $request, string $id, CancelamentoPelaOrigem $cancelamento)
    {
        [$loja, $erro] = $this->loja($request);
        if ($erro) {
            return $erro;
        }

        $linha = PedidosDaIntegracao::daLoja($loja, $id);
        if (!$linha) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        try {
            $resultado = $cancelamento->cancelar($linha);
        } catch (LockTimeoutException $e) {
            return response()->json(['errors' => ['O pedido está sendo atualizado. Tente de novo em instantes.']], 503);
        }

        if ($resultado === CancelamentoPelaOrigem::JA_CONCLUIDO) {
            return response()->json(['resultado' => $resultado, 'errors' => ['O pedido já foi concluído.']], 409);
        }

        $linha = PedidosDaIntegracao::doPedido((string) $linha->order_uuid) ?? $linha;

        return response()->json(['resultado' => $resultado] + static::recurso($linha, $this->order($linha)));
    }

    /** O pedido como a origem vê: ids, status, rastreio, motoboy (só depois do aceite) e cancelamento. */
    public static function recurso(object $linha, ?object $pedido): array
    {
        $codigo  = $pedido ? ($pedido->tracking ?? null) : null;
        $motoboy = $pedido && $pedido->started ? ($pedido->driverAssigned ?? null) : null;

        return [
            'id'                => $pedido?->public_id,
            'pedido_externo_id' => $linha->pedido_externo_id,
            'status'            => $pedido?->status,
            'codigo_rastreio'   => $codigo,
            'link_rastreio'     => Integracao::linkDeRastreio($codigo),
            'motoboy'           => $motoboy ? ['nome' => $motoboy->name, 'foto_url' => $motoboy->photo_url ?? null] : null,
            'sem_localizacao'   => (bool) $linha->sem_localizacao,
            'cancelamento'      => $linha->cancelado_em ? ['por' => $linha->cancelado_por, 'pago' => (bool) $linha->pago_mesmo_cancelado] : null,
        ];
    }

    /** @return array{0: ?object, 1: mixed} [a loja da chave, null] ou [null, a resposta de erro] */
    protected function loja(Request $request): array
    {
        if (!Integracao::ligada()) {
            return [null, response()->json(['errors' => ['Integração desligada.']], 409)];
        }

        $loja = ChavesDeIntegracao::daChave($request->bearerToken());
        if (!$loja) {
            return [null, response()->json(['errors' => ['Chave de integração inválida ou revogada.']], 401)];
        }

        $vendor = Vendor::where('uuid', $loja->vendor_uuid)->where('company_uuid', $loja->company_uuid)->first();
        if (!$vendor) {
            return [null, response()->json(['errors' => ['Loja não encontrada no Entregas. Fale com a central.']], 409)];
        }

        session(['company' => $loja->company_uuid]);
        ChavesDeIntegracao::marcarUso($loja);

        return [$loja, null];
    }

    protected function order(?object $linha): ?Order
    {
        return $linha && $linha->order_uuid
            ? Order::where('uuid', $linha->order_uuid)->with(['trackingNumber', 'driverAssigned.user.avatar'])->first()
            : null;
    }
}
