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
 * - aparece já com a página carregada, de frente, num canto livre (embaixo à direita; ao lado do painel de login,
 *   se o painel ocupa o canto; ou em cima do painel, no celular) e vira para a esquerda enquanto desce até a linha dele;
 * - desce a rampa para o mapa e atravessa o mapa da direita para a esquerda;
 * - meia-volta e uma travessia longa, da esquerda para a direita, pelos passos (acendem na ordem) e telas;
 * - meia-volta e desce devagar pelas faixas até a chamada final, onde para e entrega o pedido.
 * A seção "passa" pelo motoboy quando cruza a linha dele, que fica no pé da tela.
 *
 * Rolando de volta, ele faz o caminho inverso de frente, nunca de ré: o lado para onde está virado se inverte
 * (`voltando` no estadoNoScroll), as rodas giram para a frente, a rampa vira pilotagem subindo, as meias-voltas
 * tocam na ordem do vídeo e, ao trocar o sentido no meio do caminho, ele dá meia-volta (o componente toca o
 * trecho "retorno" pelo tempo, de `lado` em `lado`, com girar e quadroDoGiro).
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
 * @param {number} geo.inicio       rolagem em que ele chega à linha dele (o painel de login já passou dela)
 * @param {{x: number, y: number}} [geo.partida]  onde ele aparece com a rolagem em 0 (padrão: embaixo à direita)
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

    let cursor = 0;
    let x = limitar(geo.partida?.x ?? xDireita, xEsquerda, xDireita);
    let y = limitar(geo.partida?.y ?? yBase, margem, yBase);
    const trecho = (ate, clipe, espelho, x1, extra = {}) => {
        const fim = limitar(ate, cursor, maximo);
        trechos.push({ de: cursor, ate: fim, clipe, espelho, x0: x, x1, y0: y, y1: extra.y1 ?? y, curva: clipe === 'pilotando' ? 'linear' : 'suave', secao: extra.secao ?? null });
        cursor = fim;
        x = x1;
        y = extra.y1 ?? y;
    };

    // Vira de frente para o lado e desce até a linha dele quando o painel de login já saiu de baixo.
    const inicio = limitar(geo.inicio ?? 0, 0, maximo);
    trecho(Math.max(inicio, Math.max(altura * 0.3, 160)), 'partida', true, x, { secao: 'topo', y1: yBase });

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
 * `lado` diz para onde ele está virado: 0 = direita, 1 = esquerda e, no meio, a meia-volta (o quadro do trecho
 * "retorno"). `deLado` é falso quando o quadro é de frente para quem olha (começo da partida): aí o lado pode
 * trocar sem meia-volta.
 *
 * @param {Object} trajeto      resultado de montarTrajeto
 * @param {number} rolagem
 * @param {Object<string, number>} quadros  total de quadros por trecho
 * @param {number} [passoDoLoop]  px de rolagem por quadro do loop "pilotando"
 * @param {boolean} [voltando]  a pessoa está rolando para cima: ele faz o caminho inverso, virado para onde vai
 * @return {{visivel: boolean, clipe: string, quadro: number, x: number, y: number, espelho: boolean, lado: number, deLado: boolean, progresso: number, secao: ?string, indice: number}}
 */
export function estadoNoScroll(trajeto, rolagem, quadros, passoDoLoop = 14, voltando = false) {
    const { trechos } = trajeto;
    const primeiro = trechos[0];

    if (rolagem < primeiro.de) {
        return { visivel: false, clipe: 'partida', quadro: 0, x: primeiro.x0, y: primeiro.y0, espelho: true, lado: 1, deLado: false, progresso: 0, secao: null, indice: -1 };
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
    // Na volta, a rampa vira pilotagem (subindo, de frente): o vídeo da rampa ao contrário seria de ré.
    const clipe = voltando && trecho.clipe === 'rampa' ? 'pilotando' : trecho.clipe;
    const total = Math.max(1, quadros[clipe] ?? 1);

    let quadro;
    let espelho = trecho.espelho;
    let lado;
    let deLado = true;
    if (clipe === 'pilotando') {
        // As rodas giram com a rolagem: o quadro 0 cai no início do trecho, que emenda com a rampa e a partida.
        // Na volta, contam a partir do fim do trecho, para girarem para a frente enquanto a página sobe.
        const andado = voltando ? trecho.ate - rolagem : rolagem - trecho.de;
        quadro = Math.floor(Math.max(0, andado) / passoDoLoop) % total;
        espelho = voltando ? !trecho.espelho : trecho.espelho;
        lado = espelho ? 1 : 0;
    } else if (clipe === 'retorno') {
        // A meia-volta toca na ordem do vídeo nos dois sentidos: descendo, de um lado para o outro; subindo, de
        // volta, já virado para o caminho de volta (o lado inverso em cada ponta).
        const giro = trecho.espelho ? 1 - progresso : progresso;
        lado = voltando ? 1 - giro : giro;
        quadro = Math.round((trecho.espelho ? 1 - lado : lado) * (total - 1));
    } else {
        quadro = Math.round(progresso * (total - 1));
        if (clipe === 'partida') {
            // Na volta ele chega à partida virado para a direita e termina de frente.
            espelho = voltando ? !trecho.espelho : trecho.espelho;
            deLado = progresso >= 0.5;
        }
        lado = espelho ? 1 : 0;
    }

    return {
        visivel: true,
        clipe,
        quadro,
        x: trecho.x0 + (trecho.x1 - trecho.x0) * tx,
        y: trecho.y0 + (trecho.y1 - trecho.y0) * ty,
        espelho,
        lado,
        deLado,
        progresso,
        secao: trecho.secao ?? null,
        indice,
    };
}

/**
 * Sentido da rolagem, com folga: só troca depois de andar `folga` px no sentido novo, para um tremor da roda não
 * fazer a moto dar meia-volta.
 *
 * @param {{voltando: boolean, acumulado: number}} sentido
 * @param {number} delta   quanto a rolagem andou desde o último quadro (negativo = subindo)
 * @param {number} [folga]
 * @return {{voltando: boolean, acumulado: number}}
 */
export function atualizarSentido(sentido, delta, folga = 6) {
    if (!delta) {
        return sentido;
    }
    if ((delta < 0) === sentido.voltando) {
        return sentido.acumulado ? { voltando: sentido.voltando, acumulado: 0 } : sentido;
    }
    const acumulado = sentido.acumulado + Math.abs(delta);
    return acumulado >= folga ? { voltando: !sentido.voltando, acumulado: 0 } : { voltando: sentido.voltando, acumulado };
}

/**
 * Meia-volta pelo tempo: o lado mostrado anda até o lado pedido, no máximo `voltasPorSegundo` meias-voltas por segundo.
 */
export function girar(atual, alvo, segundos, voltasPorSegundo = 1.8) {
    const passo = Math.max(0, segundos) * voltasPorSegundo;
    return Math.abs(alvo - atual) <= passo ? alvo : atual + Math.sign(alvo - atual) * passo;
}

/**
 * Quadro do trecho "retorno" para um lado (0 = direita, 1 = esquerda). O vídeo vira da direita para a esquerda;
 * virando para a direita, o mesmo vídeo espelhado.
 */
export function quadroDoGiro(lado, paraEsquerda, total) {
    const ultimo = Math.max(1, total) - 1;
    return paraEsquerda ? { quadro: Math.round(lado * ultimo), espelho: false } : { quadro: Math.round((1 - lado) * ultimo), espelho: true };
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
