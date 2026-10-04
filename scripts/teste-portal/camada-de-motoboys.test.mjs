// Camada dos capacetes no mapa do portal (packages/customer-portal/addon/utils/camada-de-motoboys.js), com um Leaflet
// falso: cria, desliza, salta, destaca e remove os marcadores.
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import CamadaDeMotoboys from '../../packages/customer-portal/addon/utils/camada-de-motoboys.js';
import { DESLIZE_MS } from '../../packages/customer-portal/addon/utils/motoboys-no-mapa.js';

class ListaDeClasses {
    nomes = new Set();

    toggle(nome, ligar) {
        if (ligar) {
            this.nomes.add(nome);
        } else {
            this.nomes.delete(nome);
        }
    }

    contains(nome) {
        return this.nomes.has(nome);
    }
}

function leafletFalso() {
    const marcadores = [];
    const L = {
        icon: (opcoes) => ({ opcoes }),
        marker(latlng, opcoes) {
            const elementoDoRotulo = { classList: new ListaDeClasses() };
            const marcador = {
                latlng: [...latlng],
                opcoes,
                icone: opcoes.icon,
                zIndexOffset: 0,
                mapa: null,
                tooltip: null,
                bindTooltip(conteudo, opcoesDoRotulo) {
                    this.tooltip = { conteudo, opcoes: opcoesDoRotulo, getElement: () => elementoDoRotulo };
                    return this;
                },
                getTooltip() {
                    return this.tooltip;
                },
                addTo(mapa) {
                    this.mapa = mapa;
                    return this;
                },
                remove() {
                    this.mapa = null;
                    return this;
                },
                getLatLng() {
                    return { lat: this.latlng[0], lng: this.latlng[1] };
                },
                setLatLng(latlng) {
                    this.latlng = [...latlng];
                    return this;
                },
                setIcon(icone) {
                    this.icone = icone;
                    return this;
                },
                setZIndexOffset(z) {
                    this.zIndexOffset = z;
                    return this;
                },
            };
            marcadores.push(marcador);
            return marcador;
        },
    };

    return { L, marcadores };
}

function montar({ reduzirMovimento = false } = {}) {
    const { L, marcadores } = leafletFalso();
    const relogio = { agora: 1000 };
    const quadros = [];
    const mapa = { nome: 'mapa' };
    const camada = new CamadaDeMotoboys(mapa, {
        L,
        reduzirMovimento: () => reduzirMovimento,
        agora: () => relogio.agora,
        pedirQuadro: (passo) => {
            quadros.push(passo);
            return quadros.length;
        },
        cancelarQuadro: () => {
            quadros.length = 0;
        },
        criarRotulo: () => ({ textContent: '' }),
    });
    // roda o próximo quadro pedido pela animação, no instante t
    const quadro = (t) => {
        relogio.agora = t;
        const passo = quadros.shift();
        passo?.(t);
    };

    return { camada, marcadores, quadro, quadros, relogio, mapa };
}

const ana = (latitude, longitude, extra = {}) => ({ id: 'a1', nome: 'Ana', latitude, longitude, situacao: 'livre', pedidos: [], ...extra });
const bia = (extra = {}) => ({ id: 'b2', nome: 'Bia', latitude: -21.18, longitude: -47.82, situacao: 'coleta', pedidos: ['order_x'], ...extra });

test('cria o capacete na posição, com o ícone da situação e o nome como texto', () => {
    const { camada, marcadores, mapa } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    assert.equal(marcadores.length, 1);
    const [marcador] = marcadores;
    assert.deepEqual(marcador.latlng, [-21.17, -47.81]);
    assert.equal(marcador.icone.opcoes.iconUrl, '/engines-dist/images/capacete-verde.png');
    assert.deepEqual(marcador.icone.opcoes.iconSize, [36, 36]);
    assert.deepEqual(marcador.icone.opcoes.tooltipAnchor, [0, 13]);
    assert.equal(marcador.opcoes.interactive, false);
    assert.equal(marcador.opcoes.keyboard, false);
    assert.equal(marcador.tooltip.opcoes.permanent, true);
    assert.equal(marcador.tooltip.opcoes.direction, 'bottom');
    assert.equal(marcador.tooltip.opcoes.className, 'entregas-nome-motoboy');
    assert.equal(marcador.tooltip.conteudo.textContent, 'Ana');
    assert.equal(marcador.mapa, mapa);
});

test('nome com HTML entra como texto', () => {
    const { camada, marcadores } = montar();
    camada.atualizar([ana(-21.17, -47.81, { nome: '<img src=x onerror=alert(1)>' })]);
    assert.equal(marcadores[0].tooltip.conteudo.textContent, '<img src=x onerror=alert(1)>');
});

test('posição nova perto: desliza durante DESLIZE_MS', () => {
    const { camada, marcadores, quadro } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.1705, -47.81)]); // ~56 m, no instante 1000
    const [marcador] = marcadores;
    assert.deepEqual(marcador.latlng, [-21.17, -47.81], 'ainda não andou');
    quadro(1000 + DESLIZE_MS / 2);
    assert.ok(Math.abs(marcador.latlng[0] - -21.17025) < 1e-9, `metade do caminho, veio ${marcador.latlng[0]}`);
    quadro(1000 + DESLIZE_MS + 1);
    assert.deepEqual(marcador.latlng, [-21.1705, -47.81], 'chegou');
});

test('posição nova no meio do deslize: recomeça do ponto onde está', () => {
    const { camada, marcadores, quadro, relogio } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.1705, -47.81)]);
    quadro(1000 + DESLIZE_MS / 2); // em -21.17025
    relogio.agora = 1000 + DESLIZE_MS / 2;
    camada.atualizar([ana(-21.171, -47.81)]);
    quadro(1000 + DESLIZE_MS); // metade do deslize novo, de -21.17025 a -21.171
    assert.ok(Math.abs(marcadores[0].latlng[0] - -21.170625) < 1e-9, `veio ${marcadores[0].latlng[0]}`);
});

test('mesmo destino durante o deslize: o deslize segue no tempo dele', () => {
    const { camada, marcadores, quadro, relogio } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.1705, -47.81)]);
    quadro(1000 + DESLIZE_MS / 2);
    relogio.agora = 1000 + DESLIZE_MS / 2;
    camada.atualizar([ana(-21.1705, -47.81)]);
    quadro(1000 + DESLIZE_MS + 1);
    assert.deepEqual(marcadores[0].latlng, [-21.1705, -47.81], 'chegou no tempo do primeiro deslize');
});

test('pulo de mais de 1 km: salta sem animação', () => {
    const { camada, marcadores, quadros } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.19, -47.81)]); // ~2,2 km
    assert.deepEqual(marcadores[0].latlng, [-21.19, -47.81]);
    assert.equal(quadros.length, 0);
});

test('menos animação no sistema: salta', () => {
    const { camada, marcadores, quadros } = montar({ reduzirMovimento: true });
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.1705, -47.81)]);
    assert.deepEqual(marcadores[0].latlng, [-21.1705, -47.81]);
    assert.equal(quadros.length, 0);
});

test('troca de situação troca o ícone; troca de nome troca o rótulo', () => {
    const { camada, marcadores } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.17, -47.81, { situacao: 'entrega', nome: 'Ana Paula' })]);
    assert.equal(marcadores.length, 1, 'o mesmo marcador');
    assert.equal(marcadores[0].icone.opcoes.iconUrl, '/engines-dist/images/capacete-vermelho.png');
    assert.equal(marcadores[0].tooltip.conteudo.textContent, 'Ana Paula');
});

test('destaque: por cima dos outros e com o rótulo destacado; sai quando o pedido fecha', () => {
    const { camada, marcadores } = montar();
    camada.atualizar([ana(-21.17, -47.81), bia()], { destaque: 'b2' });
    const [marcadorDaAna, marcadorDaBia] = marcadores;
    assert.equal(marcadorDaBia.zIndexOffset, 1000);
    assert.equal(marcadorDaBia.tooltip.getElement().classList.contains('entregas-nome-motoboy-destaque'), true);
    assert.equal(marcadorDaAna.zIndexOffset, 0);
    camada.atualizar([ana(-21.17, -47.81), bia()], { destaque: null });
    assert.equal(marcadorDaBia.zIndexOffset, 0);
    assert.equal(marcadorDaBia.tooltip.getElement().classList.contains('entregas-nome-motoboy-destaque'), false);
});

test('quem saiu da lista sai do mapa; quem é inválido não entra', () => {
    const { camada, marcadores, mapa } = montar();
    camada.atualizar([ana(-21.17, -47.81), bia()]);
    camada.atualizar([ana(-21.17, -47.81), { id: 'c3', nome: 'Caio', latitude: 0, longitude: 0, situacao: 'livre', pedidos: [] }]);
    assert.equal(marcadores.length, 2, 'Caio, em (0, 0), não ganhou marcador');
    assert.equal(marcadores[1].mapa, null, 'Bia saiu do mapa');
    assert.equal(marcadores[0].mapa, mapa);
});

test('destruir tira todos do mapa e para a animação', () => {
    const { camada, marcadores, quadros } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.1705, -47.81)]);
    assert.equal(quadros.length, 1);
    camada.destruir();
    assert.equal(marcadores[0].mapa, null);
    assert.equal(quadros.length, 0, 'quadro cancelado');
    camada.atualizar([ana(-21.17, -47.81)]);
    assert.equal(marcadores.length, 1, 'depois de destruída não desenha mais');
});
