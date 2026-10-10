/**
 * Entregas: trajeto do motoboy que desce a landing junto com a rolagem (componente EntregasMotoboy).
 *
 * Funções puras: recebem a geometria da página (em px, já medida) e devolvem, para cada posição de rolagem,
 * qual trecho de vídeo mostrar, qual quadro, onde o motoboy fica na tela e para que lado está virado.
 *
 * Os trechos vêm dos vídeos gerados no Higgsfield (quadros em public/images/entregas/motoboy):
 * - partida: de frente para quem olha, vira e sai de lado (termina virado para a direita);
 * - pilotando: loop andando de lado, virado para a direita;
 * - retorno: meia-volta passando de frente (começa virado para a direita e termina para a esquerda);
 * - rampa: desce uma rampa, bico para baixo, e nivela (virado para a direita);
 * - entrega: para, tira a pizza da bag e oferece a quem olha (virado para a direita).
 * Virado para a esquerda = o mesmo quadro espelhado.
 *
 * Roteiro (poucas paradas, velocidade baixa e constante, para a pilotagem fluir):
 * - parte embaixo do painel de login, de frente, e vira para a esquerda;
 * - desce a rampa para o mapa e atravessa o mapa da direita para a esquerda;
 * - meia-volta e uma travessia longa, da esquerda para a direita, pelos passos (acendem na ordem) e telas;
 * - meia-volta e desce devagar pelas faixas até a chamada final, onde para e entrega o pedido.
 * A seção "passa" pelo motoboy quando cruza a linha dele, que fica no pé da tela.
 */

export const CLIPES = ['partida', 'pilotando', 'retorno', 'rampa', 'entrega'];

function limitar(valor, min, max) {
    return Math.min(max, Math.max(min, valor));
}

function suave(t) {
    return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
}

/**
 * @param {Object} geo
 * @param {number} geo.largura      largura útil da tela (px)
 * @param {number} geo.altura       altura da tela (px)
 * @param {number} geo.tamanho      lado do quadrado do motoboy (px)
 * @param {number} geo.margem       respiro lateral (px)
 * @param {number} geo.maximo       rolagem máxima (scrollHeight - altura)
 * @param {number} geo.inicio       rolagem em que ele aparece (o painel de login já passou da linha dele)
 * @param {Array<{nome: string, topo: number, base: number}>} geo.secoes  mapa, passos, telas e faixas (topo e base na página)
 * @param {{topo: number}} geo.final   seção da chamada final (topo na página)
 * @param {{x: number, y: number}} geo.alvo  onde ele para na chamada final (canto superior esquerdo, na tela, com a rolagem no máximo)
 * @return {{trechos: Array, linha: number, yBase: number, geo: Object}}
 */
export function montarTrajeto(geo) {
    const { largura, altura, tamanho, margem, maximo } = geo;
    const xEsquerda = margem;
    const xDireita = Math.max(margem, largura - tamanho - margem);
    const yBase = altura - tamanho - Math.round(margem * 0.5);
    const linha = yBase + tamanho * 0.5;
    const trechos = [];

    // Rolagem em que um ponto da página cruza a linha do motoboy.
    const cruza = (topo) => limitar(topo - linha, 0, maximo);
    const fimDa = (nome, padrao) => {
        const secao = geo.secoes.find((item) => item.nome === nome);
        return secao ? cruza(secao.base) : padrao;
    };

    let cursor = limitar(geo.inicio ?? 0, 0, maximo);
    let x = xDireita;
    let y = yBase;
    const trecho = (ate, clipe, espelho, x1, extra = {}) => {
        const fim = limitar(ate, cursor, maximo);
        trechos.push({ de: cursor, ate: fim, clipe, espelho, x0: x, x1, y0: y, y1: extra.y1 ?? y, curva: clipe === 'pilotando' ? 'linear' : 'suave', secao: extra.secao ?? null });
        cursor = fim;
        x = x1;
        y = extra.y1 ?? y;
    };

    trecho(cursor + Math.max(altura * 0.3, 160), 'partida', true, xDireita, { secao: 'topo' });

    // Desce para o mapa e atravessa da direita para a esquerda.
    const fimDoMapa = Math.max(cursor, fimDa('mapa', cursor + altura));
    trecho(cursor + Math.min(240, (fimDoMapa - cursor) * 0.22), 'rampa', true, limitar(x - largura * 0.04, xEsquerda, xDireita), { secao: 'mapa' });
    trecho(fimDoMapa, 'pilotando', true, xEsquerda, { secao: 'mapa' });

    // Meia-volta e a travessia longa pelos passos e telas.
    const fimDasTelas = Math.max(cursor, fimDa('telas', fimDa('passos', cursor + altura)));
    trecho(cursor + Math.min(260, (fimDasTelas - cursor) * 0.22), 'retorno', true, xEsquerda, { secao: 'passos' });
    trecho(fimDasTelas, 'pilotando', false, xDireita, { secao: 'passos' });

    // Meia-volta e a descida devagar pelas faixas até o alvo; sobe para a altura dele só na chamada final.
    const alvoX = limitar(geo.alvo.x, xEsquerda, xDireita);
    const alvoY = limitar(geo.alvo.y, margem, yBase);
    const restante = maximo - cursor;
    trecho(cursor + Math.min(260, restante * 0.2), 'retorno', false, xDireita, { secao: 'faixas' });
    const inicioDaEntrega = maximo - Math.min(360, (maximo - cursor) * 0.32);
    const chegaNoFinal = limitar(cruza(geo.final?.topo ?? maximo), cursor, inicioDaEntrega);
    const de = cursor;
    const xNoFinal = x + (alvoX - x) * ((chegaNoFinal - de) / Math.max(1, inicioDaEntrega - de));
    if (chegaNoFinal > de) {
        trecho(chegaNoFinal, 'pilotando', true, xNoFinal, { secao: 'faixas' });
    }
    trecho(inicioDaEntrega, 'pilotando', true, alvoX, { secao: 'final', y1: alvoY });
    trecho(maximo, 'entrega', true, alvoX, { secao: 'final' });

    return { trechos, linha, yBase, geo };
}

/**
 * Estado do motoboy numa rolagem.
 *
 * @param {Object} trajeto      resultado de montarTrajeto
 * @param {number} rolagem
 * @param {Object<string, number>} quadros  total de quadros por trecho
 * @param {number} [passoDoLoop]  px de rolagem por quadro do loop "pilotando"
 * @return {{visivel: boolean, clipe: string, quadro: number, x: number, y: number, espelho: boolean, progresso: number, secao: ?string, indice: number}}
 */
export function estadoNoScroll(trajeto, rolagem, quadros, passoDoLoop = 14) {
    const { trechos } = trajeto;
    const primeiro = trechos[0];

    if (rolagem < primeiro.de) {
        return { visivel: false, clipe: 'partida', quadro: 0, x: primeiro.x0, y: primeiro.y0, espelho: true, progresso: 0, secao: null, indice: -1 };
    }

    let indice = trechos.findIndex((trecho) => rolagem < trecho.ate);
    if (indice === -1) {
        indice = trechos.length - 1;
    }
    const trecho = trechos[indice];
    const duracao = trecho.ate - trecho.de;
    const progresso = duracao > 0 ? limitar((rolagem - trecho.de) / duracao, 0, 1) : 1;
    const tx = trecho.curva === 'linear' ? progresso : suave(progresso);
    const ty = suave(progresso);
    const total = Math.max(1, quadros[trecho.clipe] ?? 1);

    let quadro;
    if (trecho.clipe === 'pilotando') {
        // As rodas giram com a rolagem: o quadro 0 cai no início do trecho, que emenda com a rampa e a partida.
        quadro = Math.floor(Math.max(0, rolagem - trecho.de) / passoDoLoop) % total;
    } else {
        quadro = Math.round(progresso * (total - 1));
    }

    return {
        visivel: true,
        clipe: trecho.clipe,
        quadro,
        x: trecho.x0 + (trecho.x1 - trecho.x0) * tx,
        y: trecho.y0 + (trecho.y1 - trecho.y0) * ty,
        espelho: trecho.espelho,
        progresso,
        secao: trecho.secao ?? null,
        indice,
    };
}

/**
 * Rolagem em que um elemento (topo na página) cruza a linha do motoboy.
 */
export function rolagemQueCruza(topoNaPagina, trajeto) {
    return topoNaPagina - trajeto.linha;
}

/**
 * Passos lado a lado (desktop): o passo acende quando o motoboy passa por ele andando para a direita.
 * Empilhados (celular): acende quando cruza a linha dele.
 *
 * @param {Array<{centroX: number, topo: number}>} passos  centro na tela (x) e topo na página
 * @param {Object} estado   estadoNoScroll
 * @param {number} rolagem
 * @param {Object} trajeto
 * @return {boolean[]}
 */
export function passosVisitados(passos, estado, rolagem, trajeto) {
    if (!passos.length) {
        return [];
    }
    const emLinha = passos.every((passo) => Math.abs(passo.topo - passos[0].topo) < 8);
    if (!emLinha) {
        return passos.map((passo) => rolagem >= rolagemQueCruza(passo.topo, trajeto));
    }
    const trechos = trajeto.trechos.filter((trecho) => trecho.secao === 'passos');
    if (!trechos.length) {
        return passos.map((passo) => rolagem >= rolagemQueCruza(passo.topo, trajeto));
    }
    const comeco = trechos[0].de;
    const fim = trechos[trechos.length - 1].ate;
    if (rolagem < comeco) {
        return passos.map(() => false);
    }
    if (rolagem >= fim) {
        return passos.map(() => true);
    }
    const centro = estado.x + trajeto.geo.tamanho / 2;
    return passos.map((passo) => centro >= passo.centroX);
}
