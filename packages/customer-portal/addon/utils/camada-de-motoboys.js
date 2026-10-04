import { DESLIZE_MS, capaceteDaSituacao, deveSaltar, interpolar, motoboysValidos } from './motoboys-no-mapa';

// Entregas: os capacetes dos motoboys no mapa do portal da loja. Cria, move e remove os marcadores direto no Leaflet, com
// um laço de animação só para todos, que anima só quem está deslizando. O nome fica dentro do marcador (divIcon), e não
// num tooltip: o motoboy destacado sobe inteiro, capacete e nome (os tooltips ficam numa camada acima de todos os
// marcadores), e mover o marcador não obriga o navegador a medir um rótulo a cada quadro. O nome entra como texto, nunca
// como HTML. Marcador não interativo: clicar no capacete não faz nada, porque o portal não tem detalhes do motoboy para
// mostrar. O Leaflet, o relógio, os quadros e os elementos vêm por opção (os testes usam falsos)

/** Intervalo mínimo entre dois quadros desenhados (~30 por segundo): poupa o celular com muitos motoboys andando. */
const QUADRO_MS = 33;

/** O marcador do motoboy do pedido aberto fica por cima dos outros. */
const Z_DESTAQUE = 1000;

const reduzirMovimentoDoSistema = () => globalThis.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;

export default class CamadaDeMotoboys {
    /** id do motoboy → { marcador, raiz, capacete, nome, situacao, destacado, de, para, inicio } */
    itens = new Map();
    quadro = null;
    ultimoQuadro = -Infinity;

    constructor(
        map,
        {
            L = globalThis.L,
            reduzirMovimento = reduzirMovimentoDoSistema,
            agora = () => performance.now(),
            pedirQuadro = (passo) => requestAnimationFrame(passo),
            cancelarQuadro = (id) => cancelAnimationFrame(id),
            criarElemento = (tag) => document.createElement(tag),
        } = {}
    ) {
        this.map = map;
        this.L = L;
        this.reduzirMovimento = reduzirMovimento;
        this.agora = agora;
        this.pedirQuadro = pedirQuadro;
        this.cancelarQuadro = cancelarQuadro;
        this.criarElemento = criarElemento;
    }

    /**
     * Desenha a lista da última consulta: cria quem chegou, move quem já estava e tira quem saiu. `destaque` é o id do
     * motoboy do pedido aberto no detalhe (ou null).
     */
    atualizar(lista, { destaque = null } = {}) {
        if (!this.map || !this.L) {
            return;
        }

        const vistos = new Set();
        const agora = this.agora();

        for (const motoboy of motoboysValidos(lista)) {
            const destino = [Number(motoboy.latitude), Number(motoboy.longitude)];
            let item = this.itens.get(motoboy.id);

            vistos.add(motoboy.id);

            if (item) {
                this.moverPara(item, destino, agora);
            } else {
                item = this.criar(motoboy, destino);
                this.itens.set(motoboy.id, item);
            }

            if (item.situacao !== motoboy.situacao) {
                item.situacao = motoboy.situacao;
                item.capacete.src = capaceteDaSituacao(motoboy.situacao);
            }

            const nome = motoboy.nome ?? '';
            if (item.nome.textContent !== nome) {
                item.nome.textContent = nome;
            }

            this.destacar(item, motoboy.id === destaque);
        }

        for (const [id, item] of this.itens) {
            if (!vistos.has(id)) {
                item.marcador.remove();
                this.itens.delete(id);
            }
        }

        this.animar();
    }

    /** Tira os capacetes do mapa e para a animação (o mapa saiu da tela); depois disso, atualizar não desenha mais. */
    destruir() {
        if (this.quadro !== null) {
            this.cancelarQuadro(this.quadro);
            this.quadro = null;
        }

        for (const item of this.itens.values()) {
            item.marcador.remove();
        }

        this.itens.clear();
        this.map = null;
    }

    // o capacete de 36 px centrado na posição, como no mapa do console, e o nome num rótulo embaixo (CSS entregas-motoboy-*)
    criar(motoboy, destino) {
        const raiz = this.criarElemento('div');
        raiz.className = 'entregas-motoboy';

        const capacete = this.criarElemento('img');
        capacete.className = 'entregas-motoboy-capacete';
        capacete.alt = '';
        capacete.src = capaceteDaSituacao(motoboy.situacao);

        const nome = this.criarElemento('span');
        nome.className = 'entregas-motoboy-nome';
        nome.textContent = motoboy.nome ?? '';

        raiz.append(capacete, nome);

        const icon = this.L.divIcon({ html: raiz, className: 'entregas-motoboy-icone', iconSize: [36, 36], iconAnchor: [18, 18] });
        const marcador = this.L.marker(destino, { icon, interactive: false, keyboard: false });
        marcador.addTo(this.map);

        return { marcador, raiz, capacete, nome, situacao: motoboy.situacao, destacado: false, de: null, para: null, inicio: 0 };
    }

    destacar(item, destacado) {
        // a classe vai sempre (é idempotente); o z-index só muda quando o destaque muda
        item.raiz.classList.toggle('entregas-motoboy-destaque', destacado);

        if (item.destacado !== destacado) {
            item.destacado = destacado;
            item.marcador.setZIndexOffset(destacado ? Z_DESTAQUE : 0);
        }
    }

    moverPara(item, destino, agora) {
        // já está indo para lá: o deslize segue no tempo dele
        if (item.para && item.para[0] === destino[0] && item.para[1] === destino[1]) {
            return;
        }

        const atual = item.marcador.getLatLng();
        const de = [atual.lat, atual.lng];

        if (de[0] === destino[0] && de[1] === destino[1]) {
            item.para = null;
            return;
        }

        if (deveSaltar(de, destino, { reduzirMovimento: this.reduzirMovimento() })) {
            item.para = null;
            item.marcador.setLatLng(destino);
            return;
        }

        // posição nova no meio do deslize: recomeça do ponto onde o capacete está
        item.de = de;
        item.para = destino;
        item.inicio = agora;
    }

    animar() {
        if (this.quadro !== null || ![...this.itens.values()].some((item) => item.para)) {
            return;
        }

        const passo = (agora) => {
            this.quadro = null;

            if (!this.map) {
                return;
            }

            const desenhar = agora - this.ultimoQuadro >= QUADRO_MS;
            let andando = false;

            for (const item of this.itens.values()) {
                if (!item.para) {
                    continue;
                }

                const fracao = (agora - item.inicio) / DESLIZE_MS;

                if (fracao >= 1) {
                    item.marcador.setLatLng(item.para);
                    item.para = null;
                } else {
                    andando = true;
                    if (desenhar) {
                        item.marcador.setLatLng(interpolar(item.de, item.para, fracao));
                    }
                }
            }

            if (desenhar) {
                this.ultimoQuadro = agora;
            }

            if (andando) {
                this.quadro = this.pedirQuadro(passo);
            }
        };

        this.quadro = this.pedirQuadro(passo);
    }
}
