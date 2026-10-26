<?php

// Stubs dos testes do saque Pix (scripts/teste-php/saque-*.php), sobre os do financeiro: o Hash (senha do admin), o
// vínculo do usuário com a empresa (CompanyUser, papel Administrator), o usuário do console, o arquivo do Docker secret
// com a chave do Cofre, a fila de respostas do Asaas (Http falso) e os cenários comuns.

namespace {
    require_once __DIR__ . '/stubs-financeiro.php';

    // a base das notificações do Laravel (as do saque a estendem; o envio é o notify do Driver falso)
    if (!class_exists(\Illuminate\Notifications\Notification::class, false)) {
        eval('namespace Illuminate\Notifications; class Notification {}');
    }
}

namespace Illuminate\Support\Facades {
    if (!class_exists(Hash::class, false)) {
        // o Hash::check do Laravel: nos testes, o "hash" da senha é "hash:<senha>"
        class Hash
        {
            public static function check($valor, $hash): bool { return is_string($hash) && $hash === 'hash:' . $valor; }
        }
    }
}

namespace Fleetbase\Models {
    if (!class_exists(CompanyUser::class, false)) {
        // o vínculo do usuário com a empresa (Spatie HasRoles): hasRole pelo nome do papel
        #[\AllowDynamicProperties]
        class CompanyUser
        {
            use \Teste\ModeloDeTeste;
            public $user_uuid;
            public $company_uuid;
            public array $papeis = [];
            public function hasRole($papel): bool { return in_array($papel, $this->papeis, true); }
        }
    }
}

namespace Teste {
    // o usuário da sessão no console (Auth::getUserFromSession): type admin = administrador do sistema
    class UsuarioDoSaque
    {
        public function __construct(public string $uuid, public string $type = 'user', public string $password = 'hash:senha-certa') {}
        public function isAdmin(): bool { return $this->type === 'admin'; }
        public function isNotAdmin(): bool { return $this->type !== 'admin'; }
    }
}

namespace {
    define('CHAVE_DO_COFRE', sys_get_temp_dir() . '/entregas_saque_chave');
    const CHAVE_ASAAS    = 'chave-asaas-de-teste-123456';
    const TOKEN_DE_TESTE = 'token-de-teste';

    /** Zera tudo, liga o saque e grava a chave do Cofre no "Docker secret" de teste. */
    function reiniciarSaque(): void
    {
        reiniciarFinanceiro();
        \Fleetbase\Models\CompanyUser::$todos                               = [];
        \Teste\Config::$valores['services.entregas.saque']                 = '1';
        \Teste\Config::$valores['services.entregas.saque_chave_arquivo']   = CHAVE_DO_COFRE;
        \Teste\Config::$valores['services.entregas.asaas_url']             = 'https://asaas.teste/v3';
        \Teste\Config::$valores['app.url']                                = 'https://entregas-api.teste';
        file_put_contents(CHAVE_DO_COFRE, base64_encode(str_repeat('k', 32)) . "\n");
        \Illuminate\Support\Str::$gerados = 0;
        \Teste\Relogio::$agora            = '2026-10-27 10:00:00';
    }

    /** Põe uma resposta do Asaas na fila do Http falso: [status, corpo]. */
    function asaas(int $status, $corpo = null): void
    {
        \Teste\Http::$respostas[] = [$status, $corpo];
    }

    /** Motoboy da empresa-1 com chave Pix (telefone) e uma corrida de $saldo reais. public_id = uuid com "_" no lugar de "-". */
    function motoboyComSaldo(string $uuid, string $nome, float $saldo = 100.0, string $chave = '11999994321'): void
    {
        \Fleetbase\FleetOps\Models\Driver::$todos[] = new \Fleetbase\FleetOps\Models\Driver([
            'uuid' => $uuid, 'public_id' => str_replace('-', '_', $uuid), 'company_uuid' => 'empresa-1', 'user_uuid' => 'user-' . $uuid, 'name' => $nome,
        ]);
        \App\Support\Entregas\Financeiro\ChavePix::salvar('empresa-1', $uuid, $chave, 'telefone');
        \App\Support\Entregas\Financeiro\Lancamentos::inserir([
            'company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => $uuid, 'tipo' => 'corrida', 'valor' => $saldo,
            'data_referencia' => '2026-10-26', 'order_uuid' => 'order-' . $uuid, 'chave_unica' => 'corrida:motoboy:order-' . $uuid,
        ]);
    }

    /**
     * A central empresa-1 conectada (chave CHAVE_ASAAS, wallet "wallet-1", token TOKEN_DE_TESTE), validação confirmada e
     * saque ligado com os padrões (ou os campos de $conexao); o motoboy driver-1 (João Silva) com chave 11999994321 e R$ 100.
     */
    function centralComSaque(array $conexao = []): void
    {
        reiniciarSaque();
        $antes = '2026-10-26 09:00:00';
        \Illuminate\Support\Facades\DB::table('entregas_asaas_conexoes')->insert(array_merge([
            'company_uuid' => 'empresa-1', 'chave_cifrada' => \App\Support\Entregas\Saques\Cofre::cifrar(CHAVE_ASAAS),
            'conta_nome' => 'Central Teste', 'conta_documento' => '12345678000199', 'wallet_id' => 'wallet-1',
            'conectada_por' => 'user-admin', 'conectada_em' => $antes, 'token_hash' => hash('sha256', TOKEN_DE_TESTE),
            'token_gerado_em' => $antes, 'validacao_confirmada_em' => $antes, 'saque_ligado' => true,
            'created_at' => $antes, 'updated_at' => $antes,
        ], $conexao));
        motoboyComSaldo('driver-1', 'João Silva');
    }

    /** O saque pedido e enviado: a wallet confere e o Asaas responde $transferencia ao POST /transfers. */
    function saqueEnviado(string $driver, string $uuid, float $valor, array $transferencia): object
    {
        $pedido = \App\Support\Entregas\Saques\Saques::pedir('empresa-1', $driver, $uuid, $valor);
        asaas(200, ['data' => [['id' => 'wallet-1']]]);
        asaas(200, $transferencia);

        return \App\Support\Entregas\Saques\Saques::enviar($pedido['saque']);
    }

    /** O saque relido do banco pelo uuid. */
    function saqueDoBanco(string $uuid): ?object
    {
        return \Illuminate\Support\Facades\DB::table('entregas_saques')->where('uuid', $uuid)->first();
    }
}
