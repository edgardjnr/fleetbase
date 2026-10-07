import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import { chaveDaFase, chaveDaResposta, chaveDoMotivo, minutos, podeAbrir, segundosRestantes } from '../../../utils/distribuicao';

/**
 * Entregas: painel "Distribuição" no detalhe do pedido (oferta um a um aos motoboys; rota GET
 * int/v1/entregas/pedidos/{id}/distribuicao, só administradores: para os demais o painel nem aparece, nem carrega).
 * Não depende do `adhoc`: a atribuição da central e a troca pelo líder o desligam, e o histórico precisa continuar ali.
 * Carrega para todo pedido e só aparece quando houve distribuição (`distribuicao: true`) ou, no erro, em pedido aberto.
 * Mostra a fase, a oferta atual com o cronômetro, a fila calculada (só em ofertas: tempo até o cliente, encaixe, ≈ quando
 * a estimativa é em linha reta) e o histórico das ofertas. "Abrir a todos agora" (POST .../distribuicao/abrir) pula a
 * fila; o 409 (a distribuição já saiu de ofertas) mostra a mensagem do servidor e relê o painel.
 * Em fase ofertas, o cronômetro conta de segundo em segundo e o painel é relido a cada 5 s e quando a oferta vence (uma
 * vez por vencimento), menos com a aba oculta: a fila anda sem mudar o pedido. Com a distribuição desligada no servidor
 * (`ligada: false`), não relê nem oferece o "Abrir a todos agora": a fase fica parada em ofertas. O cronômetro compara o relógio do PC com
 * o `vence_em` do servidor: é só exibição (quem vence a oferta é o servidor). O laço vive na task `carregar`
 * (restartable), cancelada quando o pedido muda ou o componente sai da tela.
 */
export default class OrderDetailsDistribuicaoComponent extends Component {
    @service fetch;
    @service currentUser;
    @service intl;
    @service notifications;
    @service modalsManager;
    @tracked painel = null;
    @tracked erro = false;
    @tracked agora = Date.now();
    // o pedido do último carregamento (não rastreado: só o `recarregar` lê e grava)
    ultimoId = null;

    // só administrador carrega: a rota do servidor é só de admin e os demais veriam só o aviso de erro
    get ehAdmin() {
        return this.currentUser.isAdmin === true;
    }

    // o painel só aparece com distribuição (inclusive encerrada, pelo histórico) ou, no erro, em pedido aberto
    get mostrar() {
        return this.temDistribuicao || (this.erro && this.args.resource?.adhoc === true);
    }

    get emOfertas() {
        return this.painel?.fase === 'ofertas';
    }

    get id() {
        return encodeURIComponent(this.args.resource?.public_id ?? this.args.resource?.id ?? '');
    }

    get temDistribuicao() {
        return this.painel?.distribuicao === true;
    }

    get faseTexto() {
        const fase = this.intl.t(`fleet-ops.ui.distribuicao.fase.${chaveDaFase(this.painel?.fase)}`);
        const motivo = chaveDoMotivo(this.painel?.motivo);

        return motivo ? `${fase} · ${this.intl.t(`fleet-ops.ui.distribuicao.motivo.${motivo}`)}` : fase;
    }

    get segundos() {
        return segundosRestantes(this.painel?.oferta?.vence_em, this.agora);
    }

    get fila() {
        return (this.painel?.fila ?? []).map((item, index) => ({
            posicao: index + 1,
            nome: item.nome || '—',
            tempo: this.tempoTexto(item.tempo_s),
            encaixe: item.encaixe === true,
            aproximado: item.aproximado === true,
            livre: item.livre === true,
        }));
    }

    get historico() {
        return (this.painel?.historico ?? []).map((oferta) => ({
            posicao: oferta.posicao,
            motoboy: oferta.motoboy || '—',
            tempo: this.tempoTexto(oferta.tempo_estimado_s),
            aproximado: oferta.aproximado === true,
            resposta: this.intl.t(`fleet-ops.ui.distribuicao.resposta.${chaveDaResposta(oferta.resposta)}`),
        }));
    }

    get podeAbrir() {
        return podeAbrir(this.painel, this.args.resource?.status);
    }

    abaOculta() {
        return typeof document !== 'undefined' && document.hidden === true;
    }

    tempoTexto(segundos) {
        const valor = minutos(segundos);

        return valor === null ? '—' : this.intl.t('fleet-ops.ui.distribuicao.minutos', { minutos: valor });
    }

    /**
     * O Glimmer reaproveita o componente quando o @resource muda: carrega na entrada e a cada mudança de id, status ou
     * atualização do pedido. Trocou de pedido: zera o painel antes, para não mostrar os dados do anterior.
     */
    @action recarregar() {
        const id = this.id;
        if (id !== this.ultimoId) {
            this.ultimoId = id;
            this.painel = null;
            this.erro = false;
        }
        if (this.ehAdmin) {
            this.carregar.perform();
        }
    }

    @task({ restartable: true }) *carregar() {
        try {
            this.painel = yield this.fetch.get(`entregas/pedidos/${this.id}/distribuicao`);
            this.erro = false;
        } catch (error) {
            this.erro = true;
            return;
        }
        this.agora = Date.now();
        // em ofertas: cronômetro a cada segundo; releitura a cada 5 s e no vencimento da oferta, com a aba visível
        let passos = 0;
        // o vence_em da oferta cujo vencimento já provocou uma releitura (uma vez por vencimento)
        let vencimentoRelido = null;
        while (this.painel?.fase === 'ofertas' && this.painel?.ligada !== false) {
            yield timeout(1000);
            this.agora = Date.now();
            passos += 1;
            const venceEm = this.painel?.oferta?.vence_em ?? null;
            const venceu = venceEm !== null && venceEm !== vencimentoRelido && this.segundos === 0;
            if (venceu) {
                vencimentoRelido = venceEm;
            }
            if ((passos >= 5 || venceu) && !this.abaOculta()) {
                passos = 0;
                try {
                    this.painel = yield this.fetch.get(`entregas/pedidos/${this.id}/distribuicao`);
                    this.agora = Date.now();
                } catch (error) {
                    // segue com o que tem; a próxima volta tenta de novo
                }
            }
        }
    }

    @action abrirATodos() {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.ui.distribuicao.abrir-titulo'),
            body: this.intl.t('fleet-ops.ui.distribuicao.abrir-texto'),
            acceptButtonText: this.intl.t('fleet-ops.ui.distribuicao.abrir'),
            acceptButtonIcon: 'bullhorn',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    const painel = await this.fetch.post(`entregas/pedidos/${this.id}/distribuicao/abrir`);
                    // a releitura em curso (fase ofertas) não pode sobrescrever o painel novo com o antigo
                    this.carregar.cancelAll();
                    this.painel = painel;
                    this.notifications.success(this.intl.t('fleet-ops.ui.distribuicao.aberto'));
                    modal.done();
                } catch (error) {
                    // 409: a distribuição já saiu de ofertas (aceite, motoboy definido); mostra o motivo e relê o painel
                    this.notifications.serverError(error);
                    modal.stopLoading();
                    this.carregar.perform();
                }
            },
        });
    }
}
