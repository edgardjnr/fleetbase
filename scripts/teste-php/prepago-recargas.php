<?php

// Loja pré-paga: a recarga da carteira por Pix (Recargas) na conta Asaas da central. Pedir (limites, uma por vez, limite
// do dia, cliente da loja no Asaas), o QR code, a conferência (paga → crédito uma vez só; vencida → apaga e expira), a
// procura da recarga sem id, a conferência tardia, o cancelamento e as defesas: o crédito só entra com a cobrança do
// Asaas conferida (id, valor, externalReference, PIX, cliente) e nunca duas vezes.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/prepago-recargas.php

require __DIR__ . '/stubs-saque.php';

use App\Console\Commands\Entregas\RecargasConferir;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Financeiro\Pagamentos;
use App\Support\Entregas\PrePago\PrePago;
use App\Support\Entregas\PrePago\RecargaRecusada;
use App\Support\Entregas\PrePago\Recargas;
use App\Support\Entregas\Saques\ConexaoAsaas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Teste\Http as HttpFalso;
use Teste\Relogio;

const CNPJ_DA_LOJA = '11222333000181';
const QR_IMAGEM    = 'iVBORw0KGgoAAAANSUhEUgAAAAE=';
const QR_PAYLOAD   = '00020101021226820014br.gov.bcb.pix2560qrpix.asaas.com/qr/cobv/abc5204000053039865406100.005802BR';

/** A central conectada (stubs-saque), a recarga ligada e a loja vendor-a pré-paga com o CNPJ. */
function lojaPrePaga(): void
{
    centralComSaque();
    \Teste\Config::$valores['services.entregas.recarga'] = '1';
    PrePago::definir('empresa-1', 'vendor-a', 'pre', CNPJ_DA_LOJA, 'user-admin');
    HttpFalso::$chamadas = [];
    Log::$registros      = [];
}

/** A cobrança como o Asaas devolve. */
function cobranca(string $id, float $valor, string $referencia, string $status = 'PENDING', array $extra = []): array
{
    return array_merge(['id' => $id, 'customer' => 'cus_1', 'value' => $valor, 'billingType' => 'PIX', 'status' => $status, 'externalReference' => $referencia], $extra);
}

/** Pede a recarga com o Asaas respondendo tudo certo (wallet, cliente novo, cobrança e QR). */
function recargaAguardando(float $valor = 100.0, bool $clienteNovo = true, string $id = 'pay_1'): object
{
    asaas(200, ['data' => [['id' => 'wallet-1']]]);
    if ($clienteNovo) {
        asaas(200, ['id' => 'cus_1']);
    }
    HttpFalso::$respostas[] = function () use ($valor, $id) {
        $recarga = DB::table('entregas_recargas')->orderBy('id', 'desc')->first();

        return [200, cobranca($id, $valor, $recarga->external_reference)];
    };
    asaas(200, ['encodedImage' => QR_IMAGEM, 'payload' => QR_PAYLOAD, 'expirationDate' => '2026-10-27 23:59:59']);

    return Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria do Zé', $valor, 'usuario-a');
}

function credito(): float
{
    return round(-ExtratoDaConta::saldo('empresa-1', 'loja', 'vendor-a'), 2);
}

function recusa(callable $fazer): ?string
{
    try {
        $fazer();
    } catch (RecargaRecusada $e) {
        return $e->getMessage();
    }

    return null;
}

function logs(string $nivel): array
{
    return array_values(array_map(fn ($r) => $r[1], array_filter(Log::$registros, fn ($r) => $r[0] === $nivel)));
}

echo '== Pedir: cria cliente, cobrança e QR' . PHP_EOL;
lojaPrePaga();
$r = recargaAguardando(100);
confere($r->situacao === 'aguardando' && $r->payment_id === 'pay_1' && $r->cliente_id === 'cus_1' && $r->wallet_id === 'wallet-1', 'aguardando com o id da cobrança: ' . json_encode($r));
confere($r->pix_copia_cola === QR_PAYLOAD && $r->pix_imagem === QR_IMAGEM && $r->expira_em === '2026-10-27 10:30:00', 'QR gravado e vale 30 min');
$urls = array_map(fn ($c) => $c['metodo'] . ' ' . $c['url'], HttpFalso::$chamadas);
confere($urls === ['GET https://asaas.teste/v3/wallets', 'POST https://asaas.teste/v3/customers', 'POST https://asaas.teste/v3/payments', 'GET https://asaas.teste/v3/payments/pay_1/pixQrCode'], 'chamadas na ordem: ' . json_encode($urls));
confere(HttpFalso::$chamadas[1]['dados'] === ['name' => 'Pizzaria do Zé', 'cpfCnpj' => CNPJ_DA_LOJA, 'externalReference' => 'loja:vendor-a', 'notificationDisabled' => true], 'cliente com o CNPJ da loja, sem avisos do Asaas');
confere(HttpFalso::$chamadas[2]['dados'] === ['customer' => 'cus_1', 'billingType' => 'PIX', 'value' => 100.0, 'dueDate' => '2026-10-27', 'description' => 'Recarga de créditos Entregas', 'externalReference' => 'recarga:' . $r->uuid], 'cobrança PIX com o externalReference da recarga');
confere((HttpFalso::$chamadas[0]['headers']['access_token'] ?? null) === CHAVE_ASAAS && (HttpFalso::$chamadas[0]['options']['allow_redirects'] ?? null) === false, 'chave da central decifrada, sem seguir redirecionamento');
confere(DB::table('entregas_carteiras_lojas')->where('loja_uuid', 'vendor-a')->value('asaas_cliente_id') === 'cus_1', 'cliente gravado na carteira da loja');
confere(credito() === 0.0, 'nada creditado antes do pagamento');
$publico = Recargas::publico($r);
confere($publico['pix'] === ['copia_e_cola' => QR_PAYLOAD, 'imagem' => QR_IMAGEM] && $publico['situacao'] === 'aguardando' && $publico['valor'] === 100.0 && $publico['expira_em'] === '2026-10-27T10:30:00-03:00', 'formato do portal: ' . json_encode($publico));
confere(!array_key_exists('payment_id', $publico) && !array_key_exists('cliente_id', $publico) && !str_contains(json_encode($publico), 'pay_1'), 'o portal não recebe ids do Asaas');
$log = json_encode(Log::$registros, JSON_UNESCAPED_UNICODE);
confere(!str_contains($log, CHAVE_ASAAS) && !str_contains($log, CNPJ_DA_LOJA), 'o log não traz a chave nem o CNPJ');

echo '== Pedir: recusas' . PHP_EOL;
lojaPrePaga();
confere(recusa(fn () => Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 9.99, null)) === 'A recarga mínima é de R$ 10,00.', 'abaixo do mínimo');
confere(recusa(fn () => Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 5000.01, null)) === 'A recarga máxima é de R$ 5.000,00.', 'acima do máximo');
foreach ([null, '', 'abc', -10, 0, '10.999', '1e3', INF, NAN, [10]] as $valor) {
    confere(recusa(fn () => Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', $valor, null)) === 'Informe o valor da recarga.', 'valor inválido: ' . json_encode(is_float($valor) && !is_finite($valor) ? (string) $valor : $valor));
}
confere(HttpFalso::$chamadas === [] && DB::table('entregas_recargas')->count() === 0, 'recusa antes de chamar o Asaas e sem gravar');
confere(Recargas::valorEmCentavos('150,50') === 15050 && Recargas::valorEmCentavos(' 75 ') === 7500 && Recargas::valorEmCentavos(20.1) === 2010, 'valores aceitos');
\Teste\Config::$valores['services.entregas.recarga'] = '';
confere(recusa(fn () => Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 50, null)) === 'Recarga por Pix indisponível no momento.', 'ENTREGAS_RECARGA desligada');
\Teste\Config::$valores['services.entregas.recarga'] = '1';
confere(recusa(fn () => Recargas::pedir('empresa-1', 'vendor-b', 'Burger', 50, null)) === 'Esta loja não usa a carteira pré-paga.', 'loja pós-paga');
DB::table('entregas_asaas_conexoes')->delete();
confere(recusa(fn () => Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 50, null)) === 'A central ainda não ligou a recarga por Pix. Fale com a central.', 'central sem a conta Asaas');
lojaPrePaga();
unlink(CHAVE_DO_COFRE);
$erro = null;
try {
    Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 50, null);
} catch (\App\Support\Entregas\Saques\CofreIndisponivel $e) {
    $erro = 'cofre';
}
confere($erro === 'cofre', 'sem o secret do Cofre: CofreIndisponivel (503)');
lojaPrePaga();
file_put_contents(CHAVE_DO_COFRE, base64_encode(str_repeat('z', 32)));
confere(recusa(fn () => Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 50, null)) === 'Recarga por Pix indisponível no momento. Fale com a central.' && HttpFalso::$chamadas === [], 'secret trocado (a chave gravada não abre): recusa sem chamar o Asaas');

echo '== Uma por vez, limite do dia e cliente reaproveitado' . PHP_EOL;
lojaPrePaga();
recargaAguardando(50);
confere(recusa(fn () => Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 80, null)) === 'Já há um Pix de recarga aguardando pagamento.', 'segunda com uma aguardando: recusa');
confere(in_array(['entregas_carteiras_lojas', true], \Teste\Banco::$travas, true), 'a carteira da loja é travada dentro da transação');
confere(in_array(['entregas_asaas_conexoes', true], \Teste\Banco::$travas, true), 'a conexão Asaas é relida travada (o desconectar espera, e vice-versa)');
Relogio::$agora = '2026-10-27 10:00:20';
$antiga = DB::table('entregas_recargas')->where('situacao', 'aguardando')->first();
$chamadas = count(HttpFalso::$chamadas);
asaas(200, cobranca('pay_1', 50, $antiga->external_reference));
Recargas::conferir($antiga, true);
Recargas::conferir($antiga, true);
Recargas::conferir($antiga, true);
confere(count(HttpFalso::$chamadas) === $chamadas + 1, 'três perguntas do portal em paralelo com a mesma cópia: só uma consulta ao Asaas');
HttpFalso::$respostas = [];
DB::table('entregas_recargas')->update(['situacao' => 'expirada']);
HttpFalso::$chamadas = [];
$r2 = recargaAguardando(80, false, 'pay_2');
confere($r2->situacao === 'aguardando' && $r2->cliente_id === 'cus_1' && !in_array('POST https://asaas.teste/v3/customers', array_map(fn ($c) => $c['metodo'] . ' ' . $c['url'], HttpFalso::$chamadas), true), 'mesma wallet e mesmo documento: o cliente do Asaas é reaproveitado');
DB::table('entregas_recargas')->update(['situacao' => 'expirada']);
for ($i = 0; $i < 8; $i++) {
    DB::table('entregas_recargas')->insert(['uuid' => sprintf('bbbbbbbb-0000-4000-8000-%012d', $i), 'company_uuid' => 'empresa-1', 'loja_uuid' => 'vendor-a', 'valor' => 10, 'situacao' => 'falhou', 'external_reference' => 'recarga:x' . $i, 'wallet_id' => 'wallet-1', 'created_at' => '2026-10-27 08:00:00', 'updated_at' => '2026-10-27 08:00:00']);
}
confere(recusa(fn () => Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 50, null)) === 'Limite de recargas de hoje atingido.', '10 no dia: recusa');
DB::table('entregas_recargas')->where('situacao', 'falhou')->update(['created_at' => '2026-10-26 23:00:00']);
confere(recusa(fn () => recargaAguardando(50, false, 'pay_3')) === null, 'as de ontem não contam');
lojaPrePaga();
DB::table('entregas_carteiras_lojas')->update(['documento' => '52998224725']);
HttpFalso::$chamadas = [];
DB::table('entregas_carteiras_lojas')->update(['asaas_cliente_id' => 'cus_velho', 'asaas_cliente_wallet' => 'wallet-1', 'asaas_cliente_documento' => CNPJ_DA_LOJA]);
recargaAguardando(50);
confere(HttpFalso::$chamadas[1]['url'] === 'https://asaas.teste/v3/customers' && HttpFalso::$chamadas[1]['dados']['cpfCnpj'] === '52998224725', 'documento trocado: cliente novo no Asaas');

echo '== Pedir: Asaas com problema' . PHP_EOL;
lojaPrePaga();
asaas(200, ['data' => [['id' => 'wallet-OUTRA']]]);
$r = Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 50, null);
confere($r->situacao === 'falhou' && $r->motivo_falha === Recargas::MOTIVO_SEM_PIX && count(HttpFalso::$chamadas) === 1, 'chave de outra wallet: falha antes de criar a cobrança');
lojaPrePaga();
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(400, ['errors' => [['code' => 'invalid_cpfCnpj', 'description' => 'O CPF/CNPJ informado é inválido.']]]);
$r = Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 50, null);
confere($r->situacao === 'falhou' && count(HttpFalso::$chamadas) === 2, 'cliente recusado: falha sem criar a cobrança');
lojaPrePaga();
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'cus_1']);
asaas(400, ['errors' => [['code' => 'invalid_action', 'description' => 'Sua conta não possui chave Pix cadastrada.']]]);
$r = Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 50, null);
confere($r->situacao === 'falhou' && $r->motivo_falha === Recargas::MOTIVO_SEM_PIX, 'cobrança recusada (conta sem chave Pix): falha, e a loja vê a frase nossa');
confere(in_array('[entregas] recarga: o Asaas recusou a cobrança', logs('warning'), true) && str_contains(json_encode(Log::$registros, JSON_UNESCAPED_UNICODE), 'Sua conta não possui chave Pix'), 'o motivo do Asaas vai para o log');
lojaPrePaga();
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'cus_1']);
HttpFalso::$respostas[] = 'conexao';
$r = Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 50, null);
confere($r->situacao === 'criando' && $r->payment_id === null, 'POST da cobrança sem resposta: fica criando (a cobrança pode ter nascido)');
confere(recusa(fn () => Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 50, null)) === 'Já há um Pix de recarga aguardando pagamento.', 'e segura outra até a rotina resolver');
lojaPrePaga();
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'cus_1']);
HttpFalso::$respostas[] = function () {
    return [200, cobranca('pay_1', 100, DB::table('entregas_recargas')->value('external_reference'))];
};
asaas(500, null);
$r = Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 100, null);
confere($r->situacao === 'aguardando' && $r->pix_copia_cola === null && Recargas::publico($r)['pix'] === null, 'QR code sem resposta: aguardando sem o QR');
Relogio::$agora = '2026-10-27 10:00:10';
asaas(200, cobranca('pay_1', 100, $r->external_reference));
asaas(200, ['encodedImage' => QR_IMAGEM, 'payload' => QR_PAYLOAD]);
$r = Recargas::conferir($r, true);
confere($r->pix_copia_cola === QR_PAYLOAD, 'a próxima consulta busca o QR de novo');
lojaPrePaga();
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'cus_1']);
HttpFalso::$respostas[] = fn () => [200, cobranca('pay_1', 100, DB::table('entregas_recargas')->value('external_reference'))];
asaas(200, ['encodedImage' => '"><script>alert(1)</script>', 'payload' => QR_PAYLOAD]);
$r = Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 100, null);
confere($r->pix_imagem === null && $r->pix_copia_cola === QR_PAYLOAD, 'imagem que não é base64: descartada (só o copia e cola)');
lojaPrePaga();
DB::table('entregas_recargas')->insert(['uuid' => 'cccccccc-0000-4000-8000-000000000001', 'company_uuid' => 'empresa-1', 'loja_uuid' => 'vendor-b', 'valor' => 100, 'situacao' => 'paga', 'external_reference' => 'recarga:cccccccc', 'wallet_id' => 'wallet-1', 'payment_id' => 'pay_1', 'created_at' => '2026-10-26 10:00:00', 'updated_at' => '2026-10-26 10:00:00']);
$r = recargaAguardando(100);
confere($r->situacao === 'falhou' && $r->payment_id === null && in_array('[entregas] recarga: id da cobrança já usado por outra recarga; conferir à mão', logs('critical'), true), 'o Asaas devolve o id de outra recarga: falha com log crítico, sem 500');

echo '== Conferir: pago → crédito, uma vez só' . PHP_EOL;
lojaPrePaga();
$r = recargaAguardando(100);
Relogio::$agora = '2026-10-27 10:00:02';
$chamadas = count(HttpFalso::$chamadas);
$r = Recargas::conferir(Recargas::recarregar($r), true);
confere(count(HttpFalso::$chamadas) === $chamadas, 'o portal pergunta de novo em menos de 5 s: sem consultar o Asaas');
Relogio::$agora = '2026-10-27 10:03:00';
asaas(200, cobranca('pay_1', 100, $r->external_reference, 'RECEIVED'));
$r = Recargas::conferir($r, true);
confere($r->situacao === 'paga' && $r->paga_em === '2026-10-27 10:03:00' && $r->asaas_status === 'RECEIVED' && $r->pix_copia_cola === null, 'paga: ' . json_encode($r));
confere(credito() === 100.0, 'crédito de R$ 100,00 na carteira');
$pagamento = Pagamentos::porUuid('empresa-1', (string) $r->pagamento_uuid);
confere($pagamento && $pagamento->conta_tipo === 'loja' && $pagamento->forma === 'pix' && (float) $pagamento->valor === 100.0 && $pagamento->criado_por === null && $pagamento->observacao_interna === 'Recarga Pix pelo portal', 'um recebimento Pix do sistema na conta da loja');
confere(in_array(['entregas_recargas', true], \Teste\Banco::$travas, true), 'a recarga é travada para creditar');
$chamadas = count(HttpFalso::$chamadas);
Recargas::conferir($r);
Recargas::conferir(DB::table('entregas_recargas')->where('id', $r->id)->first());
confere(count(HttpFalso::$chamadas) === $chamadas && credito() === 100.0, 'paga não consulta de novo nem credita duas vezes');
$velha = (object) array_merge((array) $r, ['situacao' => 'aguardando', 'consultada_em' => null]);
asaas(200, cobranca('pay_1', 100, $r->external_reference, 'RECEIVED'));
Recargas::conferir($velha);
confere(credito() === 100.0 && DB::table('entregas_pagamentos')->count() === 1, 'conferência concorrente com a cópia antiga (portal e rotina juntos): a trava segura, crédito uma vez só');

echo '== Conferir: o que não credita' . PHP_EOL;
foreach ([
    'valor diferente'             => ['value' => 1000.0],
    'externalReference diferente' => ['externalReference' => 'recarga:outra'],
    'sem externalReference'       => ['externalReference' => null],
    'id diferente'                => ['id' => 'pay_9'],
    'boleto em vez de Pix'        => ['billingType' => 'BOLETO'],
    'outro cliente'               => ['customer' => 'cus_9'],
] as $caso => $extra) {
    lojaPrePaga();
    $r = recargaAguardando(100);
    Relogio::$agora = '2026-10-27 10:05:00';
    asaas(200, cobranca('pay_1', 100, $r->external_reference, 'RECEIVED', $extra));
    $r = Recargas::conferir($r);
    confere($r->situacao === 'aguardando' && credito() === 0.0 && in_array('[entregas] recarga: cobrança do Asaas não confere com a recarga', logs('critical'), true), "{$caso}: não credita e log crítico");
}
foreach (['RECEIVED_IN_CASH', 'PENDING', 'OVERDUE', 'REFUNDED', 'AWAITING_RISK_ANALYSIS'] as $status) {
    lojaPrePaga();
    $r = recargaAguardando(100);
    Relogio::$agora = '2026-10-27 10:05:00';
    asaas(200, cobranca('pay_1', 100, $r->external_reference, $status));
    $r = Recargas::conferir($r);
    confere($r->situacao === 'aguardando' && credito() === 0.0, "status {$status}: não credita");
}
lojaPrePaga();
$r = recargaAguardando(100);
Relogio::$agora = '2026-10-27 10:05:00';
HttpFalso::$respostas[] = 'conexao';
$r = Recargas::conferir($r);
confere($r->situacao === 'aguardando', 'Asaas fora do ar: espera');
lojaPrePaga();
$r = recargaAguardando(100);
DB::table('entregas_asaas_conexoes')->update(['wallet_id' => 'wallet-OUTRA']);
Relogio::$agora = '2026-10-27 10:05:00';
$chamadas = count(HttpFalso::$chamadas);
$r = Recargas::conferir($r);
confere($r->situacao === 'aguardando' && count(HttpFalso::$chamadas) === $chamadas && in_array('[entregas] recarga: a conta Asaas da recarga não está mais conectada; conferir à mão', logs('critical'), true), 'conexão de outra wallet: não consulta e pede conferência à mão');

echo '== Expirar' . PHP_EOL;
lojaPrePaga();
$r = recargaAguardando(100);
Relogio::$agora = '2026-10-27 10:31:00';
asaas(200, cobranca('pay_1', 100, $r->external_reference, 'PENDING'));
asaas(200, ['deleted' => true, 'id' => 'pay_1']);
$r = Recargas::conferir($r);
confere($r->situacao === 'expirada' && $r->motivo_falha === 'O Pix expirou sem pagamento.' && $r->pix_copia_cola === null && $r->pix_imagem === null, 'QR vencido: expirada e QR apagado');
confere(end(HttpFalso::$chamadas)['metodo'] === 'DELETE' && end(HttpFalso::$chamadas)['url'] === 'https://asaas.teste/v3/payments/pay_1', 'a cobrança é apagada no Asaas');
lojaPrePaga();
$r = recargaAguardando(100);
Relogio::$agora = '2026-10-27 10:31:00';
asaas(200, cobranca('pay_1', 100, $r->external_reference, 'PENDING'));
asaas(400, ['errors' => [['code' => 'invalid_action', 'description' => 'Não é possível remover uma cobrança recebida.']]]);
$r = Recargas::conferir($r);
confere($r->situacao === 'aguardando', 'o Asaas não apagou (paga no último segundo): continua aguardando');
Relogio::$agora = '2026-10-27 10:32:00';
asaas(200, cobranca('pay_1', 100, $r->external_reference, 'RECEIVED'));
$r = Recargas::conferir($r);
confere($r->situacao === 'paga' && credito() === 100.0, 'e a consulta seguinte credita');
lojaPrePaga();
$r = recargaAguardando(100);
Relogio::$agora = '2026-10-27 10:10:00';
asaas(200, cobranca('pay_1', 100, $r->external_reference, 'PENDING', ['deleted' => true]));
$r = Recargas::conferir($r);
confere($r->situacao === 'expirada', 'apagada no painel do Asaas: expirada');

echo '== Conferência tardia' . PHP_EOL;
lojaPrePaga();
$r = recargaAguardando(100);
Relogio::$agora = '2026-10-27 10:31:00';
asaas(200, cobranca('pay_1', 100, $r->external_reference));
asaas(200, ['deleted' => true]);
$r = Recargas::conferir($r);
Relogio::$agora = '2026-10-27 11:00:00';
(new RecargasConferir())->handle();
confere(DB::table('entregas_recargas')->where('id', $r->id)->value('conferida_depois_em') === null, 'antes de 1 h da expiração: ainda não');
Relogio::$agora = '2026-10-27 11:32:00';
asaas(200, cobranca('pay_1', 100, $r->external_reference, 'RECEIVED'));
(new RecargasConferir())->handle();
$r = Recargas::recarregar($r);
confere($r->situacao === 'paga' && credito() === 100.0 && in_array('[entregas] recarga: Pix pago depois de expirar; creditado', logs('warning'), true), 'paga depois de expirar: creditada com aviso');
lojaPrePaga();
$r = recargaAguardando(100);
Relogio::$agora = '2026-10-27 10:31:00';
asaas(200, cobranca('pay_1', 100, $r->external_reference));
asaas(200, ['deleted' => true]);
Recargas::conferir($r);
Relogio::$agora = '2026-10-27 11:32:00';
asaas(200, cobranca('pay_1', 100, $r->external_reference, 'PENDING', ['deleted' => true]));
(new RecargasConferir())->handle();
$chamadas = count(HttpFalso::$chamadas);
Relogio::$agora = '2026-10-27 11:40:00';
(new RecargasConferir())->handle();
confere(Recargas::recarregar($r)->situacao === 'expirada' && count(HttpFalso::$chamadas) === $chamadas, 'conferida uma vez só');

echo '== Procurar a recarga sem id (criando)' . PHP_EOL;
lojaPrePaga();
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'cus_1']);
HttpFalso::$respostas[] = 'conexao';
$r = Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 100, null);
Relogio::$agora = '2026-10-27 10:01:00';
$chamadas = count(HttpFalso::$chamadas);
(new RecargasConferir())->handle();
confere(count(HttpFalso::$chamadas) === $chamadas, 'antes de 2 min: não procura (o POST pode estar em andamento)');
Relogio::$agora = '2026-10-27 10:02:30';
asaas(200, ['data' => []]);
(new RecargasConferir())->handle();
$r = Recargas::recarregar($r);
confere($r->situacao === 'criando' && str_contains(end(HttpFalso::$chamadas)['url'], '/payments?externalReference=' . rawurlencode($r->external_reference)), 'nada no Asaas aos 2 min: continua procurando (a busca pode demorar)');
Relogio::$agora = '2026-10-27 10:05:00';
asaas(200, ['erro' => 'sem lista']);
(new RecargasConferir())->handle();
confere(Recargas::recarregar($r)->situacao === 'criando', 'resposta sem a lista: não falha');
Relogio::$agora = '2026-10-27 10:10:30';
asaas(200, ['data' => []]);
(new RecargasConferir())->handle();
confere(Recargas::recarregar($r)->situacao === 'falhou', 'nada no Asaas depois de 10 min: falha');
lojaPrePaga();
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'cus_1']);
HttpFalso::$respostas[] = 'conexao';
$r = Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 100, null);
Relogio::$agora = '2026-10-27 10:03:00';
asaas(200, ['data' => [cobranca('pay_7', 100, $r->external_reference)]]);
asaas(200, ['deleted' => true]);
(new RecargasConferir())->handle();
$r = Recargas::recarregar($r);
confere($r->payment_id === 'pay_7' && $r->situacao === 'expirada' && end(HttpFalso::$chamadas)['metodo'] === 'DELETE', 'achada e pendente: adotada e apagada (ninguém viu o QR)');
lojaPrePaga();
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'cus_1']);
HttpFalso::$respostas[] = 'conexao';
$r = Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 100, null);
Relogio::$agora = '2026-10-27 10:03:00';
asaas(200, ['data' => [cobranca('pay_7', 100, $r->external_reference, 'RECEIVED')]]);
(new RecargasConferir())->handle();
confere(Recargas::recarregar($r)->situacao === 'paga' && credito() === 100.0, 'achada e já paga: creditada');
lojaPrePaga();
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'cus_1']);
HttpFalso::$respostas[] = 'conexao';
$r = Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 100, null);
Relogio::$agora = '2026-10-27 10:03:00';
asaas(200, ['data' => [cobranca('pay_7', 100, $r->external_reference), cobranca('pay_8', 100, $r->external_reference)]]);
(new RecargasConferir())->handle();
confere(Recargas::recarregar($r)->situacao === 'criando' && in_array('[entregas] recarga: mais de uma cobrança no Asaas com o externalReference da recarga; conferir à mão', logs('critical'), true), 'duas com a mesma referência: não adota, log crítico');
lojaPrePaga();
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'cus_1']);
HttpFalso::$respostas[] = 'conexao';
$r = Recargas::pedir('empresa-1', 'vendor-a', 'Pizzaria', 100, null);
Relogio::$agora = '2026-10-27 10:03:00';
asaas(200, ['data' => [cobranca('pay_7', 100, 'recarga:outra-loja', 'RECEIVED')]]);
(new RecargasConferir())->handle();
confere(Recargas::recarregar($r)->situacao === 'criando' && Recargas::recarregar($r)->payment_id === null && credito() === 0.0, 'cobrança de outra referência na resposta (filtro ignorado): não adota nem credita');

echo '== Cancelar' . PHP_EOL;
lojaPrePaga();
$r = recargaAguardando(100);
asaas(200, ['deleted' => true, 'id' => 'pay_1']);
$r = Recargas::cancelar($r, 'usuario-a');
confere($r->situacao === 'expirada' && $r->motivo_falha === 'Pix cancelado.' && $r->cancelada_por === 'usuario-a', 'cancelada: expirada com quem cancelou');
lojaPrePaga();
$r = recargaAguardando(100);
asaas(400, ['errors' => [['description' => 'Não é possível remover uma cobrança recebida.']]]);
asaas(200, cobranca('pay_1', 100, $r->external_reference, 'RECEIVED'));
$r = Recargas::cancelar($r, 'usuario-a');
confere($r->situacao === 'paga' && credito() === 100.0, 'cancelar uma já paga: credita em vez de cancelar');
lojaPrePaga();
$r = recargaAguardando(100);
HttpFalso::$respostas[] = 'conexao';
HttpFalso::$respostas[] = 'conexao';
confere(recusa(fn () => Recargas::cancelar($r, 'usuario-a')) === 'Não foi possível cancelar agora. Tente de novo.', 'Asaas fora: recusa e continua aguardando');

echo '== Desconectar a conta Asaas com Pix aguardando' . PHP_EOL;
lojaPrePaga();
recargaAguardando(100);
$erro = null;
try {
    ConexaoAsaas::desconectar('empresa-1', 'user-admin');
} catch (\InvalidArgumentException $e) {
    $erro = $e->getMessage();
}
confere($erro === 'Há Pix de recarga de loja aguardando pagamento; espere ele expirar.' && DB::table('entregas_asaas_conexoes')->exists(), 'recusado: sem a chave ninguém confere o Pix');
DB::table('entregas_recargas')->update(['situacao' => 'paga']);
ConexaoAsaas::desconectar('empresa-1', 'user-admin');
confere(!DB::table('entregas_asaas_conexoes')->exists(), 'sem Pix aguardando: desconecta');

echo '== Rotina' . PHP_EOL;
lojaPrePaga();
$r = recargaAguardando(100);
\Teste\Config::$valores['services.entregas.recarga'] = '';
Relogio::$agora = '2026-10-27 10:05:00';
asaas(200, cobranca('pay_1', 100, $r->external_reference, 'RECEIVED'));
(new RecargasConferir())->handle();
confere(Recargas::recarregar($r)->situacao === 'paga' && in_array('[entregas] recarga: rotina creditou recargas', logs('info'), true), 'credita mesmo com ENTREGAS_RECARGA desligada');
lojaPrePaga();
$r1 = recargaAguardando(100);
DB::table('entregas_carteiras_lojas')->insert(['company_uuid' => 'empresa-1', 'loja_uuid' => 'vendor-b', 'modelo' => 'pre', 'documento' => '52998224725', 'asaas_cliente_id' => 'cus_2', 'asaas_cliente_wallet' => 'wallet-1', 'asaas_cliente_documento' => '52998224725', 'created_at' => '2026-10-27 09:00:00', 'updated_at' => '2026-10-27 09:00:00']);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
HttpFalso::$respostas[] = fn () => [200, cobranca('pay_2', 30, DB::table('entregas_recargas')->orderBy('id', 'desc')->value('external_reference'), 'PENDING', ['customer' => 'cus_2'])];
asaas(200, ['encodedImage' => QR_IMAGEM, 'payload' => QR_PAYLOAD]);
$r2 = Recargas::pedir('empresa-1', 'vendor-b', 'Burger', 30, null);
Relogio::$agora = '2026-10-27 10:05:00';
// a primeira (r1) vem paga, mas o banco falha ao gravar o recebimento; a segunda (r2) grava normal
HttpFalso::$respostas[] = function () use ($r1) {
    \Teste\Banco::$falhar['entregas_pagamentos'] = 'banco fora';

    return [200, cobranca('pay_1', 100, $r1->external_reference, 'RECEIVED')];
};
HttpFalso::$respostas[] = function () use ($r2) {
    unset(\Teste\Banco::$falhar['entregas_pagamentos']);

    return [200, cobranca('pay_2', 30, $r2->external_reference, 'RECEIVED', ['customer' => 'cus_2'])];
};
(new RecargasConferir())->handle();
confere(Recargas::recarregar($r1)->situacao === 'aguardando' && Recargas::recarregar($r2)->situacao === 'paga' && in_array('[entregas] recarga: conferência falhou', logs('warning'), true), 'falha numa recarga não para as outras');
$agenda = file_get_contents(dirname(__DIR__, 2) . '/api/app/Console/Kernel.php');
confere(str_contains($agenda, "\$schedule->command('entregas:recargas-conferir')->everyMinute()->withoutOverlapping(5)"), 'agendada a cada minuto, sem depender da chave');

resumo();
