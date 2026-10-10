import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { montarTrajeto, estadoNoScroll, passosVisitados, rolagemQueCruza, CLIPES } from '@fleetbase/console/utils/entregas-motoboy-trajeto';

/**
 * Entregas: o motoboy que desce a landing (auth/login) junto com a rolagem.
 *
 * Técnica da skill "video to website": vídeos do personagem (gerados no Higgsfield, fundo verde recortado)
 * viram quadros WebP com transparência em public/images/entregas/motoboy/<trecho>/NNN.webp, desenhados num
 * canvas conforme a rolagem. Rolagem suave pelo Lenis e gatilhos pelo GSAP ScrollTrigger, os dois servidos de
 * public/landing (fora do fingerprint e do bundle: só a landing carrega).
 *
 * Interações: o cartão "Oferta para você" é aceito quando ele chega, os passos acendem quando ele passa,
 * as três telas se marcam, a margem da tabela conta de zero e, na chamada final, ele entrega o pedido ao
 * lado do botão do WhatsApp.
 *
 * Com "reduzir animações" (no Windows, "Efeitos de animação" desligado já liga isso no Chrome) ele continua,
 * mas só anda quando a pessoa rola: sem Lenis, sem inclinação, sem as entradas das seções e sem a contagem.
 */
const BASE = '/images/entregas/motoboy';
const SCRIPTS = ['/landing/gsap.min.js', '/landing/ScrollTrigger.min.js', '/landing/lenis.min.js'];
const SECOES = [
    ['mapa', '.ent-mapa-secao'],
    ['passos', '.ent-passos-secao'],
    ['telas', '.ent-telas-secao'],
    ['faixas', '.ent-faixas-secao'],
];

function carregarScript(src) {
    return new Promise((resolve, reject) => {
        const existente = document.querySelector(`script[data-ent-landing="${src}"]`);
        if (existente?.dataset.pronto) {
            return resolve();
        }
        const script = existente ?? document.createElement('script');
        script.addEventListener('load', () => {
            script.dataset.pronto = '1';
            resolve();
        });
        script.addEventListener('error', reject);
        if (!existente) {
            script.src = src;
            script.async = false;
            script.dataset.entLanding = src;
            document.head.appendChild(script);
        }
    });
}

function limitar(valor, min, max) {
    return Math.min(max, Math.max(min, valor));
}

export default class EntregasMotoboyComponent extends Component {
    @service intl;

    quadros = {};
    totais = {};
    animacoes = [];
    marcas = new Map();
    rotacao = 0;
    rolagemVisual = null;
    ultimoDesenho = '';
    ultimaPosicao = '';
    ativo = false;

    @action async iniciar(elemento) {
        this.reduzido = Boolean(window.matchMedia?.('(prefers-reduced-motion: reduce)').matches);
        this.elemento = elemento;
        this.corpo = elemento.querySelector('.ent-motoboy-corpo');
        this.canvas = elemento.querySelector('.ent-motoboy-canvas');
        this.contexto = this.canvas.getContext('2d');
        this.landing = elemento.closest('.ent-landing');
        if (!this.landing || !this.contexto) {
            return;
        }
        this.ativo = true;

        try {
            // Os primeiros quadros (o que aparece no topo) vêm junto com os scripts, para ele aparecer logo; o resto
            // depois, sem disputar com a página.
            const quadrosDoTopo = fetch(`${BASE}/manifesto.json`, { cache: 'no-cache' })
                .then((resposta) => resposta.json())
                .then((manifesto) => {
                    this.manifesto = manifesto;
                    CLIPES.forEach((clipe) => (this.totais[clipe] = manifesto.clipes?.[clipe] ?? 0));
                    return Promise.all([this.carregarClipe('partida', 4), this.carregarClipe('pilotando', 2)]);
                });
            await Promise.all([quadrosDoTopo, ...SCRIPTS.map(carregarScript)]);
            if (!this.ativo) {
                return;
            }
            this.montar();
            Promise.all([this.carregarClipe('partida'), this.carregarClipe('pilotando')]).then(() => {
                ['retorno', 'rampa', 'entrega'].forEach((clipe) => this.carregarClipe(clipe));
            });
        } catch (erro) {
            // Sem os quadros ou os scripts a página segue como era, só sem o motoboy.
            this.encerrar();
        }
    }

    /**
     * Carrega os quadros de um trecho; com `primeiros`, só esses (e espera por eles).
     */
    carregarClipe(clipe, primeiros) {
        const total = this.totais[clipe];
        const lista = (this.quadros[clipe] ??= new Array(total).fill(null));
        const ate = primeiros ? Math.min(primeiros, total) : total;
        const versao = this.manifesto?.versao ?? 1;
        const promessas = [];
        for (let i = 0; i < ate; i++) {
            if (lista[i]) {
                continue;
            }
            const imagem = new Image();
            imagem.decoding = 'async';
            imagem.src = `${BASE}/${clipe}/${String(i + 1).padStart(3, '0')}.webp?v=${versao}`;
            promessas.push(
                imagem
                    .decode()
                    .then(() => {
                        lista[i] = imagem;
                        this.ultimoDesenho = '';
                    })
                    .catch(() => {})
            );
        }
        return Promise.all(promessas);
    }

    montar() {
        const { gsap, ScrollTrigger, Lenis } = window;
        gsap.registerPlugin(ScrollTrigger);

        if (!this.reduzido) {
            this.lenis = new Lenis({
                wrapper: this.landing,
                content: this.landing,
                duration: 1.2,
                easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
                smoothWheel: true,
                autoRaf: false,
            });
            this.lenis.on('scroll', ScrollTrigger.update);
            this.args.aoIniciar?.(this.lenis);
        }
        this.tique = (tempo) => {
            this.lenis?.raf(tempo * 1000);
            this.atualizar();
        };
        gsap.ticker.add(this.tique);
        gsap.ticker.lagSmoothing(0);

        this.formatoBRL = new Intl.NumberFormat(this.intl.primaryLocale ?? 'pt-BR', { style: 'currency', currency: 'BRL' });
        this.contadores = [];
        if (!this.reduzido) {
            this.prepararContadores();
            this.prepararEntradas();
        }
        this.medir();

        this.observador = new ResizeObserver(() => this.agendarMedida());
        this.observador.observe(this.landing);
        const hero = this.landing.querySelector('.ent-hero');
        if (hero) {
            this.observador.observe(hero);
        }
        document.fonts?.ready?.then(() => this.agendarMedida());
        this.elemento.classList.add('is-pronto');
    }

    agendarMedida() {
        cancelAnimationFrame(this.quadroDeMedida);
        this.quadroDeMedida = requestAnimationFrame(() => {
            if (this.ativo) {
                this.medir();
                window.ScrollTrigger?.refresh();
            }
        });
    }

    /**
     * Posição do elemento na página (relativa ao topo do .ent-landing), pela cadeia de offsets: ignora as
     * transformações das animações de entrada, que deslocam o getBoundingClientRect enquanto rodam.
     */
    caixaNaPagina(el) {
        let x = 0;
        let y = 0;
        let atual = el;
        while (atual && atual !== this.landing) {
            x += atual.offsetLeft;
            y += atual.offsetTop;
            atual = atual.offsetParent;
        }
        return { x, y, largura: el.offsetWidth, altura: el.offsetHeight };
    }

    topoNaPagina(el) {
        return this.caixaNaPagina(el).y;
    }

    medir() {
        const landing = this.landing;
        const largura = landing.clientWidth;
        const altura = landing.clientHeight;
        const tamanho = Math.round(limitar(largura * 0.14, 112, 210));
        const margem = Math.round(limitar(largura * 0.025, 12, 40));
        const maximo = Math.max(0, landing.scrollHeight - altura);
        const yBase = altura - tamanho - Math.round(margem * 0.5);

        // Ele aparece já com a página carregada, num canto livre. Se o painel de login ocupa o canto de baixo à
        // direita (telas baixas), fica ao lado do painel; sem espaço ao lado (celular), em cima do painel, à frente
        // dele, e desce até a linha dele enquanto o painel sobe.
        const painel = landing.querySelector('#entrar');
        let inicio = 0;
        let partida = null;
        this.naFrente = false;
        if (painel) {
            const caixa = this.caixaNaPagina(painel);
            const xDireita = largura - tamanho - margem;
            const cobre = caixa.y + caixa.altura > yBase + 8 && caixa.y < yBase + tamanho && caixa.x < xDireita + tamanho && caixa.x + caixa.largura > xDireita;
            const aoLado = caixa.x - 24 - tamanho;
            if (cobre && aoLado >= margem) {
                partida = { x: aoLado, y: yBase };
            } else if (cobre) {
                // Rodas no canto livre do painel, ao lado do título, para cobrir menos o texto do hero; se o
                // título chega até ali (tela estreita), na borda de cima do painel.
                const titulo = painel.querySelector('.ent-painel-titulo');
                let fimDoTitulo = 0;
                if (titulo) {
                    // O título é bloco (largura cheia): vale a largura do texto.
                    const texto = document.createRange();
                    texto.selectNodeContents(titulo);
                    fimDoTitulo = this.caixaNaPagina(titulo).x + Math.min(titulo.offsetWidth, texto.getBoundingClientRect().width);
                }
                const chao = fimDoTitulo + 8 <= xDireita + tamanho * 0.25 ? caixa.y + tamanho * 0.48 : caixa.y;
                partida = { x: xDireita, y: Math.max(margem, chao - tamanho * 0.97) };
                inicio = Math.max(0, caixa.y + caixa.altura - yBase + 8);
                this.naFrente = true;
            }
        }

        const secoes = SECOES.map(([nome, seletor]) => {
            const el = landing.querySelector(seletor);
            if (!el) {
                return null;
            }
            const topo = this.topoNaPagina(el);
            return { nome, topo, base: topo + el.offsetHeight };
        }).filter(Boolean);

        const final = landing.querySelector('.ent-final');
        const botao = final?.querySelector('.ent-botao');
        let alvo = { x: largura - tamanho - margem, y: yBase };
        if (botao) {
            const caixa = this.caixaNaPagina(botao);
            const esquerda = caixa.x;
            const direita = caixa.x + caixa.largura;
            const topo = caixa.y - maximo;
            // As rodas apoiam na divisa da chamada final com o rodapé. No desktop ele para no vão entre o título
            // e o texto; sem esse vão, ao lado do botão (o texto fica acima dele).
            const chao = final.offsetHeight + this.topoNaPagina(final) - maximo;
            const titulo = final.querySelector('.ent-final-titulo');
            const lado = final.querySelector('.ent-final-lado');
            const fimDoTitulo = titulo ? this.caixaNaPagina(titulo).x + titulo.offsetWidth : 0;
            const inicioDoLado = lado ? this.caixaNaPagina(lado).x : 0;
            if (lado && titulo && inicioDoLado - 32 - tamanho >= fimDoTitulo + 16 && this.caixaNaPagina(lado).y < this.caixaNaPagina(titulo).y + titulo.offsetHeight) {
                alvo = { x: inicioDoLado - 32 - tamanho, y: chao - tamanho * 0.97 };
            } else if (direita + 24 + tamanho <= largura - margem) {
                alvo = { x: direita + 24, y: Math.max(topo - tamanho * 0.5, chao - tamanho * 0.97) };
            } else {
                alvo = { x: Math.max(margem, esquerda), y: topo - tamanho - 8 };
            }
        }

        this.trajeto = montarTrajeto({ largura, altura, tamanho, margem, maximo, inicio, partida, secoes, final: { topo: final ? this.topoNaPagina(final) : maximo }, alvo });
        this.tamanho = tamanho;

        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const lado = Math.round(tamanho * dpr);
        if (this.canvas.width !== lado) {
            this.canvas.width = lado;
            this.canvas.height = lado;
        }
        this.corpo.style.width = `${tamanho}px`;
        this.corpo.style.height = `${tamanho}px`;

        const passos = [...landing.querySelectorAll('.ent-passo-num')];
        this.passos = passos.map((el) => {
            const caixa = this.caixaNaPagina(el);
            return { el: el.closest('.ent-passo'), centroX: caixa.x + caixa.largura / 2, topo: caixa.y };
        });
        this.telas = [...landing.querySelectorAll('.ent-tela')].map((el) => ({ el, topo: this.topoNaPagina(el) + 24 }));
        this.linhas = [...landing.querySelectorAll('.ent-tabela tbody tr')].map((el) => ({ el, topo: this.topoNaPagina(el) + el.offsetHeight / 2 }));
        const oferta = landing.querySelector('.ent-oferta');
        this.oferta = oferta ? { el: oferta, topo: this.topoNaPagina(oferta) + 40 } : null;
        this.botaoFinal = botao;

        this.ultimoDesenho = '';
        this.ultimaPosicao = '';
    }

    quadroDisponivel(clipe, indice) {
        const lista = this.quadros[clipe];
        if (!lista?.length) {
            return null;
        }
        if (lista[indice]) {
            return lista[indice];
        }
        // Ainda carregando: o mais próximo já pronto (para trás primeiro).
        for (let passo = 1; passo < lista.length; passo++) {
            const antes = lista[indice - passo];
            if (antes) {
                return antes;
            }
            const depois = lista[indice + passo];
            if (depois) {
                return depois;
            }
        }
        return null;
    }

    desenhar(clipe, quadro, espelho) {
        const chave = `${clipe}:${quadro}:${espelho}:${this.canvas.width}`;
        if (chave === this.ultimoDesenho) {
            return;
        }
        let imagem = this.quadroDisponivel(clipe, quadro);
        if (!imagem && clipe !== 'pilotando') {
            // Trecho ainda não carregado: o loop andando segura a cena.
            imagem = this.quadroDisponivel('pilotando', 0);
        }
        if (!imagem) {
            return;
        }
        const { width, height } = this.canvas;
        const ctx = this.contexto;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, width, height);
        if (espelho) {
            ctx.setTransform(-1, 0, 0, 1, width, 0);
        }
        ctx.drawImage(imagem, 0, 0, width, height);
        this.ultimoDesenho = chave;
    }

    marcar(el, classe, ligado) {
        if (!el) {
            return;
        }
        const chave = `${classe}`;
        let estado = this.marcas.get(el);
        if (!estado) {
            estado = {};
            this.marcas.set(el, estado);
        }
        if (estado[chave] !== ligado) {
            estado[chave] = ligado;
            el.classList.toggle(classe, ligado);
        }
    }

    atualizar() {
        if (!this.trajeto) {
            return;
        }
        // O motoboy persegue a rolagem com amortecimento: um clique da roda (≈100 px) vira um deslize, não um salto.
        const real = this.landing.scrollTop;
        const anterior = this.rolagemVisual ?? real;
        let rolagem = anterior + (real - anterior) * (this.lenis ? 0.16 : 0.085);
        if (Math.abs(real - rolagem) < 0.5) {
            rolagem = real;
        }
        this.rolagemVisual = rolagem;
        const estado = estadoNoScroll(this.trajeto, rolagem, this.totais);

        this.elemento.classList.toggle('is-visivel', estado.visivel);
        this.elemento.classList.toggle('is-na-frente', this.naFrente && estado.secao === 'topo');
        this.desenhar(estado.clipe, estado.quadro, estado.espelho);

        // Inclina na descida: bico para baixo quando a página desce, na direção em que ele está virado.
        const velocidade = rolagem - anterior;
        const alvoRotacao = estado.clipe === 'pilotando' && !this.reduzido ? limitar(velocidade * 0.3, -5, 5) * (estado.espelho ? -1 : 1) : 0;
        this.rotacao += (alvoRotacao - this.rotacao) * 0.12;
        const posicao = `translate3d(${estado.x.toFixed(1)}px, ${estado.y.toFixed(1)}px, 0) rotate(${this.rotacao.toFixed(2)}deg)`;
        if (posicao !== this.ultimaPosicao) {
            this.corpo.style.transform = posicao;
            this.ultimaPosicao = posicao;
        }

        this.reagir(estado, rolagem);
    }

    reagir(estado, rolagem) {
        const trajeto = this.trajeto;
        const cruzou = (topo) => rolagem >= rolagemQueCruza(topo, trajeto);

        if (this.oferta) {
            this.marcar(this.oferta.el, 'is-aceita', cruzou(this.oferta.topo));
        }
        passosVisitados(this.passos, estado, rolagem, trajeto).forEach((visitado, i) => this.marcar(this.passos[i].el, 'is-visitado', visitado));
        this.telas.forEach((tela) => this.marcar(tela.el, 'is-visitada', cruzou(tela.topo)));
        this.linhas.forEach((linha) => this.contar(linha, cruzou(linha.topo)));
        this.marcar(this.botaoFinal, 'is-entregue', estado.clipe === 'entrega' && estado.progresso > 0.85);
    }

    // A margem de cada faixa conta de zero quando a linha passa pelo motoboy (e volta a zero se ele voltar).
    prepararContadores() {
        this.contadores = [...this.landing.querySelectorAll('.ent-tabela td[data-valor]')].map((el) => {
            const contador = { el, valor: Number(el.dataset.valor) || 0, texto: el.textContent, atual: 0, ligado: false };
            el.textContent = this.formatoBRL.format(0);
            return contador;
        });
    }

    contar(linha, ligado) {
        const contadores = this.contadores.filter((contador) => linha.el.contains(contador.el));
        contadores.forEach((contador) => {
            if (contador.ligado === ligado) {
                return;
            }
            contador.ligado = ligado;
            contador.tween?.kill();
            if (!ligado) {
                contador.atual = 0;
                contador.el.textContent = this.formatoBRL.format(0);
                return;
            }
            contador.tween = window.gsap.to(contador, {
                atual: contador.valor,
                duration: 1.1,
                ease: 'power2.out',
                onUpdate: () => (contador.el.textContent = this.formatoBRL.format(contador.atual)),
                onComplete: () => (contador.el.textContent = contador.texto),
            });
        });
    }

    // Entradas das seções, uma de cada tipo (da skill): da esquerda, de baixo em sequência, da direita, recorte, escala.
    prepararEntradas() {
        const { gsap } = window;
        const scroller = this.landing;
        const entrada = (seletor, de, extra = {}) => {
            const alvos = this.landing.querySelectorAll(seletor);
            if (!alvos.length) {
                return;
            }
            this.animacoes.push(
                gsap.from(alvos, {
                    ...de,
                    stagger: 0.12,
                    duration: 0.9,
                    ease: 'power3.out',
                    ...extra,
                    scrollTrigger: { trigger: alvos[0], scroller, start: 'top 85%', toggleActions: 'play none none reverse' },
                })
            );
        };
        entrada('.ent-mapa-cabeca > *', { x: -70, opacity: 0 });
        entrada('.ent-legenda li', { y: 30, opacity: 0 });
        entrada('.ent-passos-secao .ent-h2', { clipPath: 'inset(100% 0 0 0)', y: 20 }, { duration: 1.1, ease: 'power4.inOut' });
        entrada('.ent-passo', { y: 60, opacity: 0 }, { stagger: 0.15, duration: 0.8 });
        entrada('.ent-telas-titulo', { y: 40, rotation: 3, opacity: 0 });
        entrada('.ent-tela', { x: 80, opacity: 0 }, { stagger: 0.14 });
        entrada('.ent-faixas-texto > *', { clipPath: 'inset(100% 0 0 0)', y: 24 }, { duration: 1.1, ease: 'power4.inOut', stagger: 0.15 });
        entrada('.ent-tabela tbody tr', { y: 24, opacity: 0 }, { stagger: 0.1, duration: 0.7 });
        entrada('.ent-final-titulo', { scale: 0.86, opacity: 0, transformOrigin: 'left bottom' }, { duration: 1, ease: 'power2.out' });
        entrada('.ent-final-lado > *', { x: 60, opacity: 0 }, { stagger: 0.14 });
    }

    @action encerrar() {
        this.ativo = false;
        cancelAnimationFrame(this.quadroDeMedida);
        this.observador?.disconnect();
        const { gsap } = window;
        if (gsap && this.tique) {
            gsap.ticker.remove(this.tique);
        }
        this.animacoes.forEach((animacao) => {
            animacao.scrollTrigger?.kill();
            animacao.revert?.();
        });
        this.animacoes = [];
        this.contadores?.forEach((contador) => {
            contador.tween?.kill();
            contador.el.textContent = contador.texto;
        });
        this.marcas.forEach((estado, el) => Object.keys(estado).forEach((classe) => el.classList.remove(classe)));
        this.marcas.clear();
        this.lenis?.destroy();
        this.lenis = null;
        this.args.aoIniciar?.(null);
        this.trajeto = null;
    }
}
