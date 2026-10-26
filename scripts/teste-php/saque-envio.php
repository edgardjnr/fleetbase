<?php

// Saque Pix: o envio ao Asaas (Saques::enviar), a conclusão, a falha com reembolso (estorno do pagamento e da taxa) e os
// pushes. Nunca dá por falho o que pode ter saído (sem resposta no POST fica "enviando").
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-envio.php

require __DIR__ . '/stubs-saque.php';

use App\Notifications\Entregas\SaqueConcluido;
use App\Notifications\Entregas\SaqueNaoConcluido;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Financeiro\Pagamentos;
use App\Support\Entregas\Saques\Saque;
use App\Support\Entregas\Saques\Saques;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Support\Facades\DB;
use Teste\Http as HttpFalso;

const U1 = 'aaaaaaaa-0000-4000-8000-000000000001';

function saldo(): float
{
    return ExtratoDaConta::saldo('empresa-1', 'motoboy', 'driver-1');
}

function ultimoAviso()
{
    $ultimo = end(Driver::$avisos);

    return $ultimo ? $ultimo[1] : null;
}

function pedido(float $valor): object
{
    return Saques::pedir('empresa-1', 'driver-1', U1, $valor)['saque'];
}

echo '== Enviado e processando' . PHP_EOL;
centralComSaque();
$s = saqueEnviado('driver-1', U1, 50, ['id' => 'tra_1', 'status' => 'PENDING']);
confere($s->situacao === 'processando' && $s->transfer_id === 'tra_1' && $s->asaas_status === 'PENDING', 'processando com o id do Asaas');
$post = HttpFalso::$chamadas[1];
confere($post['url'] === 'https://asaas.teste/v3/transfers' && $post['dados'] === ['value' => 50.0, 'operationType' => 'PIX', 'pixAddressKey' => '11999994321', 'pixAddressKeyType' => 'PHONE', 'description' => 'Saque Entregas', 'externalReference' => 'saque:' . U1], 'POST com a chave do banco e o externalReference: ' . json_encode($post['dados']));
confere(($post['headers']['access_token'] ?? null) === CHAVE_ASAAS && HttpFalso::$chamadas[0]['url'] === 'https://asaas.teste/v3/wallets', 'confere a wallet antes, com a chave decifrada');
confere(Driver::$avisos === [], 'processando: sem push ainda');

echo '== DONE na hora' . PHP_EOL;
centralComSaque();
$s = saqueEnviado('driver-1', U1, 20, ['id' => 'tra_2', 'status' => 'DONE', 'transactionReceiptUrl' => 'https://www.asaas.com/comprovantes/2']);
confere($s->situacao === 'concluido' && $s->comprovante_url === 'https://www.asaas.com/comprovantes/2' && $s->concluido_em === '2026-10-27 10:00:00' && $s->asaas_status === 'DONE', 'concluído com o comprovante');
confere(ultimoAviso() instanceof SaqueConcluido && ultimoAviso()->message === 'Seu saque de R$ 20,00 caiu no Pix.', 'push de concluído');
confere(saldo() === 80.0, 'o saldo continua descontado');

echo '== Comprovante só de endereço do Asaas' . PHP_EOL;
foreach ([
    'https://www.asaas.com/comprovantes/2'      => 'https://www.asaas.com/comprovantes/2',
    'https://asaas.com/c/2'                     => 'https://asaas.com/c/2',
    'https://sandbox.asaas.com/c/2'             => 'https://sandbox.asaas.com/c/2',
    'HTTPS://WWW.ASAAS.COM/c/2'                 => 'HTTPS://WWW.ASAAS.COM/c/2',
    'http://www.asaas.com/c/2'                  => null,
    'https://asaas.com.golpe.com/c/2'           => null,
    'https://golpeasaas.com/c/2'                => null,
    'https://golpe.com/?u=https://asaas.com/'   => null,
    'https://www.asaas.com@golpe.com/c/2'       => null,
    'javascript:alert(1)//asaas.com/'           => null,
    'https://www.asaas.com'                     => null,
    ''                                          => null,
] as $url => $esperado) {
    confere(Saque::comprovante($url) === $esperado, 'comprovante "' . $url . '": ' . ($esperado === null ? 'descartado' : 'aceito'));
}
confere(Saque::comprovante(null) === null && Saque::comprovante(123) === null && Saque::comprovante('https://www.asaas.com/' . str_repeat('x', 500)) === null, 'null, número ou maior que 500: descartado');
centralComSaque();
$s = saqueEnviado('driver-1', U1, 20, ['id' => 'tra_2', 'status' => 'DONE', 'transactionReceiptUrl' => 'https://golpe.com/comprovante']);
confere($s->situacao === 'concluido' && $s->comprovante_url === null, 'DONE com comprovante de fora do Asaas: conclui (o dinheiro saiu) sem o link');

echo '== Recusado pelo Asaas' . PHP_EOL;
centralComSaque();
$s = pedido(50);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(400, ['errors' => [['description' => 'Saldo insuficiente.']]]);
$s = Saques::enviar($s);
confere($s->situacao === 'falhou' && $s->motivo_falha === 'Não foi possível concluir o saque agora.' && $s->motivo_asaas === 'Saldo insuficiente.', 'falhou: o motoboy vê a frase nossa, o texto do Asaas fica em motivo_asaas');
$pagamento = Pagamentos::porUuid('empresa-1', (string) $s->pagamento_uuid);
confere($pagamento->estornado_em !== null && saldo() === 100.0, 'pagamento estornado: o dinheiro volta ao saldo');
$estorno = DB::table('entregas_lancamentos')->where('tipo', 'estorno')->first();
confere($estorno && $estorno->motivo === 'Pagamento estornado' && $estorno->criado_por === null, 'estorno com o motivo que o app reconhece, pelo sistema');
confere(ultimoAviso() instanceof SaqueNaoConcluido && ultimoAviso()->message === 'O saque de R$ 50,00 não foi concluído e o valor voltou para o seu saldo. Motivo: Não foi possível concluir o saque agora.', 'push sem o texto cru do Asaas: ' . ultimoAviso()->message);
confere(Saques::publico($s)['motivo'] === 'Não foi possível concluir o saque agora.' && !array_key_exists('motivo_asaas', Saques::publico($s)), 'app: a frase nossa, sem motivo_asaas');
$noConsole = Saques::publico($s, true);
confere($noConsole['motivo'] === 'Saldo insuficiente.' && $noConsole['motivo_asaas'] === 'Saldo insuficiente.', 'console: o texto do Asaas');

echo '== Sem resposta no POST: fica enviando' . PHP_EOL;
centralComSaque();
$s = pedido(50);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
HttpFalso::$respostas[] = 'conexao';
$s = Saques::enviar($s);
confere($s->situacao === 'enviando' && $s->transfer_id === null && Pagamentos::porUuid('empresa-1', (string) $s->pagamento_uuid)->estornado_em === null && saldo() === 50.0, 'pode ter saído: nada é estornado (a rotina confere)');
centralComSaque();
$s = pedido(50);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['status' => 'PENDING']);
confere(Saques::enviar($s)->situacao === 'enviando', '200 sem id: também fica enviando');

echo '== 4xx ambíguo: fica enviando (só a recusa clara devolve)' . PHP_EOL;
foreach ([
    '429 com errors'        => [429, ['errors' => [['description' => 'Muitas requisições.']]]],
    '409 com errors'        => [409, ['errors' => [['description' => 'Conflito.']]]],
    '400 sem corpo'         => [400, null],
    '422 com errors vazio'  => [422, ['errors' => []]],
    '404 sem errors'        => [404, ['message' => 'Not Found']],
] as $caso => [$status, $corpo]) {
    centralComSaque();
    $s = pedido(50);
    asaas(200, ['data' => [['id' => 'wallet-1']]]);
    asaas($status, $corpo);
    $s = Saques::enviar($s);
    confere($s->situacao === 'enviando' && saldo() === 50.0, $caso . ': fica enviando, nada devolvido (a rotina confere)');
}
foreach ([400, 401, 403, 404, 422] as $status) {
    centralComSaque();
    $s = pedido(50);
    asaas(200, ['data' => [['id' => 'wallet-1']]]);
    asaas($status, ['errors' => [['code' => 'invalid', 'description' => 'Recusado.']]]);
    confere(Saques::enviar($s)->situacao === 'falhou' && saldo() === 100.0, $status . ' com errors: falha e devolve');
}

echo '== Antes do POST: falha e reembolsa' . PHP_EOL;
centralComSaque();
$s = pedido(50);
asaas(401, null);
$s = Saques::enviar($s);
confere($s->situacao === 'falhou' && $s->motivo_falha === 'A chave do Asaas da central foi recusada. Avise a central.' && count(HttpFalso::$chamadas) === 1 && saldo() === 100.0, 'chave recusada no /wallets: falha sem chamar o /transfers');
confere($s->motivo_asaas === null && Saques::publico($s, true)['motivo'] === 'A chave do Asaas da central foi recusada. Avise a central.' && Saques::publico($s, true)['motivo_asaas'] === null, 'motivo nosso: fica em motivo_falha, motivo_asaas vazio (console mostra o nosso)');
centralComSaque();
$s = pedido(50);
HttpFalso::$respostas[] = 'conexao';
confere(Saques::enviar($s)->motivo_falha === 'O Asaas não respondeu. Tente de novo.', 'Asaas fora no /wallets: falha (nada saiu)');
centralComSaque();
$s = pedido(50);
asaas(200, ['data' => [['id' => 'wallet-OUTRA']]]);
confere(Saques::enviar($s)->motivo_falha === 'A chave do Asaas não é mais da conta conectada. Avise a central.' && count(HttpFalso::$chamadas) === 1, 'wallet diferente: falha sem transferir');
centralComSaque();
$s = pedido(50);
unlink(CHAVE_DO_COFRE);
confere(Saques::enviar($s)->motivo_falha === 'Saque Pix indisponível no momento. Avise a central.' && HttpFalso::$chamadas === [], 'sem o secret: falha sem chamar o Asaas');

echo '== Taxa devolvida e falha uma vez só' . PHP_EOL;
centralComSaque(['taxa' => 2.0]);
$s = pedido(50);
confere(saldo() === 48.0, 'saque 50 + taxa 2');
confere(Saques::falhar($s, 'Teste') === true, 'falhar');
confere(saldo() === 100.0 && Lancamentos::jaEstornados([(int) $s->taxa_lancamento_id]) === [(int) $s->taxa_lancamento_id], 'a taxa também volta');
$taxaDevolvida = DB::table('entregas_lancamentos')->where('estorno_de', (int) $s->taxa_lancamento_id)->first();
confere($taxaDevolvida->motivo === 'Taxa do saque devolvida', 'motivo da taxa devolvida');
$avisos = count(Driver::$avisos);
confere(Saques::falhar(Saques::recarregar($s), 'De novo') === false && saldo() === 100.0 && count(Driver::$avisos) === $avisos, 'falhar de novo: nada muda, sem outro push');
confere(Saques::concluir(Saques::recarregar($s), null) === false && Saques::recarregar($s)->situacao === 'falhou', 'concluir depois de falhar: nada muda');

resumo();
