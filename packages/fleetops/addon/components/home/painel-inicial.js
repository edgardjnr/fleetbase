import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import escutarCanalDaEmpresa from '../../utils/escutar-canal-da-empresa';
import { formatadores, itemDeAtencao } from '../../utils/financeiro';
import { cronometro } from '../../utils/entregas-cronometro';
import { CAPACETES } from '../../utils/entregas-capacete';
import { segundosRestantes } from '../../utils/distribuicao';
import {
    PERIODOS,
    FILTROS,
    periodoDoPainel,
    saudacao,
    situacaoDoPedido,
    contagens,
    filaDoPainel,
    resumoDosMotoboys,
    motoboysParaAtribuir,
    barrasDoGrafico,
    mostrarAlerta,
} from '../../utils/painel-inicial';

const INTERVALO_AO_VIVO_MS = 20000;
const INTERVALO_RESUMO_MS = 5 * 60000;
const ESPERA_DO_SOCKET_MS = 800;
const EVENTOS_AO_VIVO = /^(order\.|waypoint\.|entity\.|driver\.(created|updated|deleted)$|entregas\.motoboy_online$)/;
const ROTA_DO_PEDIDO = 'console.fleet-ops.operations.orders.index.details';
const P = 'fleet-ops.ui.painel-inicial';

/**
 * Entregas: painel inicial do console (rota console.home; registrado em console:home:before-dashboard pelo extension.js).
 * Variante A do sketch 004: números do período, Ao vivo (fila + mapa), corridas por hora/dia, lojas, motoboys e Atenção.
 * Dados:
 * - GET entregas/painel-inicial (ao abrir, ao trocar o período e a cada 5 min);
 * - GET entregas/painel-inicial/ao-vivo (a cada 20 s e 0,8 s depois dos eventos do socket);
 * - GET entregas/mapa/locais-de-coleta (uma vez).
 * Com a aba escondida, nada é consultado.
 * Roda no contexto do console (owner do host): o roteador é o `router`, com o nome completo da rota.
 */
export default class HomePainelInicialComponent extends Component {
    @service fetch;
    @service intl;
    @service currentUser;
    @service socket;
    @service router;
    @service notifications;

    @tracked periodo = 'hoje';
    @tracked filtro = 'todos';
    @tracked resumo = null;
    @tracked aoVivo = { pedidos: [], motoboys: [] };
    @tracked lojasDoMapa = [];
    @tracked atribuindo = null;
    @tracked agora = Date.now();

    capaceteLivre = CAPACETES.livre;
    esqueleto = [1, 2, 3, 4, 5, 6];

    constructor() {
        super(...arguments);
        this.carregarResumo.perform();
        this.carregarAoVivo.perform();
        this.carregarLojas.perform();
        this._intervaloAoVivo = setInterval(() => this.#seVisivel(() => this.carregarAoVivo.perform()), INTERVALO_AO_VIVO_MS);
        this._intervaloResumo = setInterval(() => this.#seVisivel(() => this.carregarResumo.perform()), INTERVALO_RESUMO_MS);
        // 1 s: o relógio das ofertas e do cronômetro (só troca o tracked; nada vai ao servidor)
        this._tique = setInterval(() => this.#seVisivel(() => (this.agora = Date.now())), 1000);
        this._escuta = escutarCanalDaEmpresa({
            socket: this.socket,
            currentUser: this.currentUser,
            aoReceber: (mensagem) => {
                if (EVENTOS_AO_VIVO.test(String(mensagem?.event ?? ''))) this.#agendarAoVivo();
            },
        });
    }

    willDestroy() {
        super.willDestroy(...arguments);
        clearInterval(this._intervaloAoVivo);
        clearInterval(this._intervaloResumo);
        clearInterval(this._tique);
        clearTimeout(this._espera);
        this._escuta?.parar();
    }

    #seVisivel(fazer) {
        if (typeof document === 'undefined' || !document.hidden) fazer();
    }

    #agendarAoVivo() {
        clearTimeout(this._espera);
        this._espera = setTimeout(() => this.#seVisivel(() => this.carregarAoVivo.perform()), ESPERA_DO_SOCKET_MS);
    }

    get f() {
        return formatadores(this.intl.primaryLocale);
    }

    get ehAdmin() {
        return this.currentUser.isAdmin === true;
    }

    /* ---------- cabeçalho ---------- */

    get saudacao() {
        const textos = { 'bom-dia': `${P}.saudacao.bom-dia`, 'boa-tarde': `${P}.saudacao.boa-tarde`, 'boa-noite': `${P}.saudacao.boa-noite` };
        const texto = this.intl.t(textos[saudacao(new Date(this.agora).getHours())]);
        const nome = String(this.currentUser.name ?? '').trim().split(/\s+/)[0];
        return nome ? `${texto}, ${nome}` : texto;
    }

    get dataDeHoje() {
        const texto = new Intl.DateTimeFormat(this.intl.primaryLocale, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'America/Sao_Paulo' }).format(this.agora);
        const hora = new Intl.DateTimeFormat(this.intl.primaryLocale, { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' }).format(this.agora);
        return `${texto.charAt(0).toUpperCase()}${texto.slice(1)} · ${hora}`;
    }

    get periodos() {
        const textos = { hoje: this.intl.t(`${P}.periodo.hoje`), semana: this.intl.t(`${P}.periodo.semana`), mes: this.intl.t(`${P}.periodo.mes`) };
        return PERIODOS.map((chave) => ({ chave, texto: textos[chave], ativo: chave === this.periodo }));
    }

    /* ---------- cartões ---------- */

    get cartoes() {
        const r = this.resumo;
        if (!r) return [];
        const f = this.f;
        const t = (chave, opcoes) => this.intl.t(`${P}.cartoes.${chave}`, opcoes);
        const bases = { hoje: t('base-hoje'), semana: t('base-semana'), mes: t('base-mes') };
        const lista = [
            {
                chave: 'corridas',
                icone: 'motorcycle',
                rotulo: t('corridas'),
                valor: String(r.corridas),
                detalhe: r.variacao === null ? t('sem-comparacao') : t('variacao', { variacao: `${r.variacao > 0 ? '+' : ''}${r.variacao}`, base: bases[this.periodo] }),
                destaque: true,
            },
        ];
        if (this.ehAdmin) {
            lista.push(
                { chave: 'faturado', icone: 'store', rotulo: t('faturado'), valor: r.faturado === null ? '—' : f.valor(r.faturado), detalhe: r.faturado && r.corridas ? t('por-corrida', { valor: f.valor(r.faturado / r.corridas) }) : '' },
                { chave: 'a-pagar', icone: 'sack-dollar', rotulo: t('a-pagar'), valor: r.a_pagar === null ? '—' : f.valor(r.a_pagar), detalhe: r.km === null ? '' : t('km', { km: f.km(r.km) }) },
                { chave: 'margem', icone: 'chart-line', rotulo: t('margem'), valor: r.margem === null ? '—' : f.valor(r.margem), detalhe: r.margem !== null && r.faturado ? t('do-faturado', { pct: f.km((r.margem / r.faturado) * 100).replace(/,(\d)\d$/, ',$1') }) : '' }
            );
        }
        lista.push(
            { chave: 'no-prazo', icone: 'clock', rotulo: t('no-prazo'), valor: r.no_prazo === null ? '—' : `${r.no_prazo}%`, detalhe: t('meta'), alerta: r.no_prazo !== null && r.no_prazo < 90 },
            { chave: 'tempo-medio', icone: 'stopwatch', rotulo: t('tempo-medio'), valor: r.tempo_medio_min === null ? '—' : t('minutos', { n: r.tempo_medio_min }), detalhe: t('do-aceite') }
        );
        return lista;
    }

    /* ---------- ao vivo ---------- */

    get contagem() {
        return contagens(this.aoVivo.pedidos, this.agora);
    }

    get motoboysOnline() {
        return resumoDosMotoboys(this.aoVivo.motoboys);
    }

    get textoOnline() {
        return this.intl.t(`${P}.ao-vivo.online`, this.motoboysOnline);
    }

    get alerta() {
        return mostrarAlerta(this.contagem);
    }

    get textoDoAlerta() {
        return {
            titulo: this.intl.t(`${P}.alerta.titulo`, { oferta: this.contagem.oferta, atrasado: this.contagem.atrasado }),
            texto: this.intl.t(`${P}.alerta.texto`, { livres: this.motoboysOnline.livres }),
        };
    }

    get filtros() {
        const c = this.contagem;
        const textos = {
            todos: this.intl.t(`${P}.filtros.todos`),
            oferta: this.intl.t(`${P}.filtros.oferta`),
            loja: this.intl.t(`${P}.filtros.loja`),
            rota: this.intl.t(`${P}.filtros.rota`),
            atrasado: this.intl.t(`${P}.filtros.atrasado`),
        };
        return FILTROS.map((chave) => ({ chave, texto: textos[chave], n: c[chave], ativo: this.filtro === chave, alerta: (chave === 'oferta' || chave === 'atrasado') && c[chave] > 0 }));
    }

    get textoDoVazio() {
        const textos = {
            todos: this.intl.t(`${P}.vazio.todos`),
            oferta: this.intl.t(`${P}.vazio.oferta`),
            loja: this.intl.t(`${P}.vazio.loja`),
            rota: this.intl.t(`${P}.vazio.rota`),
            atrasado: this.intl.t(`${P}.vazio.atrasado`),
        };
        return textos[this.filtro];
    }

    get textosDoCronometro() {
        return {
            faltam: (n) => this.intl.t('fleet-ops.ui.cronometro.faltam', { n }),
            atrasado: (n) => this.intl.t('fleet-ops.ui.cronometro.atrasado', { n }),
        };
    }

    get pedidos() {
        return filaDoPainel(this.aoVivo.pedidos, this.filtro, this.agora).map((pedido) => this.#linha(pedido));
    }

    #linha(pedido) {
        const situacao = situacaoDoPedido(pedido);
        const prazo = situacao === 'oferta' ? null : cronometro(pedido.prazo_em, this.agora, this.textosDoCronometro);
        const aberto = this.atribuindo === pedido.id;
        return {
            ...pedido,
            situacao,
            ehOferta: situacao === 'oferta',
            numeroTexto: pedido.ifood ? `#${pedido.numero}` : pedido.numero,
            capacete: CAPACETES[situacao === 'oferta' ? 'offline' : situacao === 'rota' ? 'entrega' : 'coleta'],
            textoDaSituacao: situacao === 'rota' ? this.intl.t(`${P}.situacao.rota`) : this.intl.t(`${P}.situacao.loja`),
            textoDaOferta: situacao === 'oferta' ? this.#textoDaOferta(pedido) : '',
            prazo,
            atrasado: prazo?.estado === 'atrasado',
            aberto,
            candidatos: aberto ? this.#candidatos(pedido) : [],
        };
    }

    #textoDaOferta(pedido) {
        const oferta = pedido.oferta;
        if (!oferta) return this.intl.t(`${P}.oferta.aguardando`);
        if (oferta.fase === 'aberta' || !oferta.vence_em) return this.intl.t(`${P}.oferta.aberta`);
        const segundos = segundosRestantes(oferta.vence_em, this.agora) ?? 0;
        const tempo = `${Math.floor(segundos / 60)}:${String(segundos % 60).padStart(2, '0')}`;
        return oferta.rodadas && oferta.rodada ? this.intl.t(`${P}.oferta.rodada`, { rodada: oferta.rodada, tempo }) : this.intl.t(`${P}.oferta.simples`, { tempo });
    }

    #candidatos(pedido) {
        return motoboysParaAtribuir(this.aoVivo.motoboys, pedido.coleta).map((motoboy) => ({
            ...motoboy,
            kmTexto: motoboy.km === null ? this.intl.t(`${P}.atribuir.sem-local`) : this.intl.t(`${P}.atribuir.km`, { km: this.f.km(motoboy.km).replace(/,(\d)\d$/, ',$1') }),
        }));
    }

    /* ---------- gráfico, lojas, motoboys, atenção ---------- */

    get tituloDoGrafico() {
        return this.resumo?.grafico?.tipo === 'dia' ? this.intl.t(`${P}.grafico.dia`) : this.intl.t(`${P}.grafico.hora`);
    }

    get barras() {
        return barrasDoGrafico(this.resumo?.grafico, { hoje: this.periodo === 'hoje', horaAtual: new Date(this.agora).getHours() }).map((barra) => ({
            ...barra,
            dica: this.intl.t(`${P}.grafico.dica`, { rotulo: barra.rotuloCurto, total: barra.total }),
        }));
    }

    get temMedia() {
        return this.barras.some((barra) => barra.alturaMedia !== null);
    }

    get lojas() {
        const lista = this.resumo?.lojas ?? [];
        const maior = Math.max(1, ...lista.map((loja) => loja.corridas));
        const textos = { vinculada: this.intl.t(`${P}.lojas.ifood-vinculada`), vinculo_perdido: this.intl.t(`${P}.lojas.ifood-perdido`) };
        return lista.map((loja) => ({
            ...loja,
            largura: Math.round((loja.corridas / maior) * 100),
            ifoodTexto: textos[loja.ifood] ?? this.intl.t(`${P}.lojas.sem-ifood`),
            ifoodClasse: loja.ifood === 'vinculada' ? 'pi-ifood-ok' : loja.ifood === 'vinculo_perdido' ? 'pi-ifood-erro' : 'pi-ifood-nao',
            faturadoTexto: loja.faturado === null ? '' : this.f.valor(loja.faturado),
        }));
    }

    get motoboysDoPeriodo() {
        return (this.resumo?.motoboys ?? []).map((motoboy, indice) => ({ ...motoboy, posicao: indice + 1, ganhosTexto: motoboy.ganhos === null ? '' : this.f.valor(motoboy.ganhos) }));
    }

    get atencao() {
        return (this.resumo?.atencao ?? [])
            .map((item) => itemDeAtencao(item, this.f))
            .filter(Boolean)
            .map((item) => ({ ...item, texto: this.intl.t(item.chave, item.params) }));
    }

    get textoDosItens() {
        return this.intl.t(`${P}.atencao.itens`, { n: this.atencao.length });
    }

    /* ---------- ações ---------- */

    @action escolherPeriodo(chave) {
        if (this.periodo === chave) return;
        this.periodo = chave;
        this.carregarResumo.perform();
    }

    @action escolherFiltro(chave) {
        this.filtro = this.filtro === chave && chave !== 'todos' ? 'todos' : chave;
    }

    @action abrirPedido(id) {
        this.router.transitionTo(ROTA_DO_PEDIDO, id);
    }

    @action novoPedido() {
        this.router.transitionTo('console.fleet-ops.operations.orders.index.new');
    }

    @action abrirOperacoes() {
        this.router.transitionTo('console.fleet-ops.operations.orders.index', { queryParams: { layout: 'map' } });
    }

    @action abrirFinanceiro() {
        this.router.transitionTo('console.fleet-ops.financeiro.painel');
    }

    @action abrirLojas() {
        this.router.transitionTo('console.fleet-ops.management.lojas');
    }

    @action abrirMotoboys() {
        this.router.transitionTo('console.fleet-ops.financeiro.motoboys');
    }

    @action abrirAtencao(item) {
        if (item?.rota && item?.modelo) this.router.transitionTo(`console.fleet-ops.${item.rota}`, item.modelo);
    }

    @action alternarAtribuir(id, evento) {
        evento?.stopPropagation?.();
        this.atribuindo = this.atribuindo === id ? null : id;
    }

    @action enviar(pedidoId, motoboyId, evento) {
        evento?.stopPropagation?.();
        this.atribuir.perform(pedidoId, motoboyId);
    }

    /* ---------- dados ---------- */

    @task({ restartable: true }) *carregarResumo() {
        const { inicio, fim } = periodoDoPainel(this.periodo, new Date());
        try {
            this.resumo = yield this.fetch.get('entregas/painel-inicial', { inicio, fim });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ restartable: true }) *carregarAoVivo() {
        try {
            const resposta = yield this.fetch.get('entregas/painel-inicial/ao-vivo');
            this.aoVivo = { pedidos: resposta?.pedidos ?? [], motoboys: resposta?.motoboys ?? [] };
        } catch {
            // silencioso: o próximo ciclo (20 s) ou o próximo evento do socket tenta de novo
        }
    }

    @task *carregarLojas() {
        try {
            this.lojasDoMapa = yield this.fetch.get('entregas/mapa/locais-de-coleta', {}, { normalizeToEmberData: true, normalizeModelType: 'place' });
        } catch {
            this.lojasDoMapa = [];
        }
    }

    @task({ drop: true }) *atribuir(pedidoId, motoboyId) {
        try {
            yield this.fetch.post(`entregas/painel-inicial/pedidos/${pedidoId}/motoboy`, { motoboy: motoboyId });
            this.atribuindo = null;
            this.notifications.success(this.intl.t(`${P}.atribuir.enviado`));
            yield this.carregarAoVivo.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
