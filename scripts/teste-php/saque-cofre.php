<?php

// Saque Pix: o Cofre (AES-256-GCM com a chave do Docker secret, amarrado à central pelo dado autenticado) e as funções
// puras do Saque.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-cofre.php

require __DIR__ . '/stubs-saque.php';

use App\Support\Entregas\Saques\Cofre;
use App\Support\Entregas\Saques\CofreIndisponivel;
use App\Support\Entregas\Saques\Saque;
use Teste\Config;

echo '== Cofre' . PHP_EOL;
reiniciarSaque();
confere(Cofre::disponivel(), 'com o secret: disponível');
$cifrado = Cofre::cifrar(CHAVE_ASAAS, 'empresa-1');
confere(str_starts_with($cifrado, 'v1:') && !str_contains($cifrado, CHAVE_ASAAS) && !str_contains(base64_decode(substr($cifrado, 3)), CHAVE_ASAAS), 'cifrado: v1:..., sem a chave em claro');
confere(Cofre::decifrar($cifrado, 'empresa-1') === CHAVE_ASAAS, 'decifra de volta (mesma central)');
confere(Cofre::decifrar($cifrado, 'empresa-2') === null, 'o cifrado de uma central NÃO decifra como de outra (linha copiada no banco)');
confere(Cofre::decifrar($cifrado, '') === null, 'contexto vazio: null');
confere(excecao(fn () => Cofre::cifrar('x', '')) instanceof \InvalidArgumentException, 'cifrar sem contexto: recusa');
confere(Cofre::cifrar('x', 'empresa-1') !== Cofre::cifrar('x', 'empresa-1'), 'cada cifra sai diferente (nonce aleatório)');
$bytes = base64_decode(substr($cifrado, 3));
$adulterado = 'v1:' . base64_encode(substr($bytes, 0, -1) . chr(ord(substr($bytes, -1)) ^ 1));
confere(Cofre::decifrar($adulterado, 'empresa-1') === null, 'texto adulterado: null (a tag do GCM não confere)');
confere(Cofre::decifrar('lixo', 'empresa-1') === null && Cofre::decifrar('v1:@@@', 'empresa-1') === null && Cofre::decifrar(null, 'empresa-1') === null && Cofre::decifrar('', 'empresa-1') === null, 'lixo, vazio ou null: null');

file_put_contents(CHAVE_DO_COFRE, base64_encode(str_repeat('z', 32)));
confere(Cofre::decifrar($cifrado, 'empresa-1') === null, 'outra chave no secret: não decifra');
file_put_contents(CHAVE_DO_COFRE, 'curta');
confere(!Cofre::disponivel(), 'secret que não é base64 de 32 bytes: indisponível');
unlink(CHAVE_DO_COFRE);
confere(!Cofre::disponivel(), 'sem o arquivo do secret: indisponível');
$erro = excecao(fn () => Cofre::cifrar('x', 'empresa-1'));
confere($erro instanceof CofreIndisponivel && $erro->getMessage() === 'Saque Pix indisponível: chave de criptografia não configurada no servidor.', 'sem o secret, cifrar lança CofreIndisponivel com a mensagem do spec');
confere(Cofre::decifrar($cifrado, 'empresa-1') === null, 'sem o secret, decifrar devolve null');
Config::$valores['services.entregas.saque_chave_arquivo'] = '';
confere(!Cofre::disponivel(), 'caminho vazio: indisponível');

echo '== Saque (funções puras)' . PHP_EOL;
reiniciarSaque();
confere(Saque::ligada() === true, 'ENTREGAS_SAQUE=1: ligada');
Config::$valores['services.entregas.saque'] = null;
confere(Saque::ligada() === false, 'vazia: desligada');
confere(Saque::situacaoDoAsaas('DONE') === 'concluido' && Saque::situacaoDoAsaas('FAILED') === 'falhou' && Saque::situacaoDoAsaas('cancelled') === 'falhou', 'DONE → concluido; FAILED e CANCELLED → falhou');
confere(Saque::situacaoDoAsaas('PENDING') === 'processando' && Saque::situacaoDoAsaas('BANK_PROCESSING') === 'processando' && Saque::situacaoDoAsaas(null) === 'processando', 'o resto segue processando');
confere(Saque::chaveEscondida('11999994321', 'telefone') === '*******4321' && Saque::chaveEscondida('12345678901', 'cpf') === '*******8901', 'telefone e CPF: só os 4 últimos');
confere(Saque::chaveEscondida('joao.silva@gmail.com', 'email') === 'jo***@gmail.com', 'e-mail: 2 letras e o domínio');
confere(Saque::chaveEscondida('123e4567-e89b-12d3-a456-426614174000', 'aleatoria') === '123e…4000', 'aleatória: começo e fim');
confere(Saque::centavos('150.10') === 15010 && Saque::centavos(0.1 + 0.2) === 30 && Saque::centavos(null) === 0, 'centavos');
confere(Saque::reais(15010) === 150.1 && Saque::dinheiro(1234.5) === 'R$ 1.234,50', 'reais e dinheiro');
confere(Saque::iso('2026-10-27 10:00:00', 'empresa-1') === '2026-10-27T10:00:00-03:00' && Saque::iso(null, 'empresa-1') === null, 'ISO 8601 no fuso da empresa');
confere(Saque::TIPOS_ASAAS === ['cpf' => 'CPF', 'cnpj' => 'CNPJ', 'telefone' => 'PHONE', 'email' => 'EMAIL', 'aleatoria' => 'EVP'], 'tipos de chave no Asaas');

resumo();
