import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import {
    agruparPorVolta,
    chaveDaFase,
    chaveDaResposta,
    chaveDoMotivo,
    emRodadas,
    horaCurta,
    inteiroPositivo,
    kmTexto,
    minutos,
    podeAbrir,
    segundosRestantes,
    textosDoBotao,
} from '../../../utils/distribuicao';

/**
 * Entregas: painel "Distribuição" no detalhe do pedido (oferta um a um aos motoboys; rota GET
 * int/v1/entregas/pedidos/{id}/distribuicao, só administradores: para os demais o painel nem aparece, nem carrega).
 * Não depende do `adhoc`: a atribuição da central e a troca pelo líder o desligam, e o histórico precisa continuar ali.
 * Carrega para todo pedido e só aparece quando houve distribuição (`distribuicao: true`) ou, no erro, em pedido aberto.
 * Mostra a fase, a oferta atual com o cronômetro, a fila calculada (só em ofertas: tempo até o cliente, "no caminho" quando
 * o pedido novo encaixa na sequência do motoboy, ≈ quando a estimativa é em linha reta) e o histórico das ofertas.
 * Com rodadas (`rodadas: true`, ENTREGAS_DISTRIBUICAO_RODADAS): em ofertas, "Volta N · rodada M · até X km" e a lista
 * aberta ("Lista aberta desde HH:MM") ou fechada; o "no caminho" também aparece (encaixe com até 5 min de atraso para
 * quem já espera, em vez de 10 min); o histórico agrupado por volta, com a rodada e o raio em cada linha; e o botão
 * vira "Mostrar a todos agora" (grava a lista_aberta_em, sem alarme; só com a lista ainda fechada). Sem rodadas, "Abrir
 * a todos agora" pula a fila e manda o alarme a todos.
 * Nos dois, POST .../distribuicao/abrir; o 409 mostra a mensagem do servidor e relê o painel.
 * Em fase ofertas, o cronômetro conta de segundo em segundo e o painel é relido a cada 5 s e quando a oferta vence (uma
 * vez por vencimento), menos com a aba oculta: a fila anda sem mudar o pedido. Com a distribuição desligada no servidor
 * (`ligada: false`), não relê nem oferece o botão: a fase fica parada em ofertas. O cronômetro compara o relógio do PC com
 * o `vence_em` do servidor (30 s, ou 20 s com rodadas): é só exibição (quem vence a oferta é o servidor). O laço vive na
 * task `carregar` (restartable), cancelada quando o pedido muda ou o componente sai da tela.
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

    get emRodadas() {
        return emRodadas(this.painel);
    }

    get locale() {
        return this.intl.primaryLocale ?? 'pt-BR';
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

    // rodadas, em ofertas: "Volta 2 · rodada 1 · até 6 km" (sem o raio, só a volta e a rodada)
    get rodadaTexto() {
        if (!this.emRodadas || !this.emOfertas) {
            return null;
        }
        const dados = { volta: inteiroPositivo(this.painel?.volta), rodada: inteiroPositivo(this.painel?.rodada) };
        const km = kmTexto(this.painel?.raio_m, this.locale);

        return km
            ? this.intl.t('fleet-ops.ui.distribuicao.rodada-com-raio', { ...dados, km })
            : this.intl.t('fleet-ops.ui.distribuicao.rodada-sem-raio', dados);
    }

    // rodadas, em ofertas: a lista "Novos pedidos" aberta a todos (desde quando) ou só para quem recebe a oferta
    get listaTexto() {
        if (!this.emRodadas || !this.emOfertas) {
            return null;
        }
        if (!this.painel?.lista_aberta_em) {
            return this.intl.t('fleet-ops.ui.distribuicao.lista-fechada');
        }

        return this.intl.t('fleet-ops.ui.distribuicao.lista-aberta', { hora: horaCurta(this.painel.lista_aberta_em, this.locale) ?? '—' });
    }

    get segundos() {
        return segundosRestantes(this.painel?.oferta?.vence_em, this.agora);
    }

    get fila() {
        return (this.painel?.fila ?? []).map((item, index) => ({
            posicao: index + 1,
            nome: item.nome || '—',
            tempo: this.tempoTexto(item.tempo_s),
            // com e sem rodadas (o servidor só limita o atraso: 5 min em rodadas, 10 min sem)
            encaixe: item.encaixe === true,
            aproximado: item.aproximado === true,
            livre: item.livre === true,
        }));
    }

    // sem rodadas: a lista corrida de antes
    get historico() {
        return (this.painel?.historico ?? []).map((oferta) => this.linhaDoHistorico(oferta));
    }

    // com rodadas: "Volta N" e as ofertas dela, cada uma com a rodada e o raio
    get historicoPorVolta() {
        return agruparPorVolta(this.painel?.historico).map((grupo) => ({
            titulo: this.intl.t('fleet-ops.ui.distribuicao.volta', { volta: grupo.volta }),
            ofertas: grupo.ofertas.map((oferta) => this.linhaDoHistorico(oferta)),
        }));
    }

    get podeAbrir() {
        return podeAbrir(this.painel, this.args.resource?.status);
    }

    get textosDoBotao() {
        return textosDoBotao(this.painel);
    }

    get botaoTexto() {
        return this.intl.t(`fleet-ops.ui.distribuicao.${this.textosDoBotao.botao}`);
    }

    abaOculta() {
        return typeof document !== 'undefined' && document.hidden === true;
    }

    tempoTexto(segundos) {
        const valor = minutos(segundos);

        return valor === null ? '—' : this.intl.t('fleet-ops.ui.distribuicao.minutos', { minutos: valor });
    }

    linhaDoHistorico(oferta) {
        return {
            // as linhas `dispensada`/`aceita_pela_lista` (rodadas) vêm com posicao 0: sem posição na tela
            posicao: Number(oferta.posicao) > 0 ? oferta.posicao : null,
            motoboy: oferta.motoboy || '—',
            tempo: this.tempoTexto(oferta.tempo_estimado_s),
            aproximado: oferta.aproximado === true,
            resposta: this.intl.t(`fleet-ops.ui.distribuicao.resposta.${chaveDaResposta(oferta.resposta)}`),
            rodada: this.rodadaDaLinha(oferta),
        };
    }

    // rodadas: "rodada 2 · até 9 km" em cada linha do histórico (null sem rodadas)
    rodadaDaLinha(oferta) {
        if (!this.emRodadas) {
            return null;
        }
        const rodada = inteiroPositivo(oferta?.rodada);
        const km = kmTexto(oferta?.raio_m, this.locale);

        return km
            ? this.intl.t('fleet-ops.ui.distribuicao.historico-rodada-com-raio', { rodada, km })
            : this.intl.t('fleet-ops.ui.distribuicao.historico-rodada-sem-raio', { rodada });
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

    // "Abrir a todos agora" (sem rodadas) ou "Mostrar a todos agora" (rodadas): os textos são lidos no clique
    @action abrirATodos() {
        const textos = textosDoBotao(this.painel);
        this.modalsManager.confirm({
            title: this.intl.t(`fleet-ops.ui.distribuicao.${textos.titulo}`),
            body: this.intl.t(`fleet-ops.ui.distribuicao.${textos.texto}`),
            acceptButtonText: this.intl.t(`fleet-ops.ui.distribuicao.${textos.botao}`),
            acceptButtonIcon: textos.icone,
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    const painel = await this.fetch.post(`entregas/pedidos/${this.id}/distribuicao/abrir`);
                    // a releitura em curso (fase ofertas) não pode sobrescrever o painel novo com o antigo
                    this.carregar.cancelAll();
                    this.painel = painel;
                    this.notifications.success(this.intl.t(`fleet-ops.ui.distribuicao.${textos.feito}`));
                    modal.done();
                    // com rodadas a fase continua em ofertas: o laço de releitura volta a correr
                    if (painel?.fase === 'ofertas') {
                        this.carregar.perform();
                    }
                } catch (error) {
                    // 409: a lista já está aberta ou a distribuição saiu de ofertas; mostra o motivo e relê o painel
                    this.notifications.serverError(error);
                    modal.stopLoading();
                    this.carregar.perform();
                }
            },
        });
    }
}
