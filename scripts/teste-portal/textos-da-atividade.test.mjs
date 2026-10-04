// Texto das atividades do pedido no idioma ativo (packages/ember-ui/addon/utils/tracking-status-text.js), usado no
// console (Fleet-Ops) e no portal da loja, com as traduções reais de console/translations.
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import trackingStatusText, { traducaoDaAtividade } from '../../packages/ember-ui/addon/utils/tracking-status-text.js';

const raiz = new URL('../../', import.meta.url);
const pnpm = new URL('console/node_modules/.pnpm/', raiz);
const pastaYaml = readdirSync(pnpm).find((nome) => nome.startsWith('js-yaml@4'));
const yaml = createRequire(import.meta.url)(fileURLToPath(new URL(`${pastaYaml}/node_modules/js-yaml/index.js`, pnpm)));

function intlDe(idioma) {
    const textos = yaml.load(readFileSync(new URL(`console/translations/${idioma}.yaml`, raiz), 'utf8'));
    const ler = (chave) => chave.split('.').reduce((no, parte) => no?.[parte], textos);

    return {
        exists: (chave) => typeof ler(chave) === 'string',
        t: (chave, variaveis = {}) => ler(chave).replace(/\{(\w+)\}/g, (_, nome) => variaveis[nome] ?? ''),
    };
}

const ptBr = intlDe('pt-br');
const enUs = intlDe('en-us');

test('status padrão do fluxo e do PHP do Fleet-Ops em pt-BR', () => {
    assert.equal(trackingStatusText(ptBr, 'Order Created'), 'Pedido criado');
    assert.equal(trackingStatusText(ptBr, 'Order Dispatched'), 'Pedido despachado');
    assert.equal(trackingStatusText(ptBr, 'Order Started'), 'Pedido iniciado');
    assert.equal(trackingStatusText(ptBr, 'order started'), 'Pedido iniciado');
    assert.equal(trackingStatusText(ptBr, 'Driver Enroute'), 'Motorista a caminho');
    assert.equal(trackingStatusText(ptBr, 'Order Completed'), 'Pedido concluído');
    assert.equal(trackingStatusText(ptBr, 'Order canceled'), 'Pedido cancelado');
    assert.equal(trackingStatusText(ptBr, 'arrived'), 'Chegou ao destino');
});

test('descrições padrão em pt-BR, com e sem ponto final', () => {
    assert.equal(trackingStatusText(ptBr, 'New order was created.', 'details'), 'Novo pedido criado.');
    assert.equal(trackingStatusText(ptBr, 'New order created.', 'details'), 'Novo pedido criado.');
    assert.equal(trackingStatusText(ptBr, 'Order has been dispatched.', 'details'), 'O pedido foi despachado.');
    assert.equal(trackingStatusText(ptBr, 'Order has been started', 'details'), 'O pedido foi iniciado.');
    assert.equal(trackingStatusText(ptBr, 'Order has started', 'details'), 'O pedido foi iniciado.');
    assert.equal(trackingStatusText(ptBr, 'Driver is en-route.', 'details'), 'O motorista está a caminho.');
    assert.equal(trackingStatusText(ptBr, 'Order has been completed.', 'details'), 'O pedido foi concluído.');
    assert.equal(trackingStatusText(ptBr, 'Order was completed', 'details'), 'O pedido foi concluído.');
    assert.equal(trackingStatusText(ptBr, 'Order was canceled', 'details'), 'O pedido foi cancelado.');
    assert.equal(trackingStatusText(ptBr, 'Driver entered destination geofence "Centro".', 'details'), 'O motorista entrou na cerca do destino "Centro".');
});

test('texto digitado pela central fica como foi gravado', () => {
    assert.equal(trackingStatusText(ptBr, 'Saiu para entrega'), 'Saiu para entrega');
    assert.equal(trackingStatusText(ptBr, 'Saiu para entrega', 'details'), 'Saiu para entrega');
    assert.equal(traducaoDaAtividade(ptBr, 'Saiu para entrega'), null);
    // a descrição não é traduzida como status, nem o contrário
    assert.equal(traducaoDaAtividade(ptBr, 'Order Created', 'details'), null);
    assert.equal(traducaoDaAtividade(ptBr, 'New order was created.', 'status'), null);
});

test('vazio, nulo e sem intl', () => {
    assert.equal(trackingStatusText(ptBr, ''), '');
    assert.equal(trackingStatusText(ptBr, null), null);
    assert.equal(trackingStatusText(ptBr, undefined), undefined);
    assert.equal(trackingStatusText(null, 'Order Created'), 'Order Created');
});

test('inglês continua em inglês', () => {
    assert.equal(trackingStatusText(enUs, 'Order Created'), 'Order Created');
    assert.equal(trackingStatusText(enUs, 'Order has been dispatched.', 'details'), 'Order has been dispatched.');
});
