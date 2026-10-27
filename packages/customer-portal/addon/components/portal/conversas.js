import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { MAX_CARACTERES, itensDaLista, resumo, semConversaComMotoboy } from '../../utils/conversas';

// Entregas: a gaveta do chat da loja com os motoboys e com a central (tela Pedidos). Três vistas, todas pelo serviço entregas-conversas:
// a lista de conversas, os motoboys do mapa (Nova conversa) e a conversa aberta. O estado e as leituras ficam no serviço,
// que o botão Conversas do cabeçalho e o detalhe do pedido também usam
export default class PortalConversasComponent extends Component {
    @service entregasConversas;
    @service intl;

    maxCaracteres = MAX_CARACTERES;

    get conversas() {
        return this.entregasConversas;
    }

    get titulo() {
        const atual = this.conversas.conversaAtual;

        if (atual) {
            if (atual.tipo === 'central') {
                return this.intl.t('customer-portal.ui.entregas.chat-central');
            }

            return atual.motoboy?.nome || this.intl.t('customer-portal.ui.entregas.chat-courier');
        }

        if (this.conversas.escolhendoMotoboy) {
            return this.intl.t('customer-portal.ui.entregas.chat-new');
        }

        return this.intl.t('customer-portal.ui.entregas.chat-title');
    }

    /** O texto da conversa ainda sem mensagens. */
    get textoInicial() {
        return this.intl.t(this.conversas.conversaAtual?.tipo === 'central' ? 'customer-portal.ui.entregas.chat-start-central' : 'customer-portal.ui.entregas.chat-start');
    }

    get podeVoltar() {
        return Boolean(this.conversas.conversaAtual || this.conversas.escolhendoMotoboy);
    }

    get itens() {
        return itensDaLista(this.conversas.conversas).map((conversa) => {
            const central = conversa.tipo === 'central';

            return {
                conversa,
                central,
                nome: central ? this.intl.t('customer-portal.ui.entregas.chat-central') : conversa.motoboy?.nome || this.intl.t('customer-portal.ui.entregas.chat-courier'),
                foto: central ? null : conversa.motoboy?.foto,
                resumo: conversa.ultima
                    ? `${conversa.ultima.minha ? this.intl.t('customer-portal.ui.entregas.chat-you') + ': ' : ''}${resumo(conversa.ultima.texto)}`
                    : this.intl.t(central ? 'customer-portal.ui.entregas.chat-central-hint' : 'customer-portal.ui.entregas.chat-no-messages'),
                em: conversa.ultima?.em,
                naoLidas: Number(conversa.nao_lidas) || 0,
            };
        });
    }

    /** A lista sempre tem a Central: o aviso de como começar aparece abaixo dela enquanto não há conversa com motoboy. */
    get semConversaComMotoboy() {
        return semConversaComMotoboy(this.conversas.conversas);
    }

    get mensagens() {
        return this.conversas.mensagens.map((mensagem) => ({
            ...mensagem,
            papel: this.intl.t(`customer-portal.ui.entregas.chat-role-${mensagem.autor?.papel ?? 'central'}`),
        }));
    }

    get motoboys() {
        return this.conversas.motoboys.map((motoboy) => ({
            ...motoboy,
            situacaoTexto: this.intl.t(`customer-portal.ui.entregas.chat-situation-${motoboy.situacao ?? 'livre'}`),
        }));
    }

    get podeEnviar() {
        const texto = (this.conversas.rascunho ?? '').trim();
        return texto !== '' && !this.conversas.enviar.isRunning;
    }

    // a conversa com a central sem id ainda não existe: tocar cria e abre
    @action abrirItem(item) {
        if (item.conversa.id) {
            this.conversas.escolherConversa(item.conversa, '');
        } else {
            this.conversas.abrirComACentral.perform();
        }
    }

    @action rolarParaOFim(elemento) {
        elemento.scrollTop = elemento.scrollHeight;
    }

    // Enter envia; Shift+Enter quebra a linha
    @action teclaNoTexto(event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            this.enviar();
        }
    }

    @action enviar() {
        if (this.podeEnviar) {
            this.conversas.enviar.perform();
        }
    }
}
