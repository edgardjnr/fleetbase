import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { next } from '@ember/runloop';
import { task } from 'ember-concurrency';
import { quandoMostrarAChave, situacaoDaIntegracao, tempoDesde } from '../../utils/integracao-da-loja';

const ENDPOINT = 'entregas/lojas';

/**
 * Entregas RestaurantePro: bloco "Integração" do card da loja (Fleet-Ops → Lojas, só admin). Lê a situação em
 * GET int/v1/entregas/lojas/{id}/integracao (IntegracaoLojasController) e mostra:
 * - sem chave: "—" e Gerar chave (só com a integração ligada no servidor);
 * - chave gerada, sem conexão: "Chave …a1B2 gerada · aguardando o sistema conectar";
 * - conectada: "Conectada a RestaurantePro · último uso há 3 min" e a falha do último aviso, se houver.
 * Gerar chave (POST .../integracao/chave) mostra a chave uma vez, no modal modals/chave-da-integracao. Com uma chave
 * ativa, pede confirmação antes: a anterior para de funcionar. Revogar (DELETE .../integracao) também pede confirmação.
 */
export default class LojaIntegracaoComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;
    @tracked integracao = null;
    @tracked erro = false;

    get id() {
        return encodeURIComponent(this.args.loja?.id ?? '');
    }

    get situacao() {
        return situacaoDaIntegracao(this.integracao);
    }

    get podeGerar() {
        return this.integracao?.ligada === true;
    }

    get final() {
        return this.integracao?.chave_final ?? '';
    }

    get conectadaTexto() {
        const integracao = this.integracao;
        const sistema = integracao?.sistema ?? '—';
        const uso = this.textoDoTempo(integracao?.ultimo_uso_em);

        return uso ? this.intl.t('fleet-ops.ui.lojas.integracao.conectada-uso', { sistema, uso }) : this.intl.t('fleet-ops.ui.lojas.integracao.conectada', { sistema });
    }

    get falhaTexto() {
        const integracao = this.integracao;
        if (!integracao?.ultima_falha_aviso) {
            return null;
        }

        return this.intl.t('fleet-ops.ui.lojas.integracao.falha', {
            texto: integracao.ultima_falha_aviso,
            quando: this.textoDoTempo(integracao.ultima_falha_aviso_em) ?? '—',
        });
    }

    textoDoTempo(iso) {
        const tempo = tempoDesde(iso, Date.now());

        return tempo ? this.intl.t(`fleet-ops.ui.lojas.integracao.ha.${tempo.unidade}`, { n: tempo.n }) : null;
    }

    @action carregarAoAparecer() {
        this.carregar.perform();
    }

    @task({ restartable: true }) *carregar() {
        try {
            const resposta = yield this.fetch.get(`${ENDPOINT}/${this.id}/integracao`);
            this.integracao = resposta.integracao ?? null;
            this.erro = false;
        } catch (error) {
            this.erro = true;
        }
    }

    /** POST .../integracao/chave: devolve a chave em texto (uma vez) e atualiza o bloco; null se falhou (já avisou). */
    async pedirChave() {
        try {
            const resposta = await this.fetch.post(`${ENDPOINT}/${this.id}/integracao/chave`);
            this.integracao = resposta.integracao ?? null;

            return resposta.chave ?? null;
        } catch (error) {
            this.notifications.serverError(error);

            return null;
        }
    }

    mostrarChave(chave) {
        const loja = this.args.loja;
        this.modalsManager.show('modals/chave-da-integracao', {
            title: this.intl.t('fleet-ops.ui.lojas.integracao.chave-titulo', { name: loja?.nome ?? '' }),
            chave,
            loja,
            acceptButtonText: this.intl.t('fleet-ops.ui.lojas.integracao.ja-copiei'),
            acceptButtonIcon: 'check',
            hideDeclineButton: true,
            confirm: (modal) => modal.done(),
        });
    }

    /** Gerar ou revogar em curso: os dois botões ficam desabilitados (um POST e um DELETE juntos se atropelariam). */
    get ocupado() {
        return this.gerarChave.isRunning || this.revogar.isRunning;
    }

    /**
     * drop: o segundo clique enquanto o primeiro corre é ignorado. Sem isso, um clique duplo em "Gerar chave" mandava dois
     * POSTs e abria dois modais, e o de baixo mostrava uma chave já revogada pelo segundo POST.
     */
    @task({ drop: true }) *gerarChave() {
        if (this.integracao?.ativa !== true) {
            const chave = yield this.pedirChave();
            if (chave) {
                this.mostrarChave(chave);
            }
            return;
        }

        // Já há chave ativa: confirma antes. A chave nova só abre depois que o modal de confirmação sai da pilha
        // (onFinish + next): quando a promessa do confirm termina, o modalsManager (onClickConfirmWithDone) fecha o modal de
        // confirmação pelo id e só então chama o onFinish. Assim os dois modais nunca ficam abertos juntos.
        // Esc ou clique fora durante o POST: o onClose do Modal::Default chama o onDecline sem olhar o isLoading, a
        // confirmação fecha e o onFinish roda antes de a chave chegar. A marca `fechou` faz o confirm abrir a chave ele
        // mesmo quando ela chega (quandoMostrarAChave); senão a chave nova se perderia com a anterior já revogada.
        let chaveNova = null;
        let fechou = false;
        let pedido = null;
        yield this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.ui.lojas.integracao.regerar-titulo'),
            body: this.intl.t('fleet-ops.ui.lojas.integracao.regerar-texto', { name: this.args.loja?.nome ?? '', final: this.final }),
            acceptButtonText: this.intl.t('fleet-ops.ui.lojas.integracao.gerar-de-novo'),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                pedido = this.pedirChave();
                const chave = await pedido;
                const quando = quandoMostrarAChave(chave, fechou);
                if (quando === 'agora') {
                    next(() => this.mostrarChave(chave));
                } else if (quando === 'ao-fechar') {
                    chaveNova = chave;
                }
            },
            onFinish: () => {
                fechou = true;
                if (chaveNova) {
                    const chave = chaveNova;
                    chaveNova = null;
                    next(() => this.mostrarChave(chave));
                }
            },
        });
        // A confirmação pode ter fechado (Esc) com o POST ainda em curso: a task só termina com ele, e até lá os botões
        // seguem desabilitados (outro "Gerar outra chave" revogaria a chave que está chegando).
        if (pedido) {
            yield pedido;
        }
    }

    /** DELETE .../integracao: atualiza o bloco e avisa; um erro já vira notificação. */
    async pedirRevogacao() {
        try {
            const resposta = await this.fetch.delete(`${ENDPOINT}/${this.id}/integracao`);
            this.integracao = resposta.integracao ?? null;
            this.notifications.success(this.intl.t('fleet-ops.ui.lojas.integracao.revogada'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** drop: um clique duplo não abre duas confirmações. A task dura até a confirmação fechar e o DELETE terminar. */
    @task({ drop: true }) *revogar() {
        let pedido = null;
        yield this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.ui.lojas.integracao.revogar-titulo'),
            body: this.intl.t('fleet-ops.ui.lojas.integracao.revogar-texto', { name: this.args.loja?.nome ?? '' }),
            acceptButtonText: this.intl.t('fleet-ops.ui.lojas.integracao.revogar'),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                pedido = this.pedirRevogacao();
                await pedido;
            },
        });
        if (pedido) {
            yield pedido;
        }
    }
}
