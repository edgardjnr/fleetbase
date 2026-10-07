// Pin da loja no lugar do predinho preto nos mapas do console (packages/fleetops/addon/utils/entregas-icone-da-loja.js).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import iconeDoLocal, { ICONE_DA_LOJA, avatarPadrao } from '../../packages/fleetops/addon/utils/entregas-icone-da-loja.js';

test('pin da loja: a imagem existe e a ponta fica embaixo no meio', () => {
    assert.equal(ICONE_DA_LOJA.url, '/engines-dist/images/loja-pin.png');
    assert.ok(existsSync(new URL('../../packages/fleetops/assets/images/loja-pin.png', import.meta.url)));
    assert.deepEqual(ICONE_DA_LOJA.tamanho, [34, 40]);
    assert.deepEqual(ICONE_DA_LOJA.ponta, [17, 40]);
});

test('avatar padrão do Fleetbase (predinho) ou nenhum: vira o pin', () => {
    assert.equal(avatarPadrao('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/place-icons/basic-building.png'), true);
    assert.equal(avatarPadrao('/engines-dist/images/building-marker.png'), true);
    assert.equal(avatarPadrao(null), true);
    assert.equal(avatarPadrao(''), true);
    assert.equal(iconeDoLocal({ avatar_url: null }), ICONE_DA_LOJA);
    assert.equal(iconeDoLocal(undefined), ICONE_DA_LOJA);
});

test('avatar próprio do local continua, quadrado e centrado, sempre com ponta e popup', () => {
    const icone = iconeDoLocal({ avatar_url: 'https://arquivos.exemplo/minha-loja.png' }, 40);
    assert.deepEqual(icone, { url: 'https://arquivos.exemplo/minha-loja.png', tamanho: [40, 40], ponta: [20, 20], popup: [0, 0] });
    assert.deepEqual(iconeDoLocal({ avatar_url: 'https://arquivos.exemplo/x.png' }).tamanho, [16, 16]);
});
