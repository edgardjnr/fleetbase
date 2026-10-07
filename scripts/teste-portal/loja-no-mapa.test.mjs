// Pin da loja no mapa de pedidos do portal (packages/customer-portal/addon/utils/loja-no-mapa.js).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { PIN_DA_LOJA, lojaNoMapa, mostrarPinDaLoja } from '../../packages/customer-portal/addon/utils/loja-no-mapa.js';
import { ICONE_DA_LOJA } from '../../packages/fleetops/addon/utils/entregas-icone-da-loja.js';

test('o pin é o mesmo do console, com a ponta embaixo no meio', () => {
    assert.equal(PIN_DA_LOJA.url, ICONE_DA_LOJA.url);
    assert.deepEqual(PIN_DA_LOJA.tamanho, ICONE_DA_LOJA.tamanho);
    assert.deepEqual(PIN_DA_LOJA.ponta, ICONE_DA_LOJA.ponta);
    assert.ok(existsSync(new URL('../../packages/fleetops/assets/images/loja-pin.png', import.meta.url)));
});

test('loja com a coleta: nome, endereço e coordenadas em número', () => {
    const resposta = {
        loja: { id: 'vendor_1', nome: 'Terraço Pizza Bar', endereco: 'Rua Olinda, 45', coleta: { name: 'Terraço Pizza Bar', latitude: '-21.17', longitude: '-47.81' } },
    };
    assert.deepEqual(lojaNoMapa(resposta), { nome: 'Terraço Pizza Bar', endereco: 'Rua Olinda, 45', latitude: -21.17, longitude: -47.81 });
});

test('sem nome na loja, usa o da coleta; sem endereço, null', () => {
    const loja = lojaNoMapa({ loja: { nome: '', coleta: { name: 'Loja X', latitude: -21, longitude: -47 } } });
    assert.equal(loja.nome, 'Loja X');
    assert.equal(loja.endereco, null);
});

test('sem coleta ou sem coordenada válida: sem pin', () => {
    assert.equal(lojaNoMapa(null), null);
    assert.equal(lojaNoMapa({}), null);
    assert.equal(lojaNoMapa({ loja: { nome: 'A', coleta: null } }), null);
    assert.equal(lojaNoMapa({ loja: { coleta: { latitude: null, longitude: null } } }), null);
    assert.equal(lojaNoMapa({ loja: { coleta: { latitude: '', longitude: '' } } }), null);
    assert.equal(lojaNoMapa({ loja: { coleta: { latitude: 0, longitude: 0 } } }), null);
    assert.equal(lojaNoMapa({ loja: { coleta: { latitude: 'abc', longitude: -47 } } }), null);
    assert.equal(lojaNoMapa({ loja: { coleta: { latitude: 95, longitude: -47 } } }), null);
});

test('o pin some quando o mapa já mostra a coleta pelo P', () => {
    const loja = { nome: 'A', latitude: -21, longitude: -47 };
    assert.equal(mostrarPinDaLoja(loja, []), true);
    assert.equal(mostrarPinDaLoja(loja, undefined), true);
    assert.equal(mostrarPinDaLoja(loja, [{ type: 'dropoff' }]), true);
    assert.equal(mostrarPinDaLoja(loja, [{ type: 'pickup' }, { type: 'dropoff' }]), false);
    assert.equal(mostrarPinDaLoja(null, []), false);
});
