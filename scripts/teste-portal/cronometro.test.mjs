// Cronômetro do prazo de entrega (spec docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md). A função do
// console (packages/fleetops/addon/utils/entregas-cronometro.js) e a do portal
// (packages/customer-portal/addon/utils/entregas-cronometro.js) são cópias: os mesmos casos rodam nas duas.
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/cronometro.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as doConsole from '../../packages/fleetops/addon/utils/entregas-cronometro.js';
import * as doPortal from '../../packages/customer-portal/addon/utils/entregas-cronometro.js';
import { ENCERRADOS as ENCERRADOS_DO_PORTAL } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';

const PRAZO = '2026-10-20T19:57:51-03:00';
const T = Date.parse(PRAZO);
const MIN = 60000;
const SEG = 1000;

for (const [nome, m] of [
    ['console', doConsole],
    ['portal', doPortal],
]) {
    test(`${nome}: mais de 15 min = no prazo (verde), minutos arredondados para cima`, () => {
        assert.deepEqual(m.cronometro(PRAZO, T - 32 * MIN), { minutos: 32, estado: 'no_prazo', texto: '32 min' });
        assert.deepEqual(m.cronometro(PRAZO, T - (31 * MIN + 1 * SEG)), { minutos: 32, estado: 'no_prazo', texto: '32 min' });
        assert.deepEqual(m.cronometro(PRAZO, T - (15 * MIN + 1 * SEG)), { minutos: 16, estado: 'no_prazo', texto: '16 min' });
    });

    test(`${nome}: 15 min ou menos = perto (amarelo); 59 s viram "1 min"`, () => {
        assert.deepEqual(m.cronometro(PRAZO, T - 15 * MIN), { minutos: 15, estado: 'perto', texto: '15 min' });
        assert.deepEqual(m.cronometro(PRAZO, T - 12 * MIN), { minutos: 12, estado: 'perto', texto: '12 min' });
        assert.deepEqual(m.cronometro(PRAZO, T - 59 * SEG), { minutos: 1, estado: 'perto', texto: '1 min' });
        assert.deepEqual(m.cronometro(PRAZO, T - 1), { minutos: 1, estado: 'perto', texto: '1 min' });
    });

    test(`${nome}: no prazo exato e depois = atrasado (vermelho), minutos para cima com mínimo 1, nunca "há 0 min"`, () => {
        const atrasado = (minutos) => ({ minutos, estado: 'atrasado', texto: `Atrasado há ${minutos} min` });
        assert.deepEqual(m.cronometro(PRAZO, T), atrasado(1), '0 s de atraso');
        assert.deepEqual(m.cronometro(PRAZO, T + 1 * SEG), atrasado(1), '1 s');
        assert.deepEqual(m.cronometro(PRAZO, T + 60 * SEG), atrasado(1), '60 s');
        assert.deepEqual(m.cronometro(PRAZO, T + 61 * SEG), atrasado(2), '61 s');
        assert.deepEqual(m.cronometro(PRAZO, T + 120 * SEG), atrasado(2), '120 s');
        assert.deepEqual(m.cronometro(PRAZO, T + 121 * SEG), atrasado(3), '121 s');
        assert.deepEqual(m.cronometro(PRAZO, T + 7 * MIN), atrasado(7), '7 min');
        assert.deepEqual(m.cronometro(PRAZO, T + (6 * MIN + 1 * SEG)), atrasado(7), '6 min 1 s');
    });

    test(`${nome}: prazo em UTC (Z) e agora como Date valem igual`, () => {
        assert.deepEqual(m.cronometro('2026-10-20T22:57:51Z', T - 32 * MIN), { minutos: 32, estado: 'no_prazo', texto: '32 min' });
        assert.deepEqual(m.cronometro(PRAZO, new Date(T + 7 * MIN)), { minutos: 7, estado: 'atrasado', texto: 'Atrasado há 7 min' });
    });

    test(`${nome}: prazo ausente ou ilegível (ou agora inválido) = null`, () => {
        for (const valor of [undefined, null, '', '   ', 'amanhã', '2026-10-20', 'x2026-10-20T19:57:51-03:00', '2026-13-45T99:99:00-03:00', 123, {}, []]) {
            assert.equal(m.cronometro(valor, T), null, `prazo ${JSON.stringify(valor)}`);
        }
        assert.equal(m.cronometro(PRAZO, NaN), null);
        assert.equal(m.cronometro(PRAZO, 'agora'), null);
    });

    test(`${nome}: textos traduzidos pelo terceiro parâmetro`, () => {
        const textos = { faltam: (n) => `${n} min left`, atrasado: (n) => `Late by ${n} min` };
        assert.equal(m.cronometro(PRAZO, T - 32 * MIN, textos).texto, '32 min left');
        assert.equal(m.cronometro(PRAZO, T + 7 * MIN, textos).texto, 'Late by 7 min');
    });

    test(`${nome}: prazo do pedido lido do meta (model ou objeto)`, () => {
        assert.equal(m.prazoDoPedido({ meta: { entregas_prazo_em: PRAZO } }), PRAZO);
        assert.equal(m.prazoDoPedido({ meta: { _index_resource: true } }), null);
        assert.equal(m.prazoDoPedido({ meta: { entregas_prazo_em: '  ' } }), null);
        assert.equal(m.prazoDoPedido({ meta: null }), null);
        assert.equal(m.prazoDoPedido({}), null);
        assert.equal(m.prazoDoPedido(null), null);
    });

    test(`${nome}: cronômetro do pedido some no encerrado e sem prazo`, () => {
        const emAndamento = { status: 'started', meta: { entregas_prazo_em: PRAZO } };
        assert.deepEqual(m.cronometroDoPedido(emAndamento, T - 12 * MIN), { minutos: 12, estado: 'perto', texto: '12 min' });
        for (const status of m.ENCERRADOS) {
            assert.equal(m.cronometroDoPedido({ ...emAndamento, status }, T - 12 * MIN), null, `status ${status}`);
        }
        assert.equal(m.cronometroDoPedido({ status: 'started', meta: {} }, T), null, 'pedido de antes do deploy');
        assert.equal(m.cronometroDoPedido(null, T), null);
    });

    test(`${nome}: tipo do Badge (tokens do ember-ui) por estado`, () => {
        assert.deepEqual(m.TIPO_DO_BADGE, { no_prazo: 'success', perto: 'warning', atrasado: 'error' });
    });
}

test('console e portal: as mesmas constantes, e os encerrados do portal', () => {
    assert.equal(doConsole.CHAVE_DO_PRAZO, 'entregas_prazo_em');
    assert.equal(doPortal.CHAVE_DO_PRAZO, doConsole.CHAVE_DO_PRAZO);
    assert.equal(doConsole.PERTO_MS, 15 * MIN);
    assert.equal(doPortal.PERTO_MS, doConsole.PERTO_MS);
    assert.equal(doConsole.INTERVALO_DO_CRONOMETRO_MS, 30000);
    assert.equal(doPortal.INTERVALO_DO_CRONOMETRO_MS, doConsole.INTERVALO_DO_CRONOMETRO_MS);
    assert.deepEqual(doPortal.ENCERRADOS, doConsole.ENCERRADOS);
    assert.deepEqual(doPortal.ENCERRADOS, ENCERRADOS_DO_PORTAL);
});
