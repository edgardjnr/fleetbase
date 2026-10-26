<?php

// Saque Pix: os pushes ao motoboy (saque concluído, não concluído e chave Pix alterada) e o canal/texto no AvisosDoMotoboy.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-avisos.php

require __DIR__ . '/stubs-saque.php';

use App\Notifications\Entregas\AvisosDoMotoboy;
use App\Notifications\Entregas\PixDeSaqueAlterado;
use App\Notifications\Entregas\SaqueConcluido;
use App\Notifications\Entregas\SaqueNaoConcluido;

$ok = new SaqueConcluido('aaaaaaaa-0000-4000-8000-000000000001', 150.0);
confere($ok->title === 'Saque concluído' && $ok->message === 'Seu saque de R$ 150,00 caiu no Pix.', 'concluído: ' . $ok->message);
confere($ok->data === ['type' => 'entregas_saque', 'saque' => 'aaaaaaaa-0000-4000-8000-000000000001'], 'concluído: dados com o uuid do saque');

$nao = new SaqueNaoConcluido('s-2', 80.5, 'Saldo insuficiente na conta da central.');
confere($nao->title === 'Saque não concluído' && $nao->message === 'O saque de R$ 80,50 não foi concluído e o valor voltou para o seu saldo. Motivo: Saldo insuficiente na conta da central.', 'não concluído: ' . $nao->message);
confere($nao->data === ['type' => 'entregas_saque', 'saque' => 's-2'], 'não concluído: dados');
$longo = new SaqueNaoConcluido('s-3', 10, str_repeat('x', 300));
confere(mb_strlen($longo->message) < 260, 'motivo longo é cortado (120)');

$pix = new PixDeSaqueAlterado('28/10 às 10:00');
confere($pix->title === 'Chave Pix alterada' && $pix->message === 'A central alterou a chave Pix dos seus saques. Novos saques a partir de 28/10 às 10:00. Se você não pediu a troca, fale com a central.', 'chave alterada: ' . $pix->message);
confere($pix->data === ['type' => 'entregas_pix_alterado'], 'chave alterada: dados');

confere((AvisosDoMotoboy::CANAIS['entregas_saque'] ?? null) === 'avisos' && (AvisosDoMotoboy::CANAIS['entregas_pix_alterado'] ?? null) === 'avisos', 'canal "avisos" do app para os dois tipos');
confere(AvisosDoMotoboy::texto($ok) === [$ok->title, $ok->message] && AvisosDoMotoboy::texto($nao) === [$nao->title, $nao->message] && AvisosDoMotoboy::texto($pix) === [$pix->title, $pix->message], 'AvisosDoMotoboy conhece as três (sem o aviso "sem tradução")');

resumo();
