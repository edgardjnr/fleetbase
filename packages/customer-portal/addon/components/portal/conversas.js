import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { MAX_CARACTERES, resumo } from '../../utils/conversas';

// Entregas: a gaveta do chat da loja com os motoboys (tela Pedidos). Três vistas, todas pelo serviço entregas-conversas:
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
        if (this.conversas.conversaAtual) {
            return this.conversas.conversaAtual.motoboy?.nome || this.intl.t('customer-portal.ui.entregas.chat-courier');
        }

        if (this.conversas.escolhendoMotoboy) {
            return this.intl.t('customer-portal.ui.entregas.chat-new');
        }

        return this.intl.t('customer-portal.ui.entregas.chat-title');
    }

    get podeVoltar() {
        return Boolean(this.conversas.conversaAtual || this.conversas.escolhendoMotoboy);
    }

    get itens() {
        return this.conversas.conversas.map((conversa) => ({
            conversa,
            nome: conversa.motoboy?.nome || this.intl.t('customer-portal.ui.entregas.chat-courier'),
            foto: conversa.motoboy?.foto,
            resumo: conversa.ultima
                ? `${conversa.ultima.minha ? this.intl.t('customer-portal.ui.entregas.chat-you') + ': ' : ''}${resumo(conversa.ultima.texto)}`
                : this.intl.t('customer-portal.ui.entregas.chat-no-messages'),
            em: conversa.ultima?.em,
            naoLidas: Number(conversa.nao_lidas) || 0,
        }));
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
