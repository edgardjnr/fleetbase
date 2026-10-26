<?php

// Saque Pix: a rotina entregas:saques-conferir (AcompanhamentoDeSaques): consulta as transferências em andamento, adota
// pelo externalReference a que ficou sem id e falha a que nunca chegou ao Asaas. Erro de rede nunca dá por falho.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-acompanhamento.php

require __DIR__ . '/stubs-saque.php';

use App\Console\Commands\Entregas\SaquesConferir;
use App\Notifications\Entregas\SaqueConcluido;
use App\Notifications\Entregas\SaqueNaoConcluido;
use App\Support\Entregas\Saques\AcompanhamentoDeSaques;
use App\Support\Entregas\Saques\Saques;
use Fleetbase\FleetOps\Models\Driver;
use Teste\Config;
use Teste\Http as HttpFalso;
use Teste\Relogio;

const UA = 'aaaaaaaa-0000-4000-8000-00000000000a';
const UB = 'aaaaaaaa-0000-4000-8000-00000000000b';
const UC = 'aaaaaaaa-0000-4000-8000-00000000000c';

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
confere(saqueDoBanco(UB)->situacao === 'falhou' && saqueDoBanco(UB)->motivo_falha === 'Chave Pix não encontrada' && saqueDoBanco(UB)->asaas_status === 'FAILED', 'FAILED: falhou com o motivo do Asaas');
confere(saqueDoBanco(UC)->situacao === 'processando', 'sem resposta: continua processando (nunca falha às cegas)');
confere(Driver::$avisos[0][1] instanceof SaqueConcluido && Driver::$avisos[1][1] instanceof SaqueNaoConcluido && count(Driver::$avisos) === 2, 'um push para cada um que terminou');
asaas(200, ['id' => 'tra_c', 'status' => 'CANCELLED']);
confere(AcompanhamentoDeSaques::conferir() === 1 && saqueDoBanco(UC)->motivo_falha === 'Transferência cancelada no Asaas.', 'CANCELLED sem motivo: "cancelada no Asaas"');

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

echo '== Lista fora do ar: espera' . PHP_EOL;
centralComSaque();
Saques::pedir('empresa-1', 'driver-1', UC, 10);
Relogio::$agora = '2026-10-27 10:06:00';
HttpFalso::$respostas[] = 'conexao';
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UC)->situacao === 'enviando', 'sem a lista: não falha');

echo '== Sem o secret' . PHP_EOL;
unlink(CHAVE_DO_COFRE);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UC)->situacao === 'enviando' && count(HttpFalso::$chamadas) === 1, 'sem chave: nada muda, nada é chamado');

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
