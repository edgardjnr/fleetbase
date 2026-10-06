import Service, { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import escutarCanalDaEmpresa from '../utils/escutar-canal-da-empresa';
import { avisoAcaoRecusada } from '../utils/pedido-ifood';
import { tocarSomDeAlerta } from '../utils/som-de-alerta';

const ROTA_DO_PEDIDO = 'console.fleet-ops.operations.orders.index.details';

/**
 * Entregas: aviso à central quando o iFood recusa uma ação de logística de um pedido (evento
 * entregas.ifood_acao_recusada no canal company.<uuid>, App\Events\Entregas\IfoodAcaoRecusada). Toca o som de alerta e
 * mostra uma notificação fixa que abre o pedido no clique; a central confere o pedido no Gestor de Pedidos do iFood (e,
 * se for o código, usa "Liberar sem código" no painel iFood). Iniciado na rota raiz do engine (routes/application.js),
 * como o pedido-sem-motoboy, com consumidor próprio do canal (utils/escutar-canal-da-empresa.js). O som é liberado pelo
 * pedido-sem-motoboy (primeiro clique ou tecla na página).
 */
export default class IfoodAcaoRecusadaService extends Service {
    @service socket;
    @service currentUser;
    @service notifications;
    @service intl;
    @service hostRouter;

    /** "<id>:<acao>" → notificação aberta (não repete o aviso do mesmo pedido e da mesma ação enquanto ele estiver aberto) */
    avisos = new Map();
    canal = null;

    iniciar() {
        if (this.canal) {
            return;
        }
        this.canal = escutarCanalDaEmpresa({
            socket: this.socket,
            currentUser: this.currentUser,
            aoReceber: (mensagem) => this.#receber(mensagem),
            aoFalhar: (err) => debug('iFood (ação recusada): ' + err?.message),
        });
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.canal?.parar();
        this.canal = null;
    }

    #receber(mensagem) {
        const aviso = avisoAcaoRecusada(mensagem);
        if (!aviso) {
            return;
        }

        // limpa os avisos já dispensados no "x", para a mesma recusa poder voltar a avisar
        for (const [chave, notificacao] of this.avisos) {
            if (notificacao?.dismiss) {
                this.avisos.delete(chave);
            }
        }

        const chave = `${aviso.id}:${aviso.acao}`;
        if (this.avisos.has(chave)) {
            return;
        }

        tocarSomDeAlerta();
        const acao = this.intl.t(`fleet-ops.ui.ifood.acao.${aviso.acao}`);
        // status 0 = a ação nem chegou a ser uma resposta do iFood (vínculo perdido, motoboy sem telefone, iFood fora do ar)
        const texto =
            aviso.status === 0
                ? this.intl.t('fleet-ops.ui.ifood.aviso-falha', { numero: aviso.numero, acao })
                : this.intl.t('fleet-ops.ui.ifood.aviso-recusa', { numero: aviso.numero, acao, status: aviso.status });
        const notificacao = this.notifications.warning(texto, {
            autoClear: false,
            onClick: () => {
                this.hostRouter.transitionTo(ROTA_DO_PEDIDO, aviso.id);
                this.#fechar(chave);
            },
        });

        this.avisos.set(chave, notificacao);
    }

    #fechar(chave) {
        const notificacao = this.avisos.get(chave);
        if (!notificacao) {
            return;
        }

        this.avisos.delete(chave);
        this.notifications.removeNotification(notificacao);
    }
}
