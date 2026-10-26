<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Financeiro\ChavePix;
use App\Support\Entregas\Saques\AdminDaCentral;
use App\Support\Entregas\Saques\CofreIndisponivel;
use App\Support\Entregas\Saques\ConexaoAsaas;
use App\Support\Entregas\Saques\ErroDoAsaas;
use App\Support\Entregas\Saques\Saque;
use App\Support\Entregas\Saques\Saques;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Entregas RestaurantePro: o saque Pix no console (Financeiro → Configurações e aba Saques), para o administrador do
 * sistema e o da organização (AdminDaCentral): conta Asaas da central, token e teste da validação (conectar,
 * desconectar, gerar o token e testar pedem a senha), limites e interruptor, lista dos saques e bloqueio por motoboy. As respostas trazem a conexão no
 * formato público (ConexaoAsaas::publico): nunca a chave nem o token (o token sai só no gerarToken).
 */
class SaquePixController extends Controller
{
    public const NEGADO = 'Somente administradores podem mexer no saque Pix.';

    public const DIAS_DA_LISTA = 30;

    /** GET financeiro/saque-pix → a conexão. */
    public function situacao(Request $request)
    {
        if (!AdminDaCentral::usuario($request)) {
            return $this->negado();
        }

        return $this->conexao();
    }

    /** POST financeiro/saque-pix/conexao {chave, senha}: conecta ou troca a chave (mesma conta). */
    public function conectar(Request $request)
    {
        $usuario = AdminDaCentral::usuario($request);
        if (!$usuario) {
            return $this->negado();
        }
        if (!$this->senhaConfere($request, $usuario)) {
            return $this->erro('Senha incorreta.');
        }

        return $this->tentar(fn () => ConexaoAsaas::conectar($this->empresa(), $request->input('chave'), (string) $usuario->uuid));
    }

    /** DELETE financeiro/saque-pix/conexao {senha}. */
    public function desconectar(Request $request)
    {
        $usuario = AdminDaCentral::usuario($request);
        if (!$usuario) {
            return $this->negado();
        }
        if (!$this->senhaConfere($request, $usuario)) {
            return $this->erro('Senha incorreta.');
        }

        return $this->tentar(fn () => ConexaoAsaas::desconectar($this->empresa(), (string) $usuario->uuid));
    }

    /** POST financeiro/saque-pix/token {senha} → {token, url, conexao}: o token aparece só aqui. */
    public function gerarToken(Request $request)
    {
        $usuario = AdminDaCentral::usuario($request);
        if (!$usuario) {
            return $this->negado();
        }
        if (!$this->senhaConfere($request, $usuario)) {
            return $this->erro('Senha incorreta.');
        }
        try {
            $token = ConexaoAsaas::gerarToken($this->empresa());
        } catch (\InvalidArgumentException $e) {
            return $this->erro($e->getMessage());
        }

        return response()->json([
            'token'   => $token,
            'url'     => ConexaoAsaas::urlDaValidacao(),
            'conexao' => ConexaoAsaas::publico(ConexaoAsaas::daEmpresa($this->empresa()), $this->empresa()),
        ]);
    }

    /**
     * POST financeiro/saque-pix/teste {tipo, chave, senha}: R$ 1,00 para a chave do admin, que a validação precisa
     * recusar. Um teste por vez e no máximo 5 por dia (422 "Espere o resultado do teste anterior." / "Limite de testes de
     * hoje atingido."); grava e loga quem pediu.
     */
    public function testar(Request $request)
    {
        $usuario = AdminDaCentral::usuario($request);
        if (!$usuario) {
            return $this->negado();
        }
        if (!$this->senhaConfere($request, $usuario)) {
            return $this->erro('Senha incorreta.');
        }

        return $this->tentar(fn () => ConexaoAsaas::testarValidacao($this->empresa(), $request->input('tipo'), $request->input('chave'), (string) $usuario->uuid));
    }

    /** PUT financeiro/saque-pix {saque_ligado?, minimo?, maximo_dia?, saques_dia?, taxa?}. */
    public function configurar(Request $request)
    {
        $usuario = AdminDaCentral::usuario($request);
        if (!$usuario) {
            return $this->negado();
        }

        return $this->tentar(fn () => ConexaoAsaas::configurar($this->empresa(), $request->all(), (string) $usuario->uuid));
    }

    /**
     * GET financeiro/saques?situacao → {saques (últimos 30 dias, mais novo primeiro, com o motoboy; `motivo` é o texto do
     * Asaas quando houver, e `motivo_asaas` vem à parte), motoboys (com chave Pix: id, nome, chave escondida, tipo,
     * bloqueado, liberado_em)}.
     */
    public function saques(Request $request)
    {
        if (!AdminDaCentral::usuario($request)) {
            return $this->negado();
        }
        $situacao = $request->input('situacao');
        if ($situacao !== null && $situacao !== '' && !in_array($situacao, Saque::SITUACOES, true)) {
            return $this->erro('Situação inválida.');
        }

        $empresa  = $this->empresa();
        $consulta = DB::table(Saque::TABELA)
            ->where('company_uuid', $empresa)
            ->where('created_at', '>=', now()->subDays(static::DIAS_DA_LISTA)->format('Y-m-d H:i:s'));
        if ($situacao) {
            $consulta->where('situacao', $situacao);
        }
        $linhas = $consulta->orderBy('id', 'desc')->get()->all();

        $motoboys = [];
        foreach (Driver::withTrashed()->where('company_uuid', $empresa)->get() as $motoboy) {
            $motoboys[(string) $motoboy->uuid] = $motoboy;
        }

        $saques = array_map(fn ($saque) => Saques::publico($saque, true) + ['motoboy' => [
            'id'   => $motoboys[(string) $saque->driver_uuid]->public_id ?? null,
            'nome' => $motoboys[(string) $saque->driver_uuid]->name ?? null,
        ]], $linhas);

        $comPix = [];
        foreach (DB::table(ChavePix::TABELA)->where('company_uuid', $empresa)->whereNotNull('chave_pix')->get() as $linha) {
            $motoboy = $motoboys[(string) $linha->driver_uuid] ?? null;
            if (!$motoboy || ($motoboy->deleted_at ?? null) !== null) {
                continue;
            }
            $liberado = Saques::liberadoEm($linha);
            $comPix[] = [
                'id'          => $motoboy->public_id,
                'nome'        => $motoboy->name,
                'chave'       => Saque::chaveEscondida((string) $linha->chave_pix, (string) $linha->tipo_chave),
                'tipo'        => (string) $linha->tipo_chave,
                'bloqueado'   => (bool) ($linha->saque_bloqueado ?? false),
                'liberado_em' => Saque::iso($liberado, $empresa),
            ];
        }
        usort($comPix, fn ($a, $b) => strcmp((string) $a['nome'], (string) $b['nome']));

        return response()->json(['saques' => $saques, 'motoboys' => $comPix]);
    }

    /** PUT financeiro/motoboys/{id}/saque {bloqueado} → {bloqueado}. */
    public function bloquear(Request $request, string $id)
    {
        if (!AdminDaCentral::usuario($request)) {
            return $this->negado();
        }
        $motoboy = Driver::withTrashed()->where('company_uuid', $this->empresa())->where('public_id', $id)->first();
        if (!$motoboy) {
            return $this->erro('Motoboy não encontrado.', 404);
        }
        $bloqueado = filter_var($request->input('bloqueado'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bloqueado === null) {
            return $this->erro('Informe se o saque fica bloqueado.');
        }
        Saques::bloquear($this->empresa(), (string) $motoboy->uuid, $bloqueado);

        return response()->json(['bloqueado' => $bloqueado]);
    }

    /** Roda a ação e responde com a conexão; entrada inválida 422, Asaas 502, sem o secret 503. */
    protected function tentar(\Closure $fazer)
    {
        try {
            $fazer();
        } catch (\InvalidArgumentException $e) {
            return $this->erro($e->getMessage());
        } catch (ErroDoAsaas $e) {
            return $this->erro($e->getMessage(), 502);
        } catch (CofreIndisponivel $e) {
            return $this->erro($e->getMessage(), 503);
        }

        return $this->conexao();
    }

    protected function conexao()
    {
        return response()->json(ConexaoAsaas::publico(ConexaoAsaas::daEmpresa($this->empresa()), $this->empresa()));
    }

    protected function senhaConfere(Request $request, object $usuario): bool
    {
        $senha = $request->input('senha');
        $hash  = $usuario->password ?? null;

        return is_string($senha) && $senha !== '' && is_string($hash) && $hash !== '' && Hash::check($senha, $hash);
    }

    protected function empresa(): string
    {
        return (string) session('company');
    }

    protected function negado()
    {
        return $this->erro(static::NEGADO, 403);
    }

    protected function erro(string $mensagem, int $status = 422)
    {
        return response()->json(['errors' => [$mensagem]], $status);
    }
}
