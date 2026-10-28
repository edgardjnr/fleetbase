<?php

// Chamar motoboy: validação e normalização do corpo do POST loja/chamar-motoboy e do telefone (ChamadoDaLoja, função pura).
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy-validacao.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Portal\ChamadoDaLoja;

function corpoDoChamado(array $mudancas = []): array
{
    return array_replace_recursive([
        'chave'      => '6F1C0E8A-2B1D-4C3E-9A7F-0D5B8C2E4F10',
        'cliente'    => ['nome' => '  Mariana  Souza ', 'telefone' => '+55 (16) 99999-0000'],
        'entrega'    => [
            'rua' => 'Rua Olinda, 45', 'bairro' => 'Centro', 'cidade' => 'Ribeirão Preto', 'uf' => 'sp', 'cep' => '14010000',
            'latitude' => -21.18, 'longitude' => -47.81, 'complemento' => 'Ap 12', 'referencia' => 'Perto da praça',
        ],
        'cobranca'   => ['forma' => 'dinheiro', 'valor' => '58,90', 'troco_para' => '100'],
        'observacao' => "Tocar o interfone\r\nBolo frágil",
    ], $mudancas);
}

function tem(array $r, string $trecho): bool
{
    foreach ($r['erros'] as $erro) {
        if (str_contains($erro, $trecho)) {
            return true;
        }
    }

    return false;
}

echo '== Corpo completo' . PHP_EOL;
$r = ChamadoDaLoja::validar(corpoDoChamado());
$d = $r['dados'];
confere($r['erros'] === [] && $d !== null, 'sem erros');
confere($d['chave'] === '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10', 'chave em minúsculas');
confere($d['cliente_nome'] === 'Mariana Souza' && $d['cliente_telefone'] === '16999990000', 'nome sem espaços sobrando; telefone sem o 55');
confere($d['rua'] === 'Rua Olinda, 45' && $d['bairro'] === 'Centro' && $d['cidade'] === 'Ribeirão Preto' && $d['uf'] === 'SP' && $d['cep'] === '14010-000', 'endereço, UF e CEP');
confere($d['latitude'] === -21.18 && $d['longitude'] === -47.81, 'ponto');
confere($d['complemento'] === 'Ap 12' && $d['referencia'] === 'Perto da praça', 'complemento e referência');
confere($d['cobranca_forma'] === 'dinheiro' && $d['cobranca_centavos'] === 5890 && $d['troco_para_centavos'] === 10000, 'cobrança em centavos');
confere($d['observacoes'] === "Tocar o interfone\nBolo frágil", 'observação com a quebra de linha');

echo '== Telefone' . PHP_EOL;
confere(ChamadoDaLoja::telefone('(16) 99999-0000') === '16999990000', 'celular com DDD');
confere(ChamadoDaLoja::telefone('16 3333-0000') === '1633330000', 'fixo com DDD');
confere(ChamadoDaLoja::telefone('016 99999-0000') === '16999990000', 'zero da frente sai');
confere(ChamadoDaLoja::telefone('5516999990000') === '16999990000', '55 da frente sai');
confere(ChamadoDaLoja::telefone('55999990000') === '55999990000', 'DDD 55 (RS) fica');
confere(ChamadoDaLoja::telefone('99999-0000') === null && ChamadoDaLoja::telefone('') === null && ChamadoDaLoja::telefone(null) === null && ChamadoDaLoja::telefone(['x']) === null, 'sem DDD, vazio ou não texto: null');
confere(ChamadoDaLoja::telefone('0999990000') === null, 'DDD não começa com 0');

echo '== Cobrança' . PHP_EOL;
$d = ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['forma' => 'pago', 'valor' => '10']]))['dados'];
confere($d['cobranca_forma'] === 'pago' && $d['cobranca_centavos'] === 0 && $d['troco_para_centavos'] === null, 'já pago: nada a cobrar');
$d = ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['forma' => 'PIX', 'valor' => 'R$ 1.234,56', 'troco_para' => '2000']]))['dados'];
confere($d['cobranca_forma'] === 'pix' && $d['cobranca_centavos'] === 123456 && $d['troco_para_centavos'] === null, 'pix com milhar; troco só em dinheiro (ignorado)');
confere(ChamadoDaLoja::centavos('1.500') === 150000 && ChamadoDaLoja::centavos('R$ 12.500') === 1250000, 'sem vírgula, 1.500 é milhar');
confere(ChamadoDaLoja::centavos('58.9') === 5890 && ChamadoDaLoja::centavos('58.90') === 5890 && ChamadoDaLoja::centavos('1.5000') === 150, 'ponto decimal segue decimal');
confere(ChamadoDaLoja::centavos('1.000.000') === null, 'milhar acima de R$ 100 mil: recusa');
$d = ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['troco_para' => '58,90']]))['dados'];
confere($d['troco_para_centavos'] === null, 'troco igual ao valor = sem troco');
$d = ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['troco_para' => '']]))['dados'];
confere($d['troco_para_centavos'] === null, 'troco vazio = sem troco');
confere(tem(ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['troco_para' => '50']])), 'troco precisa ser para um valor maior'), 'troco menor que o valor: erro');
confere(tem(ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['valor' => '0']])), 'Informe o valor a cobrar'), 'valor zero: erro');
confere(tem(ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['valor' => '200000']])), 'Informe o valor a cobrar'), 'valor acima de R$ 100 mil: erro');
confere(tem(ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['forma' => 'boleto']])), 'já pagou ou como'), 'forma desconhecida: erro');

echo '== Obrigatórios e limites' . PHP_EOL;
$r = ChamadoDaLoja::validar(['cliente' => [], 'entrega' => [], 'cobranca' => []]);
confere($r['dados'] === null && count($r['erros']) === 6, 'corpo vazio: os seis erros de uma vez');
confere(tem($r, 'Atualize a página') && tem($r, 'nome do cliente') && tem($r, 'telefone do cliente com DDD') && tem($r, 'rua e o número') && tem($r, 'Marque no mapa') && tem($r, 'já pagou'), 'mensagens para a loja');
confere(tem(ChamadoDaLoja::validar(corpoDoChamado(['entrega' => ['latitude' => 0, 'longitude' => 0]])), 'Marque no mapa'), 'ponto 0,0: erro');
confere(tem(ChamadoDaLoja::validar(corpoDoChamado(['entrega' => ['latitude' => 'x']])), 'Marque no mapa'), 'ponto não numérico: erro');
$d = ChamadoDaLoja::validar(corpoDoChamado(['cliente' => ['nome' => str_repeat('a', 200)], 'entrega' => ['uf' => 'São Paulo', 'cep' => '140', 'complemento' => '', 'referencia' => null], 'observacao' => null]))['dados'];
confere(mb_strlen($d['cliente_nome']) === 120, 'nome longo é cortado em 120 (sem 422)');
confere($d['uf'] === null && $d['cep'] === null && $d['complemento'] === null && $d['referencia'] === null && $d['observacoes'] === null, 'UF e CEP inválidos e opcionais vazios: null');
$d = ChamadoDaLoja::validar(corpoDoChamado(['cliente' => ['nome' => "Ana\u{00A0}\u{0007}Paula"]]))['dados'];
confere($d['cliente_nome'] === 'Ana Paula', 'espaço Unicode e controle saem do nome');

resumo();
