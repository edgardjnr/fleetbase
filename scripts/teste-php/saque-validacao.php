<?php

// Saque Pix: a validação de saque do Asaas (ValidacaoDeSaque::decidir). Só o saque nosso em andamento, com o mesmo id,
// valor e chave, é aprovado; o teste da central é recusado e confirma a configuração.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-validacao.php

require __DIR__ . '/stubs-saque.php';

use App\Support\Entregas\Saques\ConexaoAsaas;
use App\Support\Entregas\Saques\Saques;
use App\Support\Entregas\Saques\ValidacaoDeSaque;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Teste\Config;
use Teste\Relogio;

const U1 = 'aaaaaaaa-0000-4000-8000-000000000001';
const U2 = 'aaaaaaaa-0000-4000-8000-000000000002';

function pedidoDoAsaas(string $id, $valor, ?string $chave = '+5511999994321', string $tipo = 'TRANSFER'): array
{
    return ['type' => $tipo, 'transfer' => ['object' => 'transfer', 'id' => $id, 'value' => $valor, 'status' => 'PENDING', 'operationType' => 'PIX', 'bankAccount' => ['pixAddressKey' => $chave]]];
}

function recusa(string $motivo): array
{
    return [200, ['status' => 'REFUSED', 'refuseReason' => $motivo]];
}

echo '== Saque nosso' . PHP_EOL;
centralComSaque();
saqueEnviado('driver-1', U1, 50, ['id' => 'tra_1', 'status' => 'PENDING']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50)) === [200, ['status' => 'APPROVED']], 'id, valor e chave (com +55) conferem: APPROVED');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, null)) === [200, ['status' => 'APPROVED']], 'sem a chave no pedido: confere id e valor');
$log = json_encode(Log::$registros, JSON_UNESCAPED_UNICODE);
confere(str_contains($log, '[entregas] saque: validação') && str_contains($log, 'APPROVED') && !str_contains($log, TOKEN_DE_TESTE), 'decisão no log, sem o token');

echo '== Recusas' . PHP_EOL;
confere(ValidacaoDeSaque::decidir('token-errado', pedidoDoAsaas('tra_1', 50)) === [401, ['status' => 'REFUSED', 'refuseReason' => 'Token inválido.']], 'token errado: 401');
confere(ValidacaoDeSaque::decidir(null, pedidoDoAsaas('tra_1', 50))[0] === 401 && ValidacaoDeSaque::decidir('', pedidoDoAsaas('tra_1', 50))[0] === 401, 'sem token: 401');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, null, 'BILL')) === recusa('Operação não reconhecida pelo Entregas.'), 'pagamento de conta: recusado');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, ['type' => 'TRANSFER']) === recusa('Operação não reconhecida pelo Entregas.'), 'sem transfer: recusado');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_X', 50)) === recusa('Transferência não reconhecida pelo Entregas.'), 'id desconhecido: recusado (chave vazada não tira dinheiro)');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 49.99)) === recusa('Valor diferente do saque pedido.'), 'valor diferente');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, '21988887777')) === recusa('Chave Pix diferente da cadastrada.'), 'chave diferente');
DB::table('entregas_asaas_conexoes')->insert(['company_uuid' => 'empresa-2', 'chave_cifrada' => 'x', 'wallet_id' => 'w2', 'token_hash' => hash('sha256', 'token-da-outra')]);
confere(ValidacaoDeSaque::decidir('token-da-outra', pedidoDoAsaas('tra_1', 50)) === recusa('Transferência não reconhecida pelo Entregas.'), 'token de outra central: não acha o saque desta');
DB::table('entregas_saques')->where('uuid', U1)->update(['situacao' => 'concluido']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50)) === recusa('Transferência não reconhecida pelo Entregas.'), 'saque já concluído: não aprova de novo');
Config::$valores['services.entregas.saque'] = null;
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50)) === recusa('Saque Pix desligado no Entregas.'), 'chave geral desligada: recusa tudo');
Config::$valores['services.entregas.saque'] = '1';

echo '== O Asaas chamou antes de gravarmos o id' . PHP_EOL;
centralComSaque();
$s = Saques::pedir('empresa-1', 'driver-1', U2, 30)['saque'];
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_9', 30)) === [200, ['status' => 'APPROVED']], 'saque "enviando" recente, mesmo valor: aprovado');
confere(Saques::recarregar($s)->transfer_id === 'tra_9', 'e o id fica gravado nele');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_10', 30)) === recusa('Transferência não reconhecida pelo Entregas.'), 'outro id para o mesmo saque: recusado');
centralComSaque();
Saques::pedir('empresa-1', 'driver-1', U2, 30);
Relogio::$agora = '2026-10-27 10:02:01';
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_9', 30)) === recusa('Transferência não reconhecida pelo Entregas.'), 'mais de 2 min depois: recusado');

echo '== Teste da central' . PHP_EOL;
centralComSaque(['validacao_confirmada_em' => null, 'saque_ligado' => false, 'teste_transfer_id' => 'tra_teste', 'teste_pedido_em' => '2026-10-27 09:59:58']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_teste', 1)) === recusa('Teste do Entregas: validação funcionando.'), 'o teste é RECUSADO');
confere(ConexaoAsaas::daEmpresa('empresa-1')->validacao_confirmada_em === '2026-10-27 10:00:00', 'e confirma a validação');
centralComSaque(['validacao_confirmada_em' => null, 'saque_ligado' => false, 'teste_transfer_id' => null, 'teste_pedido_em' => '2026-10-27 09:59:58']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_t2', 1)) === recusa('Teste do Entregas: validação funcionando.') && ConexaoAsaas::daEmpresa('empresa-1')->teste_transfer_id === 'tra_t2', 'teste antes de gravarmos o id: reconhecido pelo R$ 1,00 recente');
centralComSaque(['validacao_confirmada_em' => null, 'saque_ligado' => false, 'teste_transfer_id' => null, 'teste_pedido_em' => '2026-10-27 09:59:58']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_t3', 2)) === recusa('Transferência não reconhecida pelo Entregas.') && ConexaoAsaas::daEmpresa('empresa-1')->validacao_confirmada_em === null, 'valor diferente de R$ 1,00: não é o teste');
Config::$valores['services.entregas.saque'] = null;
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_t4', 1)) === recusa('Teste do Entregas: validação funcionando.'), 'o teste funciona com a chave geral desligada (para configurar antes de ligar)');

resumo();
