import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { iniciaisDoSistema, seloDoCartao, tempoDesde } from '../../../utils/integracoes-da-loja';

const BASE = 'customer-portal.ui.entregas.integracoes';

/**
 * Entregas: o cartão de um sistema na tela Integrações (@integracao, do GET entregas/loja/integracoes). Com @onAbrir é
 * um botão que abre a conexão; com @destaque, é o cabeçalho da conexão aberta. A descrição de cada sistema é a tradução
 * `…integracoes.sistemas.<id>.descricao`; sistema sem tradução mostra só o nome que veio do servidor.
 */
export default class PortalIntegracoesCartaoComponent extends Component {
    @service intl;

    get id() {
        return this.args.integracao?.id ?? '';
    }

    get nome() {
        return this.args.integracao?.nome || this.id;
    }

    get iniciais() {
        return iniciaisDoSistema(this.nome);
    }

    get descricao() {
        const chave = `${BASE}.sistemas.${this.id}.descricao`;

        return this.intl.exists(chave) ? this.intl.t(chave) : '';
    }

    // conectada | aguardando | atencao | desconectada | indisponivel
    get selo() {
        return seloDoCartao(this.args.integracao);
    }

    get textoDoSelo() {
        return this.intl.t(`${BASE}.selo.${this.selo}`);
    }

    // a linha embaixo do selo: a loja no iFood, o último pedido do sistema por chave ou o que falta fazer
    get detalhe() {
        const integracao = this.args.integracao ?? {};

        if (this.selo === 'conectada') {
            if (integracao.loja_no_ifood) {
                return this.intl.t(`${BASE}.detalhe.loja-no-ifood`, { loja: integracao.loja_no_ifood });
            }
            const uso = tempoDesde(integracao.ultimo_uso_em, Date.now());
            return uso ? this.intl.t(`${BASE}.detalhe.ultimo-pedido.${uso.unidade}`, { n: uso.n }) : '';
        }
        if (this.selo === 'aguardando') {
            return this.intl.t(`${BASE}.detalhe.aguardando`, { sistema: this.nome });
        }
        if (this.selo === 'atencao') {
            return this.intl.t(`${BASE}.detalhe.atencao`);
        }

        return '';
    }

    // o texto do botão do cartão: Gerenciar, Continuar, Conectar de novo, Conectar ou Ver (indisponível)
    get acao() {
        const acoes = { conectada: 'gerenciar', aguardando: 'continuar', atencao: 'reconectar', desconectada: 'conectar', indisponivel: 'ver' };

        return this.intl.t(`${BASE}.acao.${acoes[this.selo]}`);
    }

    @action abrir() {
        this.args.onAbrir?.(this.args.integracao);
    }
}
