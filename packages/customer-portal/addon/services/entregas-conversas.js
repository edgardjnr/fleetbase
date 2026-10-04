import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { race, restartableTask, task, timeout, waitForEvent } from 'ember-concurrency';
import { espera } from '../utils/entregas-pedido';
import { INTERVALO_CONVERSA_MS, INTERVALO_LISTA_CONVERSAS_MS, mesclarMensagens, prefixoDoPedido, registrarUltimas, textoValido, totalNaoLidas, ultimasDosOutros } from '../utils/conversas';
import { prepararSom, tocarSomDeMensagem } from '../utils/som-de-mensagem';

/**
 * Entregas: o chat da loja com os motoboys (int/v1/entregas/loja/conversas*, App\Http\Controllers\Entregas\ConversasDaLojaController).
 * Desenho: docs/superpowers/specs/2026-10-04-chat-da-loja-design.md.
 *
 * Estado compartilhado entre o botão Conversas do cabeçalho da tela Pedidos (total de não lidas), a gaveta
 * (Portal::Conversas) e o botão "Conversar com o motoboy" do detalhe do pedido. Sem socket (o portal não usa o socket):
 * - a lista de conversas é lida a cada 20 s enquanto a tela Pedidos está aberta (acompanharLista, que o Workspace inicia e
 *   para) e a cada 5 s com a gaveta aberta na lista;
 * - a conversa aberta é lida a cada 5 s (acompanharConversa), o que também a marca como lida no servidor.
 * Com a aba oculta, a conversa aberta não é lida (volta assim que a aba aparece), mas a lista continua a cada 20 s: é ela
 * que toca o aviso de mensagem nova com o operador em outra aba. Em erro (429 inclusive), a espera dobra até 2 min.
 *
 * Mensagem nova de outra pessoa (motoboy, central ou outro usuário da loja) toca um aviso curto (utils/som-de-mensagem),
 * uma vez por mensagem (registrarUltimas); a primeira leitura não toca. O navegador só libera o som depois de um clique ou
 * tecla na página: o primeiro gesto prepara o som.
 */
export default class EntregasConversasService extends Service {
    @service fetch;
    @service notifications;

    /** A gaveta está aberta. */
    @tracked aberta = false;
    /** As conversas da loja (GET loja/conversas). */
    @tracked conversas = [];
    /** A conversa aberta na gaveta (item da lista); null = a gaveta mostra a lista. */
    @tracked conversaAtual = null;
    @tracked mensagens = [];
    @tracked rascunho = '';
    /** A gaveta mostra os motoboys do mapa para começar uma conversa. */
    @tracked escolhendoMotoboy = false;
    @tracked motoboys = [];
    /** A primeira leitura da conversa aberta ainda não voltou. */
    @tracked carregandoMensagens = false;

    /** Horário da última mensagem dos outros já avisada, por conversa (null antes da primeira leitura). */
    avisadas = null;
    /** O ouvinte do primeiro gesto que libera o som. */
    liberarSom = null;

    get naoLidas() {
        return totalNaoLidas(this.conversas);
    }

    @action abrirGaveta() {
        this.aberta = true;
        this.lerListaAgora();
    }

    @action fecharGaveta() {
        this.aberta = false;
        this.voltarParaLista();
    }

    @action alternarGaveta() {
        if (this.aberta) {
            this.fecharGaveta();
        } else {
            this.abrirGaveta();
        }
    }

    @action voltarParaLista() {
        this.acompanharConversa.cancelAll();
        this.conversaAtual = null;
        this.mensagens = [];
        this.rascunho = '';
        this.escolhendoMotoboy = false;
    }

    @action escolherConversa(conversa, rascunho = '') {
        this.escolhendoMotoboy = false;
        this.conversaAtual = conversa;
        this.mensagens = [];
        this.rascunho = rascunho;
        this.acompanharConversa.perform();
    }

    @action novaConversa() {
        this.acompanharConversa.cancelAll();
        this.conversaAtual = null;
        this.escolhendoMotoboy = true;
        this.carregarMotoboys.perform();
    }

    @action atualizarRascunho(event) {
        this.rascunho = event?.target?.value ?? '';
    }

    /** A lista de conversas enquanto a tela Pedidos está aberta (o Workspace inicia e cancela). */
    @restartableTask *acompanharLista() {
        let falhas = 0;
        this.escutarGestos();

        while (!this.isDestroying) {
            const oculta = document.hidden;

            // também com a aba oculta, para o aviso de mensagem nova tocar com o operador em outra aba
            try {
                yield this.lerLista();
                falhas = 0;
            } catch {
                falhas++;
            }

            // com a gaveta aberta na lista (e a aba visível), as conversas novas e as não lidas aparecem mais depressa
            const intervalo = !oculta && this.aberta && !this.conversaAtual ? INTERVALO_CONVERSA_MS : INTERVALO_LISTA_CONVERSAS_MS;
            yield timeout(espera(intervalo, falhas));
        }
    }

    /** A conversa aberta, a cada 5 s, enquanto ela está na gaveta. */
    @restartableTask *acompanharConversa() {
        let falhas = 0;
        this.carregandoMensagens = true;

        try {
            while (this.conversaAtual) {
                const oculta = document.hidden;

                if (!oculta) {
                    const conversa = this.conversaAtual;

                    try {
                        const resposta = yield this.fetch.get(`entregas/loja/conversas/${conversa.id}/mensagens`);
                        falhas = 0;

                        // a conversa pode ter mudado durante a leitura
                        if (this.conversaAtual?.id === conversa.id) {
                            this.mensagens = Array.isArray(resposta?.mensagens) ? resposta.mensagens : [];
                            this.zerarNaoLidas(conversa.id);
                            this.avisarNovas(ultimasDosOutros([], conversa.id, this.mensagens));
                        }
                    } catch {
                        falhas++;
                    } finally {
                        this.carregandoMensagens = false;
                    }
                }

                const proxima = timeout(espera(INTERVALO_CONVERSA_MS, falhas));
                yield oculta ? race([proxima, waitForEvent(document, 'visibilitychange')]) : proxima;
            }
        } finally {
            this.carregandoMensagens = false;
        }
    }

    @task *enviar() {
        const conversa = this.conversaAtual;
        const texto = textoValido(this.rascunho);

        if (!conversa || !texto) {
            return;
        }

        try {
            const resposta = yield this.fetch.post(`entregas/loja/conversas/${conversa.id}/mensagens`, { texto });

            if (this.conversaAtual?.id === conversa.id) {
                this.mensagens = mesclarMensagens(this.mensagens, resposta?.mensagem ? [resposta.mensagem] : []);
                this.rascunho = '';
            }

            this.lerListaAgora();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** Abre (ou cria) a conversa com o motoboy escolhido no mapa (id opaco). */
    @task *abrirComMotoboy(idOpaco) {
        yield this.abrir({ motoboy: idOpaco });
    }

    /** Abre (ou cria) a conversa com o motoboy do pedido, com o número do pedido no começo do texto. */
    @task *abrirPorPedido(publicId, numero = null) {
        this.aberta = true;
        yield this.abrir({ pedido: publicId }, prefixoDoPedido(numero ?? publicId));
    }

    @task *carregarMotoboys() {
        try {
            const resposta = yield this.fetch.get('entregas/loja/motoboys');
            this.motoboys = (Array.isArray(resposta?.motoboys) ? resposta.motoboys : []).filter((motoboy) => motoboy?.id && motoboy?.nome);
        } catch (error) {
            this.motoboys = [];
            this.notifications.serverError(error);
        }
    }

    /** Para tudo ao sair da tela Pedidos. */
    @action encerrar() {
        this.acompanharLista.cancelAll();
        this.fecharGaveta();
        this.pararDeEscutarGestos();
        // na volta à tela Pedidos, a primeira leitura registra de novo, sem tocar pelo que chegou enquanto estava fora
        this.avisadas = null;
    }

    async abrir(corpo, rascunho = '') {
        try {
            const resposta = await this.fetch.post('entregas/loja/conversas', corpo);
            const conversa = resposta?.conversa;

            if (!conversa?.id) {
                return;
            }

            this.conversas = [conversa, ...this.conversas.filter((item) => item.id !== conversa.id)];
            this.aberta = true;
            this.escolherConversa(conversa, rascunho);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    async lerLista() {
        const resposta = await this.fetch.get('entregas/loja/conversas');
        this.conversas = Array.isArray(resposta?.conversas) ? resposta.conversas : [];
        this.avisarNovas(ultimasDosOutros(this.conversas));

        // a conversa aberta acompanha o que a lista traz de novo (nome, foto), mas as não lidas dela ficam zeradas
        if (this.conversaAtual) {
            const atual = this.conversas.find((item) => item.id === this.conversaAtual.id);
            if (atual) {
                this.conversaAtual = atual;
                this.zerarNaoLidas(atual.id);
            }
        }
    }

    lerListaAgora() {
        this.lerLista().catch(() => {});
    }

    /** Toca o aviso se há mensagem dos outros ainda não avisada (a primeira leitura só registra). */
    avisarNovas(itens) {
        const { avisadas, tocar } = registrarUltimas(this.avisadas, itens);
        this.avisadas = avisadas;

        if (tocar) {
            tocarSomDeMensagem();
        }
    }

    // o primeiro clique ou tecla na página libera o som do navegador
    escutarGestos() {
        if (this.liberarSom) {
            return;
        }

        this.liberarSom = () => {
            prepararSom();
            this.pararDeEscutarGestos();
        };
        document.addEventListener('pointerdown', this.liberarSom, true);
        document.addEventListener('keydown', this.liberarSom, true);
    }

    pararDeEscutarGestos() {
        if (this.liberarSom) {
            document.removeEventListener('pointerdown', this.liberarSom, true);
            document.removeEventListener('keydown', this.liberarSom, true);
            this.liberarSom = null;
        }
    }

    zerarNaoLidas(id) {
        this.conversas = this.conversas.map((item) => (item.id === id && item.nao_lidas ? { ...item, nao_lidas: 0 } : item));
    }
}
