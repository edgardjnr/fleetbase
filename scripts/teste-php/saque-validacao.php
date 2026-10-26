<?php

// Saque Pix: a validação de saque do Asaas (ValidacaoDeSaque::decidir). Só o saque nosso em andamento, com o mesmo id,
// valor e chave, é aprovado; sem o id gravado, o externalReference (ou o valor, de um único candidato) só escolhe o
// saque, e a aprovação exige a chave Pix inteira e igual. operationType, se vier, tem de ser PIX; uma aprovação por
// saque (aprovado_transfer_id). A validação nunca grava o transfer_id (quem grava é o envio, com o id do nosso POST), e
// a conclusão confere a transferência com o saque. O teste da central é recusado e confirma a configuração.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-validacao.php

require __DIR__ . '/stubs-saque.php';

use App\Support\Entregas\Saques\AcompanhamentoDeSaques;
use App\Support\Entregas\Saques\ConexaoAsaas;
use App\Support\Entregas\Saques\Saques;
use App\Support\Entregas\Saques\ValidacaoDeSaque;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Teste\Config;
use Teste\Relogio;

const U1 = 'aaaaaaaa-0000-4000-8000-000000000001';
const U2 = 'aaaaaaaa-0000-4000-8000-000000000002';
const U3 = 'aaaaaaaa-0000-4000-8000-000000000003';

function pedidoDoAsaas(string $id, $valor, ?string $chave = '+5511999994321', string $tipo = 'TRANSFER', ?string $referencia = null): array
{
    $transfer = ['object' => 'transfer', 'id' => $id, 'value' => $valor, 'status' => 'PENDING', 'operationType' => 'PIX', 'bankAccount' => ['pixAddressKey' => $chave]];
    if ($referencia !== null) {
        $transfer['externalReference'] = $referencia;
    }

    return ['type' => $tipo, 'transfer' => $transfer];
}

function recusa(string $motivo): array
{
    return [200, ['status' => 'REFUSED', 'refuseReason' => $motivo]];
}

function aprovado(): array
{
    return [200, ['status' => 'APPROVED']];
}

/** As mensagens dos logs críticos desde o último reinício. */
function criticos(): array
{
    return array_values(array_map(fn ($r) => $r[1], array_filter(Log::$registros, fn ($r) => $r[0] === 'critical')));
}

echo '== Saque nosso (caminho por id: o id veio do nosso POST)' . PHP_EOL;
centralComSaque();
saqueEnviado('driver-1', U1, 50, ['id' => 'tra_1', 'status' => 'PENDING']);
confere(saqueDoBanco(U1)->aprovado_em === null, 'antes da validação: sem aprovado_em');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50)) === aprovado(), 'id, valor e chave (com +55) conferem: APPROVED');
confere(saqueDoBanco(U1)->aprovado_em === '2026-10-27 10:00:00', 'aprovado: grava aprovado_em no saque');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, null)) === aprovado(), 'sem a chave no pedido: confere id e valor');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, '', 'TRANSFER')) === aprovado(), 'chave vazia no pedido: como se não viesse');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, ['type' => 'TRANSFER', 'transfer' => ['id' => 'tra_1', 'value' => 50, 'pixAddressKey' => '21988887777']]) === recusa('Chave Pix diferente da cadastrada.'), 'chave em transfer.pixAddressKey também é conferida');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, null, 'TRANSFER', 'saque:' . U1)) === aprovado(), 'com o externalReference dele: APPROVED');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, null, 'TRANSFER', 'saque:outro')) === recusa('Transferência não reconhecida pelo Entregas.'), 'com outro externalReference: recusado');
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

echo '== Chave de telefone: comparada inteira (55 + DDD + número)' . PHP_EOL;
foreach (['+5511999994321' => true, '5511999994321' => true, '11999994321' => true, '+55 (11) 99999-4321' => true, '4321' => false, '1' => false, '' => false, '999994321' => false, '5521999994321' => false, '115511999994321' => false] as $deles => $esperado) {
    confere(ValidacaoDeSaque::mesmaChave('11999994321', 'telefone', (string) $deles) === $esperado, 'nossa 11999994321, deles "' . $deles . '": ' . ($esperado ? 'mesma' : 'diferente'));
}
confere(ValidacaoDeSaque::mesmaChave('5511999994321', 'telefone', '11999994321') && ValidacaoDeSaque::mesmaChave('1133334444', 'telefone', '+551133334444'), 'cadastro com 55 ou fixo de 10 dígitos: também');
confere(!ValidacaoDeSaque::mesmaChave('', 'telefone', '') && !ValidacaoDeSaque::mesmaChave('', 'cpf', ''), 'vazias: nunca são a mesma');
confere(ValidacaoDeSaque::mesmaChave('123.456.789-01', 'cpf', '12345678901') && !ValidacaoDeSaque::mesmaChave('12345678901', 'cpf', '2345678901'), 'CPF: sem pontuação, comparado inteiro');

echo '== Segunda revisão, N4: normalização da chave pelo tipo' . PHP_EOL;
confere(!ValidacaoDeSaque::mesmaChave('joao.silva@hotmail.com', 'email', 'joaosilva@hotmail.com'), 'PoC D: e-mail com ponto ≠ sem ponto');
confere(!ValidacaoDeSaque::mesmaChave('joao-silva@hotmail.com', 'email', 'joaosilva@hotmail.com') && !ValidacaoDeSaque::mesmaChave('joao+pix@gmail.com', 'email', 'joaopix@gmail.com'), 'e-mail: hífen e + também contam');
confere(ValidacaoDeSaque::mesmaChave('joao.silva@hotmail.com', 'email', '  Joao.Silva@HOTMAIL.com ') && !ValidacaoDeSaque::mesmaChave('joao.silva@hotmail.com', 'email', 'joao. silva@hotmail.com'), 'e-mail: só aparado e em minúsculas');
confere(ValidacaoDeSaque::mesmaChave('12.345.678/0001-99', 'cnpj', '12345678000199') && ValidacaoDeSaque::mesmaChave('12345678000199', 'cnpj', ' 12 345 678 0001 99') && !ValidacaoDeSaque::mesmaChave('12345678000199', 'cnpj', '12345678000198'), 'CNPJ: só os dígitos, comparado inteiro');
confere(ValidacaoDeSaque::mesmaChave('123e4567-e89b-12d3-a456-426614174000', 'aleatoria', ' 123E4567-E89B-12D3-A456-426614174000 ') && !ValidacaoDeSaque::mesmaChave('123e4567-e89b-12d3-a456-426614174000', 'aleatoria', '123e4567e89b12d3a456426614174000'), 'aleatória: minúsculas sem espaços (o hífen conta)');
confere(ValidacaoDeSaque::mesmaChave('11999994321', 'telefone', '+55 (11) 99999-4321'), 'telefone: como antes (55 + DDD + número)');
centralComSaque();
DB::table('entregas_motoboys_financeiro')->where('driver_uuid', 'driver-1')->update(['chave_pix' => 'joao.silva@hotmail.com', 'tipo_chave' => 'email']);
saqueEnviado('driver-1', U1, 50, ['id' => 'tra_1', 'status' => 'PENDING']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, 'joaosilva@hotmail.com')) === recusa('Chave Pix diferente da cadastrada.'), 'validação: e-mail de outra pessoa (sem o ponto) é recusado');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, 'Joao.Silva@hotmail.com')) === aprovado(), 'validação: o mesmo e-mail (outra caixa) é aprovado');
centralComSaque();
saqueEnviado('driver-1', U1, 50, ['id' => 'tra_1', 'status' => 'PENDING']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, '4321')) === recusa('Chave Pix diferente da cadastrada.'), 'pedido com só o final do telefone: recusado');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, '1')) === recusa('Chave Pix diferente da cadastrada.'), 'pedido com um dígito: recusado');

echo '== O Asaas chamou antes de gravarmos o id: pelo externalReference' . PHP_EOL;
centralComSaque();
$s = Saques::pedir('empresa-1', 'driver-1', U2, 30)['saque'];
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_9', 30, '+5511999994321', 'TRANSFER', 'saque:' . U2)) === aprovado(), 'externalReference do saque "enviando", mesmo valor e chave: aprovado');
confere(Saques::recarregar($s)->transfer_id === null && Saques::recarregar($s)->aprovado_em === '2026-10-27 10:00:00', 'a validação NÃO grava o transfer_id (só aprovado_em)');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_9', 30, null, 'TRANSFER', 'saque:' . U2)) === recusa('Transferência sem chave Pix para conferir.'), 'externalReference certo, sem chave no pedido: recusado (o externalReference só escolhe; quem tem a chave de API pode copiá-lo)');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_9', 30, null, 'TRANSFER', 'saque:' . U3)) === recusa('Transferência não reconhecida pelo Entregas.'), 'externalReference de saque que não existe: recusado');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_9', 29.99, null, 'TRANSFER', 'saque:' . U2)) === recusa('Valor diferente do saque pedido.'), 'externalReference certo, valor diferente: recusado');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_9', 30, '21988887777', 'TRANSFER', 'saque:' . U2)) === recusa('Chave Pix diferente da cadastrada.'), 'externalReference certo, chave diferente: recusado');
Relogio::$agora = '2026-10-27 10:02:01';
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_9', 30, '+5511999994321', 'TRANSFER', 'saque:' . U2)) === recusa('Transferência não reconhecida pelo Entregas.'), 'mais de 2 min depois: recusado');

echo '== O Asaas chamou antes de gravarmos o id: sem externalReference' . PHP_EOL;
centralComSaque();
$s = Saques::pedir('empresa-1', 'driver-1', U2, 30)['saque'];
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_9', 30)) === aprovado(), 'um só candidato com o mesmo valor e a chave dele: aprovado');
confere(Saques::recarregar($s)->transfer_id === null, 'e o transfer_id continua vazio');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_10', 30, null)) === recusa('Transferência sem chave Pix para conferir.'), 'sem chave no pedido: recusado (nada garante o destino)');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_10', 30, '21988887777')) === recusa('Chave Pix diferente da cadastrada.'), 'chave de outra pessoa: recusado');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_10', 31)) === recusa('Transferência não reconhecida pelo Entregas.'), 'valor de nenhum candidato: recusado');
Relogio::$agora = '2026-10-27 10:02:01';
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_9', 30)) === recusa('Transferência não reconhecida pelo Entregas.'), 'mais de 2 min depois: recusado');

echo '== PoC 1 do revisor: transferência do atacante pelo valor' . PHP_EOL;
centralComSaque();
$s = Saques::pedir('empresa-1', 'driver-1', U1, 50)['saque'];
$atacante = ['type' => 'TRANSFER', 'transfer' => ['id' => 'tra_ATACANTE', 'value' => 50, 'operationType' => 'TED', 'bankAccount' => ['agency' => '1', 'account' => '2', 'pixAddressKey' => null]]];
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $atacante) === recusa('Transferência não é Pix.'), 'TED do atacante com o mesmo valor: RECUSADA');
$atacante['transfer']['operationType'] = null;
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $atacante) === recusa('Transferência sem chave Pix para conferir.'), 'sem operationType e sem chave: RECUSADA');
confere(Saques::recarregar($s)->transfer_id === null && Saques::recarregar($s)->aprovado_em === null, 'e não toma o saque (nem id, nem aprovado_em)');
$real = ['type' => 'TRANSFER', 'transfer' => ['id' => 'tra_REAL', 'value' => 50, 'operationType' => 'PIX', 'bankAccount' => ['pixAddressKey' => '+5511999994321']]];
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $real) === aprovado(), 'a nossa (chave do motoboy): aprovada');
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'tra_REAL', 'status' => 'PENDING', 'value' => 50, 'externalReference' => 'saque:' . U1]);
$s = Saques::enviar($s);
confere($s->transfer_id === 'tra_REAL' && $s->situacao === 'processando', 'o envio grava o id do NOSSO POST');
asaas(200, ['id' => 'tra_REAL', 'status' => 'DONE', 'value' => 50, 'externalReference' => 'saque:' . U1, 'transactionReceiptUrl' => 'https://www.asaas.com/comprovantes/real']);
AcompanhamentoDeSaques::conferir();
$s = Saques::recarregar($s);
confere($s->situacao === 'concluido' && $s->comprovante_url === 'https://www.asaas.com/comprovantes/real' && criticos() === [], 'a rotina conclui a nossa, sem log crítico');

echo '== Segunda revisão, N1: o externalReference só escolhe; a chave e o Pix aprovam; uma aprovação por saque' . PHP_EOL;
centralComSaque();
$s = Saques::pedir('empresa-1', 'driver-1', U1, 50)['saque'];
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(0, null);
$s = Saques::enviar($s);
confere($s->situacao === 'enviando' && $s->transfer_id === null, 'POST sem resposta: o saque fica "enviando" sem id');
$ted = ['type' => 'TRANSFER', 'transfer' => ['id' => 'tra_ATT', 'value' => 50, 'operationType' => 'TED', 'externalReference' => 'saque:' . U1, 'bankAccount' => ['agency' => '1', 'account' => '2', 'pixAddressKey' => null]]];
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $ted) === recusa('Transferência não é Pix.'), 'PoC A: TED do atacante com o externalReference copiado: RECUSADA');
$semConta = ['type' => 'TRANSFER', 'transfer' => ['id' => 'tra_ATT2', 'value' => 50, 'externalReference' => 'saque:' . U1]];
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $semConta) === recusa('Transferência sem chave Pix para conferir.'), 'PoC A: outra do atacante sem bankAccount, com o externalReference: RECUSADA');
$outraChave = ['type' => 'TRANSFER', 'transfer' => ['id' => 'tra_ATT3', 'value' => 50, 'operationType' => 'PIX', 'externalReference' => 'saque:' . U1, 'bankAccount' => ['pixAddressKey' => 'atacante@exemplo.com']]];
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $outraChave) === recusa('Chave Pix diferente da cadastrada.'), 'Pix com o externalReference para a chave do atacante: RECUSADA');
$mascarada = ['type' => 'TRANSFER', 'transfer' => ['id' => 'tra_M', 'value' => 50, 'operationType' => 'PIX', 'externalReference' => 'saque:' . U1, 'bankAccount' => ['pixAddressKey' => '(11) *****-4321']]];
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $mascarada) === recusa('Chave Pix mascarada; não dá para conferir.'), 'sem id e chave mascarada: RECUSADA (não dá para conferir)');
confere(saqueDoBanco(U1)->aprovado_em === null && saqueDoBanco(U1)->aprovado_transfer_id === null, 'nenhuma dessas aprova o saque');
$real = ['type' => 'TRANSFER', 'transfer' => ['id' => 'tra_REAL', 'value' => 50, 'operationType' => 'PIX', 'externalReference' => 'saque:' . U1, 'bankAccount' => ['pixAddressKey' => '+5511999994321']]];
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $real) === aprovado(), 'a nossa (Pix, externalReference e a chave do motoboy): APPROVED');
confere(saqueDoBanco(U1)->aprovado_em === '2026-10-27 10:00:00' && saqueDoBanco(U1)->aprovado_transfer_id === 'tra_REAL' && saqueDoBanco(U1)->transfer_id === null, 'grava aprovado_em e aprovado_transfer_id juntos (e não o transfer_id)');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $real) === aprovado(), 'o Asaas repete a pergunta da mesma transferência: aprova de novo');
$copia = $real;
$copia['transfer']['id'] = 'tra_COPIA';
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $copia) === recusa('Saque já aprovado para outra transferência.'), 'outra transferência idêntica (mesma chave, valor e externalReference): RECUSADA');
confere(saqueDoBanco(U1)->aprovado_transfer_id === 'tra_REAL' && criticos() === ['[entregas] saque: validação de outra transferência para saque já aprovado'], 'a aprovação fica com a primeira, e vai ao log como crítico');
$semRef = $copia;
unset($semRef['transfer']['externalReference']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $semRef) === recusa('Saque já aprovado para outra transferência.'), 'sem externalReference (único candidato pelo valor): também RECUSADA');

echo '== Segunda revisão, N1: caminho por id' . PHP_EOL;
centralComSaque();
saqueEnviado('driver-1', U1, 50, ['id' => 'tra_1', 'status' => 'PENDING']);
$porId = pedidoDoAsaas('tra_1', 50);
$porId['transfer']['operationType'] = 'TED';
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $porId) === recusa('Transferência não é Pix.'), 'operationType TED com o id do saque: RECUSADA');
$porId['transfer']['operationType'] = 'INTERNAL';
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $porId) === recusa('Transferência não é Pix.'), 'outro operationType: RECUSADA');
unset($porId['transfer']['operationType']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $porId) === aprovado() && saqueDoBanco(U1)->aprovado_transfer_id === 'tra_1', 'sem operationType: segue (id, valor e chave) e grava aprovado_transfer_id');
DB::table('entregas_saques')->where('uuid', U1)->update(['aprovado_transfer_id' => 'tra_OUTRA']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50)) === recusa('Saque já aprovado para outra transferência.'), 'por id, saque já aprovado para outra: RECUSADA');
confere(saqueDoBanco(U1)->aprovado_transfer_id === 'tra_OUTRA', 'e a aprovação não muda');

echo '== Envio: o id do POST é o que vale' . PHP_EOL;
centralComSaque();
$s = Saques::pedir('empresa-1', 'driver-1', U1, 50)['saque'];
DB::table('entregas_saques')->where('uuid', U1)->update(['transfer_id' => 'tra_OUTRO']);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'tra_POST', 'status' => 'PENDING']);
$s = Saques::enviar($s);
confere($s->transfer_id === 'tra_POST' && $s->situacao === 'processando', 'outro id gravado antes: fica o do POST');
confere(criticos() === ['[entregas] saque: id da transferência diferente do gravado'], 'e vai ao log como crítico');

echo '== PoC 2 do revisor: dois saques "enviando" do mesmo valor' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria', 100.0, '21988887777');
$sb = Saques::pedir('empresa-1', 'driver-2', U2, 50)['saque'];
$sa = Saques::pedir('empresa-1', 'driver-1', U1, 50)['saque'];
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_A', 50)) === recusa('Transferência não reconhecida pelo Entregas.'), 'sem externalReference e dois candidatos com o mesmo valor: recusado');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_A', 50, '+5511999994321', 'TRANSFER', 'saque:' . U1)) === aprovado(), 'com o externalReference: aprova o certo');
confere(Saques::recarregar($sb)->transfer_id === null && Saques::recarregar($sb)->aprovado_em === null && Saques::recarregar($sa)->transfer_id === null, 'nenhum ganha transfer_id na validação; o outro nem é aprovado');
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'tra_A', 'status' => 'PENDING']);
$x = excecao(fn () => Saques::enviar($sa));
confere($x === null && saqueDoBanco(U1)->transfer_id === 'tra_A' && saqueDoBanco(U1)->situacao === 'processando', 'enviar(A) grava tra_A sem erro' . ($x ? ': ' . get_class($x) : ''));
confere(saqueDoBanco(U2)->transfer_id === null && saqueDoBanco(U2)->situacao === 'enviando', 'B continua sem id');

echo '== Id do POST já usado por outro saque: sem 500, fica para a rotina' . PHP_EOL;
DB::table('entregas_saques')->where('uuid', U1)->update(['situacao' => 'concluido']);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'tra_A', 'status' => 'PENDING']);
$x = excecao(fn () => Saques::enviar($sb));
confere($x === null && saqueDoBanco(U2)->situacao === 'enviando' && saqueDoBanco(U2)->transfer_id === null, 'chave única: o saque fica "enviando"' . ($x ? ': ' . get_class($x) : ''));
confere(in_array('[entregas] saque: id da transferência já usado por outro saque', criticos(), true), 'e vai ao log como crítico');

echo '== Conclusão: a transferência precisa conferir com o saque' . PHP_EOL;
centralComSaque();
saqueEnviado('driver-1', U1, 50, ['id' => 'tra_1', 'status' => 'PENDING']);
asaas(200, ['id' => 'tra_1', 'status' => 'DONE', 'value' => 49]);
AcompanhamentoDeSaques::conferir();
confere(saqueDoBanco(U1)->situacao === 'processando' && criticos() === ['[entregas] saque: transferência não confere com o saque'], 'valor diferente: não conclui, log crítico');
asaas(200, ['id' => 'tra_1', 'status' => 'FAILED', 'value' => 50, 'externalReference' => 'saque:outro']);
AcompanhamentoDeSaques::conferir();
confere(saqueDoBanco(U1)->situacao === 'processando' && count(criticos()) === 2, 'externalReference diferente: não falha (nem devolve), log crítico');
asaas(200, ['id' => 'tra_1', 'status' => 'DONE', 'value' => 50, 'externalReference' => null]);
AcompanhamentoDeSaques::conferir();
confere(saqueDoBanco(U1)->situacao === 'concluido', 'externalReference null e valor igual: conclui');
confere(end(Log::$registros)[1] === '[entregas] saque: concluído sem a aprovação da validação' && end(Log::$registros)[0] === 'critical', 'concluído sem aprovado_em: log crítico (a validação foi desligada no Asaas?)');
centralComSaque();
saqueEnviado('driver-1', U1, 50, ['id' => 'tra_1', 'status' => 'PENDING']);
ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50));
asaas(200, ['id' => 'tra_1', 'status' => 'DONE', 'value' => 50, 'externalReference' => 'saque:' . U1]);
AcompanhamentoDeSaques::conferir();
confere(saqueDoBanco(U1)->situacao === 'concluido' && criticos() === [], 'aprovado pela validação: conclui sem log crítico');
centralComSaque();
saqueEnviado('driver-1', U1, 50, ['id' => 'tra_1', 'status' => 'PENDING']);
DB::table('entregas_saques')->where('uuid', U1)->update(['aprovado_em' => '2026-10-27 10:00:00', 'aprovado_transfer_id' => 'tra_OUTRA']);
asaas(200, ['id' => 'tra_1', 'status' => 'DONE', 'value' => 50, 'externalReference' => 'saque:' . U1]);
AcompanhamentoDeSaques::conferir();
confere(saqueDoBanco(U1)->situacao === 'concluido' && criticos() === ['[entregas] saque: concluído com a aprovação de outra transferência'], 'concluiu uma transferência e a validação aprovou outra: log crítico');

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
