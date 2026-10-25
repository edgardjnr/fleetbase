<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Painel\AoVivo;
use App\Support\Entregas\Painel\PainelInicial;
use App\Support\Entregas\TrocaDoMotoboy;
use DateTimeImmutable;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: painel inicial do console (rota console.home, componente home/painel-inicial do Fleet-Ops).
 *
 * - resumo: números do período (até 31 dias; sem datas = hoje no fuso da empresa), gráfico, lojas, motoboys e Atenção.
 *   Dinheiro e Atenção só para admin (null / []).
 * - aoVivo: pedidos em andamento (com a oferta da distribuição e a origem iFood) e os motoboys do mapa; o console relê
 *   a cada 20 s e nos eventos do socket.
 * - atribuir: a central escolhe o motoboy de um pedido sem motoboy (só admin). Usa a mesma troca do líder
 *   (TrocaDoMotoboy: trava do pedido, adhoc falso, um aviso só ao motoboy; o log sai como "líder trocou o motoboy").
 */
class PainelInicialController extends Controller
{
    public const DIAS_MAXIMOS = 31;

    public function resumo(Request $request)
    {
        $empresa = $this->empresa();
        $hoje    = Financeiro::hoje($empresa);
        $inicio  = (string) ($request->input('inicio') ?: $hoje);
        $fim     = (string) ($request->input('fim') ?: $hoje);

        if (!static::dia($inicio) || !static::dia($fim)) {
            return $this->erro('Período inválido.');
        }
        if ($fim < $inicio) {
            return $this->erro('O fim do período precisa ser depois do início.');
        }
        if ((new DateTimeImmutable($inicio))->diff(new DateTimeImmutable($fim))->days >= static::DIAS_MAXIMOS) {
            return $this->erro('Escolha um período de até 31 dias.');
        }

        return response()->json(PainelInicial::montar($empresa, $inicio, $fim, $this->ehAdmin($request)));
    }

    public function aoVivo()
    {
        return response()->json(AoVivo::montar($this->empresa()));
    }

    public function atribuir(Request $request, string $id)
    {
        if (!$this->ehAdmin($request)) {
            return response()->json(['errors' => ['Somente administradores podem atribuir o motoboy pelo painel.']], 403);
        }

        $usuario           = Auth::getUserFromSession($request);
        [$status, $corpo]  = TrocaDoMotoboy::trocar($this->empresa(), $id, trim((string) $request->input('motoboy', '')), (string) ($usuario?->uuid ?? ''));
        if ($status === 200) {
            AoVivo::esquecer($this->empresa());
        }

        return response()->json($corpo, $status);
    }

    protected static function dia(string $dia): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)
            && checkdate((int) substr($dia, 5, 2), (int) substr($dia, 8, 2), (int) substr($dia, 0, 4));
    }

    protected function ehAdmin(Request $request): bool
    {
        $usuario = Auth::getUserFromSession($request);

        return $usuario !== null && !$usuario->isNotAdmin();
    }

    protected function empresa(): string
    {
        return (string) session('company');
    }

    protected function erro(string $mensagem, int $status = 422)
    {
        return response()->json(['errors' => [$mensagem]], $status);
    }
}
