<?php

// Integração: validação e normalização do corpo do POST pedidos e o texto da cobrança na porta.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-pedido.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Integracao\CobrancaDaIntegracao;
use App\Support\Entregas\Integracao\PedidoDaIntegracao;

function validar(array $mudancas = []): array
{
    return PedidoDaIntegracao::validar(corpoDoPedido($mudancas));
}

function temErro(array $resultado, string $trecho): bool
{
    foreach ($resultado['erros'] as $erro) {
        if (str_contains($erro, $trecho)) {
            return true;
        }
    }

    return false;
}

echo '== Corpo completo' . PHP_EOL;
$r = validar();
$d = $r['dados'];
confere($r['erros'] === [] && $d !== null, 'sem erros');
confere($d['pedido_externo_id'] === 'ord-6f1c' && $d['numero'] === '4821' && $d['cliente_nome'] === 'Maria Ficticia', 'ids e nome');
confere($d['cliente_telefone'] === '16999990000', 'telefone só com dígitos');
confere($d['endereco'] === 'Rua Ficticia, 45' && $d['complemento'] === 'Ap 12' && $d['bairro'] === 'Jardim Ficticio' && $d['cidade'] === 'Ribeirão Preto', 'endereço');
confere($d['uf'] === 'SP' && $d['cep'] === '14090-000' && $d['referencia'] === 'Perto da praça', 'UF em maiúsculas e CEP formatado');
confere($d['latitude'] === -21.17 && $d['longitude'] === -47.81, 'coordenadas');
confere($d['cobranca_forma'] === 'dinheiro' && $d['cobranca_centavos'] === 5890 && $d['troco_para_centavos'] === 10000, 'cobrança em centavos');
confere($d['observacoes'] === 'Sem cebola', 'observações');

echo '== Normalização' . PHP_EOL;
$d = validar(['numero' => 4821, 'cliente' => ['nome' => "  Maria \n  Ficticia "], 'cobranca' => ['valor' => '58,90', 'troco_para' => '100'], 'observacoes' => "Linha 1\r\nLinha 2"])['dados'];
confere($d['numero'] === '4821' && $d['cliente_nome'] === 'Maria Ficticia', 'número inteiro vira texto; espaços e quebras colapsados');
confere($d['cobranca_centavos'] === 5890 && $d['troco_para_centavos'] === 10000, 'valor com vírgula');
confere($d['observacoes'] === "Linha 1\nLinha 2", 'observações mantêm a quebra de linha');
$d = validar(['entrega' => ['latitude' => null, 'longitude' => null, 'complemento' => '', 'bairro' => null, 'uf' => '', 'cep' => null], 'cliente' => ['telefone' => '']])['dados'];
confere($d['latitude'] === null && $d['longitude'] === null && $d['complemento'] === null && $d['bairro'] === null && $d['uf'] === null && $d['cep'] === null && $d['cliente_telefone'] === null, 'opcionais vazios = null');
$d = validar(['cobranca' => ['forma' => 'PAGO', 'valor' => null, 'troco_para' => null]])['dados'];
confere($d['cobranca_forma'] === 'pago' && $d['cobranca_centavos'] === 0 && $d['troco_para_centavos'] === null, 'pago: nada a cobrar');
$d = validar(['cobranca' => ['forma' => 'dinheiro', 'troco_para' => 0]])['dados'];
confere($d['troco_para_centavos'] === null, 'troco 0 = sem troco');
$d = validar(['cobranca' => ['forma' => 'cartao', 'troco_para' => null]])['dados'];
confere($d['cobranca_forma'] === 'cartao' && $d['troco_para_centavos'] === null, 'cartão sem troco');

echo '== Erros' . PHP_EOL;
confere(temErro(validar(['pedido_externo_id' => '']), 'pedido_externo_id é obrigatório'), 'sem pedido_externo_id');
confere(temErro(validar(['pedido_externo_id' => 'ord 1/2']), 'pedido_externo_id aceita só'), 'pedido_externo_id com caractere estranho');
confere(temErro(validar(['pedido_externo_id' => str_repeat('a', 101)]), 'pedido_externo_id passa de 100'), 'pedido_externo_id longo');
confere(temErro(validar(['numero' => null]), 'numero é obrigatório'), 'sem número');
confere(temErro(validar(['cliente' => ['nome' => ['x']]]), 'cliente.nome precisa ser texto'), 'nome que não é texto');
confere(temErro(validar(['entrega' => ['endereco' => '  ']]), 'entrega.endereco é obrigatório'), 'sem endereço');
confere(temErro(validar(['entrega' => ['cidade' => null]]), 'entrega.cidade é obrigatório'), 'sem cidade');
confere(temErro(validar(['entrega' => ['uf' => 'São Paulo']]), 'entrega.uf'), 'UF inválida');
confere(temErro(validar(['entrega' => ['cep' => '1409']]), 'entrega.cep'), 'CEP curto');
confere(temErro(validar(['cliente' => ['telefone' => '123']]), 'cliente.telefone'), 'telefone curto');
confere(temErro(validar(['entrega' => ['latitude' => -21.17, 'longitude' => null]]), 'entrega.latitude e entrega.longitude'), 'só uma coordenada');
confere(temErro(validar(['entrega' => ['latitude' => 'norte']]), 'entrega.latitude e entrega.longitude'), 'coordenada que não é número');
confere(temErro(validar(['entrega' => ['latitude' => 95]]), 'entrega.latitude e entrega.longitude'), 'latitude fora da faixa');
confere(temErro(validar(['cobranca' => ['forma' => 'cheque']]), 'cobranca.forma'), 'forma desconhecida');
confere(temErro(validar(['cobranca' => ['valor' => 0]]), 'cobranca.valor'), 'valor zero fora de pago');
confere(temErro(validar(['cobranca' => ['valor' => 200000]]), 'cobranca.valor'), 'valor acima de R$ 100 mil');
confere(temErro(validar(['cobranca' => ['troco_para' => 50]]), 'cobranca.troco_para'), 'troco menor que o valor');
confere(temErro(validar(['cobranca' => ['forma' => 'pix', 'troco_para' => 100]]), 'cobranca.troco_para'), 'troco fora do dinheiro');
confere(temErro(validar(['observacoes' => str_repeat('a', 1001)]), 'observacoes passa de 1000'), 'observações longas');
confere(validar(['numero' => null])['dados'] === null, 'com erro: sem dados');
$varios = PedidoDaIntegracao::validar([]);
confere(count($varios['erros']) >= 5, 'corpo vazio: todos os obrigatórios de uma vez');

echo '== Espaço Unicode, controles e teto' . PHP_EOL;
confere(temErro(validar(['entrega' => ['endereco' => "\u{00A0}"]]), 'entrega.endereco é obrigatório'), 'endereço só com NBSP');
confere(temErro(validar(['cliente' => ['nome' => "\u{2003}"]]), 'cliente.nome é obrigatório'), 'nome só com espaço largo');
confere(validar(['entrega' => ['complemento' => "\u{00A0}"]])['dados']['complemento'] === null, 'complemento só com NBSP = null');
confere(validar(['cliente' => ['nome' => "Ma\0ria"]])['dados']['cliente_nome'] === 'Maria', 'nome sem caractere nulo');
confere(validar(['observacoes' => "a\rb\x07c"])['dados']['observacoes'] === "a\nbc", 'observações: \r vira \n, controle sai');
confere(temErro(validar(['numero' => 1e20]), 'numero precisa ser texto'), 'número float');
confere(temErro(validar(['cobranca' => ['valor' => (2 ** 64 + 4096) / 100]]), 'cobranca.valor'), 'valor float gigante');
confere(temErro(validar(['cobranca' => ['troco_para' => 'abc']]), 'cobranca.troco_para precisa ser um valor em reais'), 'troco que não é número');
confere(temErro(validar(['cobranca' => ['troco_para' => 200000]]), 'cobranca.troco_para passa de 100000'), 'troco acima do teto');
$r = validar(['entrega' => ['uf' => '  ']]);
confere($r['erros'] === [] && $r['dados']['uf'] === null, 'UF só com espaço = null');

echo '== Revisão 2' . PHP_EOL;
confere(validar(['cobranca' => ['troco_para' => '100,00']])['dados']['troco_para_centavos'] === 10000, 'troco com vírgula');
confere(temErro(validar(['cliente' => ['telefone' => "\xFF16999990000"]]), 'cliente.telefone precisa ser texto em UTF-8'), 'telefone com UTF-8 inválido');
confere(temErro(validar(['entrega' => ['cep' => "\xFF14090000"]]), 'entrega.cep precisa ser texto em UTF-8'), 'CEP com UTF-8 inválido');
confere(temErro(validar(['entrega' => ['uf' => "\xFFSP"]]), 'entrega.uf precisa ser texto em UTF-8'), 'UF com UTF-8 inválido');
confere(validar(['cliente' => ['nome' => "Maria \u{200B} Ficticia"]])['dados']['cliente_nome'] === 'Maria Ficticia', 'invisível entre espaços não deixa espaço duplo');
confere(validar(['observacoes' => "d\te"])['dados']['observacoes'] === 'd e', 'tab vira espaço nas observações');
confere(validar(['observacoes' => "👨\u{200D}👩"])['dados']['observacoes'] === "👨\u{200D}👩", 'ZWJ de emoji composto fica');

echo '== Emoji, acentos e CJK' . PHP_EOL;
confere(validar(['cliente' => ['nome' => 'Maria 😅']])['dados']['cliente_nome'] === 'Maria 😅', 'nome com emoji');
confere(validar(['entrega' => ['endereco' => 'Rua Å, 45']])['dados']['endereco'] === 'Rua Å, 45', 'endereço com Å');
confere(validar(['entrega' => ['complemento' => 'Bloco 😅']])['dados']['complemento'] === 'Bloco 😅', 'complemento com emoji');
confere(validar(['cliente' => ['nome' => "Maria\u{0085}Ficticia"]])['dados']['cliente_nome'] === 'Maria Ficticia', 'U+0085 vira espaço');
confere(validar(['observacoes' => "Sem cebola 😅\nObrigado"])['dados']['observacoes'] === "Sem cebola 😅\nObrigado", 'observações com emoji intactas');
confere(validar(['cliente' => ['nome' => '王小明']])['dados']['cliente_nome'] === '王小明', 'nome em CJK');
confere(validar(['cliente' => ['nome' => 'José Ângelo Çãõ']])['dados']['cliente_nome'] === 'José Ângelo Çãõ', 'acentos variados');

echo '== Cobrança' . PHP_EOL;
confere(CobrancaDaIntegracao::texto('dinheiro', 5890, 10000) === 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100', 'dinheiro com troco');
confere(CobrancaDaIntegracao::texto('dinheiro', 5890, null) === 'Cobrar R$ 58,90 · dinheiro', 'dinheiro sem troco');
confere(CobrancaDaIntegracao::texto('cartao', 5890, null) === 'Cobrar R$ 58,90 · cartão (maquininha)', 'cartão');
confere(CobrancaDaIntegracao::texto('pix', 5890, null) === 'Cobrar R$ 58,90 · Pix', 'Pix');
confere(CobrancaDaIntegracao::texto('pago', 0, null) === null && CobrancaDaIntegracao::texto('dinheiro', 0, null) === null, 'nada a cobrar: null');
confere(CobrancaDaIntegracao::forma('cartao') === 'cartão (maquininha)' && CobrancaDaIntegracao::forma('pago') === 'pago' && CobrancaDaIntegracao::forma(null) === null, 'forma em pt-BR');

resumo();
