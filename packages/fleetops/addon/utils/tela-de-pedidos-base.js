import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import AoVivoDaCentral from './ao-vivo-da-central';
import { formatadores } from './financeiro';
import { cronometro } from './entregas-cronometro';
import { CAPACETES } from './entregas-capacete';
import { segundosRestantes } from './distribuicao';
import { situacaoDoPedido, motoboysParaAtribuir, avisoDaAtribuicao } from './painel-inicial';
import { progressoDoPrazo } from './tela-de-pedidos';

// Entregas: o pedido abre no mapa com a folha da comanda (sketch 008 A); `origem` é para onde volta o "Voltar"
const ROTA_DOS_PEDIDOS = 'console.fleet-ops.operations.orders.index';
// textos de fila, oferta e atribuir: os mesmos do painel inicial
const PI = 'fleet-ops.ui.painel-inicial';

/**
 * Entregas: base dos componentes da tela de pedidos (tela-pedidos/mapa e tela-pedidos/quadro). Cada um tem o seu
 * AoVivoDaCentral (só um aparece por vez) e a mesma linha de pedido: número, capacete, situação, oferta, prazo e os
 * candidatos do Atribuir (só admin).
 */
export default class TelaDePedidosBase extends Component {
    @service fetch;
    @service intl;
    @service currentUser;
    @service socket;
    @service hostRouter;
    @service notifications;

    @tracked atribuindo = null;

    capaceteLivre = CAPACETES.livre;

    constructor() {
        super(...arguments);
        this.aoVivo = new AoVivoDaCentral({ fetch: this.fetch, socket: this.socket, currentUser: this.currentUser });
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.aoVivo.parar();
    }

    get ehAdmin() {
        return this.currentUser.isAdmin === true;
    }

    get f() {
        return formatadores(this.intl.primaryLocale);
    }

    get agora() {
        return this.aoVivo.agora;
    }

    get textosDoCronometro() {
        return {
            faltam: (n) => this.intl.t('fleet-ops.ui.cronometro.faltam', { n }),
            atrasado: (n) => this.intl.t('fleet-ops.ui.cronometro.atrasado', { n }),
        };
    }

    linhaDoPedido(pedido) {
        const situacao = situacaoDoPedido(pedido);
        const prazo = situacao === 'oferta' ? null : cronometro(pedido.prazo_em, this.agora, this.textosDoCronometro);
        const aberto = this.atribuindo === pedido.id;
        return {
            ...pedido,
            situacao,
            ehOferta: situacao === 'oferta',
            numeroTexto: pedido.ifood ? `#${pedido.numero}` : pedido.numero,
            capacete: CAPACETES[situacao === 'oferta' ? 'offline' : situacao === 'rota' ? 'entrega' : 'coleta'],
            textoDaSituacao: situacao === 'oferta' ? this.intl.t(`${PI}.filtros.oferta`) : situacao === 'rota' ? this.intl.t(`${PI}.situacao.rota`) : this.intl.t(`${PI}.situacao.loja`),
            textoDaOferta: situacao === 'oferta' ? this.textoDaOferta(pedido) : '',
            prazo,
            atrasado: prazo?.estado === 'atrasado',
            progresso: progressoDoPrazo(pedido, this.agora),
            aberto,
            candidatos: aberto ? this.candidatos(pedido) : [],
        };
    }

    textoDaOferta(pedido) {
        const oferta = pedido.oferta;
        if (!oferta) return this.intl.t(`${PI}.oferta.aguardando`);
        if (oferta.fase === 'aberta' || !oferta.vence_em) return this.intl.t(`${PI}.oferta.aberta`);
        const segundos = segundosRestantes(oferta.vence_em, this.agora) ?? 0;
        const tempo = `${Math.floor(segundos / 60)}:${String(segundos % 60).padStart(2, '0')}`;
        return oferta.rodadas && oferta.rodada ? this.intl.t(`${PI}.oferta.rodada`, { rodada: oferta.rodada, tempo }) : this.intl.t(`${PI}.oferta.simples`, { tempo });
    }

    candidatos(pedido) {
        return motoboysParaAtribuir(this.aoVivo.motoboys, pedido.coleta)
            .filter((motoboy) => motoboy.id !== pedido.motoboy_id)
            .map((motoboy) => ({
                ...motoboy,
                kmTexto: motoboy.km === null ? this.intl.t(`${PI}.atribuir.sem-local`) : this.intl.t(`${PI}.atribuir.km`, { km: this.f.km(motoboy.km).replace(/,(\d)\d$/, ',$1') }),
                corridasTexto: this.intl.t('fleet-ops.ui.tela-pedidos.corridas-hoje', { n: motoboy.corridas_hoje ?? 0 }),
            }));
    }

    /** Para onde volta o "Voltar" do pedido aberto (o Quadro diz 'quadro'; o Mapa abre no próprio lugar). */
    origem = null;

    @action abrirPedido(id) {
        this.hostRouter.transitionTo(ROTA_DOS_PEDIDOS, { queryParams: { layout: 'map', pedido: id, de: this.origem } });
    }

    /** Cliques dentro da lista de candidatos não abrem o pedido (o cartão do Quadro é clicável). */
    @action pararClique(evento) {
        evento?.stopPropagation?.();
    }

    @action alternarAtribuir(id, evento) {
        evento?.stopPropagation?.();
        this.atribuindo = this.atribuindo === id ? null : id;
    }

    @action async enviar(pedidoId, motoboyId, evento) {
        evento?.stopPropagation?.();
        if (this.aoVivo.enviando) return;
        try {
            const resposta = await this.aoVivo.atribuir(pedidoId, motoboyId);
            this.atribuindo = null;
            const aviso = avisoDaAtribuicao(resposta);
            this.notifications.success(this.intl.t(`${PI}.${aviso.chave}`, { nome: aviso.nome }));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
