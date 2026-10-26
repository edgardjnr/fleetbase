<?php

// Saque Pix: a rotina entregas:saques-conferir (AcompanhamentoDeSaques): consulta as transferências em andamento, adota
// pelo externalReference a que ficou sem id (ou cujo id dá 404 há mais de 30 min) e falha a que nunca chegou ao Asaas.
// Erro de rede nunca dá por falho; um saque com problema não para os outros.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-acompanhamento.php

require __DIR__ . '/stubs-saque.php';

use App\Console\Commands\Entregas\SaquesConferir;
use App\Notifications\Entregas\SaqueConcluido;
use App\Notifications\Entregas\SaqueNaoConcluido;
use App\Support\Entregas\Saques\AcompanhamentoDeSaques;
use App\Support\Entregas\Saques\Saques;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Saques\ValidacaoDeSaque;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Teste\Banco;
use Teste\Config;
use Teste\Http as HttpFalso;
use Teste\Relogio;

const UA = 'aaaaaaaa-0000-4000-8000-00000000000a';
const UB = 'aaaaaaaa-0000-4000-8000-00000000000b';
const UC = 'aaaaaaaa-0000-4000-8000-00000000000c';

/** As mensagens dos logs de um nível desde o último reinício. */
function logsDe(string $nivel): array
{
    return array_values(array_map(fn ($r) => $r[1], array_filter(Log::$registros, fn ($r) => $r[0] === $nivel)));
}

echo '== Em processamento' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria Souza');
motoboyComSaldo('driver-3', 'Pedro Lima');
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_a', 'status' => 'PENDING']);
saqueEnviado('driver-2', UB, 20, ['id' => 'tra_b', 'status' => 'PENDING']);
saqueEnviado('driver-3', UC, 30, ['id' => 'tra_c', 'status' => 'BANK_PROCESSING']);
HttpFalso::$chamadas = [];
Driver::$avisos      = [];
asaas(200, ['id' => 'tra_a', 'status' => 'DONE', 'transactionReceiptUrl' => 'https://asaas.teste/c/a']);
asaas(200, ['id' => 'tra_b', 'status' => 'FAILED', 'failReason' => 'Chave Pix não encontrada']);
HttpFalso::$respostas[] = 'conexao';
confere(AcompanhamentoDeSaques::conferir() === 2, 'dois saques terminaram');
confere(array_column(HttpFalso::$chamadas, 'url') === ['https://asaas.teste/v3/transfers/tra_a', 'https://asaas.teste/v3/transfers/tra_b', 'https://asaas.teste/v3/transfers/tra_c'], 'consulta cada um pelo id');
confere(saqueDoBanco(UA)->situacao === 'concluido' && saqueDoBanco(UA)->comprovante_url === 'https://asaas.teste/c/a', 'DONE: concluído com o comprovante');
confere(saqueDoBanco(UB)->situacao === 'falhou' && saqueDoBanco(UB)->motivo_falha === 'Não foi possível concluir o saque agora.' && saqueDoBanco(UB)->motivo_asaas === 'Chave Pix não encontrada' && saqueDoBanco(UB)->asaas_status === 'FAILED', 'FAILED com failReason: o texto do Asaas em motivo_asaas, a frase nossa em motivo_falha');
confere(saqueDoBanco(UC)->situacao === 'processando', 'sem resposta: continua processando (nunca falha às cegas)');
confere(Driver::$avisos[0][1] instanceof SaqueConcluido && Driver::$avisos[1][1] instanceof SaqueNaoConcluido && count(Driver::$avisos) === 2, 'um push para cada um que terminou');
asaas(200, ['id' => 'tra_c', 'status' => 'CANCELLED']);
confere(AcompanhamentoDeSaques::conferir() === 1 && saqueDoBanco(UC)->motivo_falha === 'Transferência cancelada no Asaas.' && saqueDoBanco(UC)->motivo_asaas === null, 'CANCELLED sem motivo: "cancelada no Asaas" (texto nosso)');

echo '== Sem id: espera 5 min, depois procura pelo externalReference' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria Souza');
$a = Saques::pedir('empresa-1', 'driver-1', UA, 10)['saque'];
$b = Saques::pedir('empresa-1', 'driver-2', UB, 20)['saque'];
confere(AcompanhamentoDeSaques::conferir() === 0 && HttpFalso::$chamadas === [], 'recém-criados sem id: não consulta ainda');
Relogio::$agora = '2026-10-27 10:06:00';
asaas(200, ['data' => [['id' => 'tra_z', 'externalReference' => 'saque:outro', 'status' => 'DONE'], ['id' => 'tra_a', 'externalReference' => 'saque:' . UA, 'status' => 'BANK_PROCESSING', 'value' => 10]], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 1, 'um mudou (o que nunca chegou ao Asaas)');
confere(count(HttpFalso::$chamadas) === 1 && HttpFalso::$chamadas[0]['url'] === 'https://asaas.teste/v3/transfers?offset=0&limit=100', 'lista as transferências uma vez só para os dois');
confere(saqueDoBanco(UA)->situacao === 'processando' && saqueDoBanco(UA)->transfer_id === 'tra_a' && saqueDoBanco(UA)->asaas_status === 'BANK_PROCESSING', 'achado pelo externalReference: adotado');
confere(saqueDoBanco(UB)->situacao === 'falhou' && saqueDoBanco(UB)->motivo_falha === 'Saque interrompido antes de chegar ao Asaas.', 'não achado: falha e devolve');

echo '== Terminou no meio da busca: não é adotado' . PHP_EOL;
centralComSaque();
Saques::pedir('empresa-1', 'driver-1', UC, 10);
Relogio::$agora = '2026-10-27 10:06:00';
HttpFalso::responderCom(function () {
    // outro processo dá o saque por falho (e devolve o valor) enquanto a rotina lista as transferências
    Saques::falhar(saqueDoBanco(UC), 'Falhou em outro processo.');

    return [200, ['data' => [['id' => 'tra_c', 'externalReference' => 'saque:' . UC, 'status' => 'DONE', 'value' => 10]], 'hasMore' => false]];
});
AcompanhamentoDeSaques::conferir();
confere(saqueDoBanco(UC)->situacao === 'falhou' && saqueDoBanco(UC)->transfer_id === null && saqueDoBanco(UC)->motivo_falha === 'Falhou em outro processo.', 'o falho (já devolvido) não volta a processando nem conclui: ' . json_encode(saqueDoBanco(UC)));
confere(in_array('[entregas] saque: transferência no Asaas para saque que não está em andamento', logsDe('critical'), true), 'e vai ao log como crítico (o dinheiro saiu e o valor foi devolvido)');

echo '== Lista fora do ar: espera' . PHP_EOL;
centralComSaque();
Saques::pedir('empresa-1', 'driver-1', UC, 10);
Relogio::$agora = '2026-10-27 10:06:00';
HttpFalso::$respostas[] = 'conexao';
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UC)->situacao === 'enviando', 'sem a lista: não falha');

echo '== Sem o secret' . PHP_EOL;
unlink(CHAVE_DO_COFRE);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UC)->situacao === 'enviando' && count(HttpFalso::$chamadas) === 1, 'sem chave: nada muda, nada é chamado');

echo '== Um saque com erro não trava os outros da central' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria Souza');
Saques::pedir('empresa-1', 'driver-1', UA, 10);
saqueEnviado('driver-2', UB, 20, ['id' => 'tra_b', 'status' => 'PENDING']);
Relogio::$agora = '2026-10-27 10:06:00';
asaas(200, ['data' => [], 'hasMore' => false]);
asaas(200, ['id' => 'tra_b', 'status' => 'DONE', 'value' => 20]);
Banco::$falhar['entregas_lancamentos'] = 'banco fora do ar';
$n = AcompanhamentoDeSaques::conferir();
unset(Banco::$falhar['entregas_lancamentos']);
confere(saqueDoBanco(UA)->situacao === 'enviando', 'o primeiro (falhar deu erro no banco): continua enviando');
confere($n === 1 && saqueDoBanco(UB)->situacao === 'concluido', 'o seguinte é conferido e conclui');
$aviso = array_values(array_filter(Log::$registros, fn ($r) => $r[1] === '[entregas] saque: acompanhamento do saque falhou'));
confere(count($aviso) === 1 && $aviso[0][0] === 'warning' && $aviso[0][2] === ['saque' => UA, 'erro' => 'Teste\ErroDeBanco'], 'warning com o saque e a classe do erro: ' . json_encode($aviso[0][2] ?? null));

echo '== PoC 3 do revisor: dois "enviando" de 50 e um processando' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria', 100.0, '21988887777');
motoboyComSaldo('driver-3', 'Ze', 100.0, '31977776666');
Saques::pedir('empresa-1', 'driver-2', UB, 50);
Saques::pedir('empresa-1', 'driver-1', UA, 50);
Saques::pedir('empresa-1', 'driver-3', UC, 20);
DB::table('entregas_saques')->where('uuid', UC)->update(['transfer_id' => 'tra_C', 'situacao' => 'processando']);
ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, ['type' => 'TRANSFER', 'transfer' => ['id' => 'tra_A', 'value' => 50, 'bankAccount' => ['pixAddressKey' => '+5511999994321']]]);
Relogio::$agora = '2026-10-27 10:06:00';
asaas(200, ['data' => [['id' => 'tra_A', 'externalReference' => 'saque:' . UA, 'status' => 'PENDING', 'value' => 50]], 'hasMore' => false]);
asaas(200, ['id' => 'tra_C', 'status' => 'DONE', 'value' => 20]);
$n = AcompanhamentoDeSaques::conferir();
confere(saqueDoBanco(UB)->situacao === 'falhou' && saqueDoBanco(UB)->transfer_id === null, 'B (não está no Asaas): falha e devolve; nunca recebe o id de A');
confere(saqueDoBanco(UA)->situacao === 'processando' && saqueDoBanco(UA)->transfer_id === 'tra_A', 'A: adotado pelo externalReference');
confere(saqueDoBanco(UC)->situacao === 'concluido' && $n === 2, 'C: conferido e concluído (a central não travou)');
confere(logsDe('critical') === ['[entregas] saque: concluído sem a aprovação da validação'], 'só o aviso de C sem aprovação: ' . json_encode(logsDe('critical'), JSON_UNESCAPED_UNICODE));

echo '== Id que dá 404 (saque órfão)' . PHP_EOL;
centralComSaque();
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_sumiu', 'status' => 'PENDING']);
HttpFalso::$chamadas = [];
asaas(404, ['errors' => [['code' => 'not_found', 'description' => 'Transferência não encontrada.']]]);
Relogio::$agora = '2026-10-27 10:29:59';
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->situacao === 'processando' && count(HttpFalso::$chamadas) === 1, 'menos de 30 min: 404 só espera (nem lista)');
Relogio::$agora = '2026-10-27 10:30:00';
asaas(500, null);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->situacao === 'processando' && count(HttpFalso::$chamadas) === 2, 'outro erro (500), mesmo depois de 30 min: espera');
asaas(404, null);
HttpFalso::$respostas[] = 'conexao';
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->situacao === 'processando', '404 e a lista fora do ar: espera');
asaas(404, null);
asaas(200, ['data' => [['id' => 'tra_x', 'externalReference' => 'saque:outro', 'status' => 'DONE']], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 1 && saqueDoBanco(UA)->situacao === 'falhou' && saqueDoBanco(UA)->motivo_falha === 'Transferência não encontrada no Asaas.', '404 há 30 min e não está nas recentes: falha e devolve');
confere(ExtratoDaConta::saldo('empresa-1', 'motoboy', 'driver-1') === 100.0, 'o valor voltou ao saldo');

centralComSaque();
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_sumiu', 'status' => 'PENDING']);
Relogio::$agora = '2026-10-27 10:31:00';
asaas(404, null);
asaas(200, ['data' => [['id' => 'tra_certa', 'externalReference' => 'saque:' . UA, 'status' => 'DONE', 'value' => 10, 'transactionReceiptUrl' => 'https://www.asaas.com/c/certa']], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 1 && saqueDoBanco(UA)->transfer_id === 'tra_certa' && saqueDoBanco(UA)->situacao === 'concluido', 'achada pelo externalReference: adota (troca o id) e aplica o status');
confere(in_array('[entregas] saque: transferência do saque trocada pela achada no Asaas', logsDe('critical'), true), 'a troca do id vai ao log como crítica');

centralComSaque();
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_sumiu', 'status' => 'PENDING']);
Relogio::$agora = '2026-10-27 10:31:00';
asaas(404, null);
asaas(200, ['data' => [['id' => 'tra_errada', 'externalReference' => 'saque:' . UA, 'status' => 'DONE', 'value' => 99]], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->transfer_id === 'tra_sumiu' && saqueDoBanco(UA)->situacao === 'processando', 'achada com outro valor: não adota, nada muda');
confere(in_array('[entregas] saque: transferência não confere com o saque', logsDe('critical'), true), 'e vai ao log como crítico');

echo '== Comando e agenda' . PHP_EOL;
centralComSaque();
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_a', 'status' => 'PENDING']);
Config::$valores['services.entregas.saque'] = null;
$antes = count(HttpFalso::$chamadas);
confere((new SaquesConferir())->handle() === 0 && count(HttpFalso::$chamadas) === $antes, 'desligada: o comando sai sem chamar o Asaas');
Config::$valores['services.entregas.saque'] = '1';
asaas(200, ['id' => 'tra_a', 'status' => 'DONE']);
confere((new SaquesConferir())->handle() === 0 && saqueDoBanco(UA)->situacao === 'concluido', 'ligada: confere');
$kernel = file_get_contents('/repo/api/app/Console/Kernel.php');
confere(str_contains($kernel, "\$schedule->command('entregas:saques-conferir')->everyMinute()->when(fn () => Saque::ligada())->withoutOverlapping(5)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);") && str_contains($kernel, 'use App\Support\Entregas\Saques\Saque;'), 'agenda: a cada minuto, só com a chave ligada');
confere(str_contains(file_get_contents('/repo/api/app/Console/Commands/Entregas/SaquesConferir.php'), "'entregas:saques-conferir'"), 'assinatura do comando');

resumo();
