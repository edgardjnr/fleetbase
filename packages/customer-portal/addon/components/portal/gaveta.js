import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { iniciais, resumoDaGaveta } from '../../utils/gaveta-do-menu';

// Entregas: a gaveta do menu da tela Pedidos (☰; sketch 003, variante B "Marca da loja"). Opaca, por cima do mapa: topo
// na cor da loja com o nome e o endereço, o resumo de hoje em três cartões, Novo pedido, o menu e a conta com o tema
// escuro e o sair (a cor de cada item fica no CSS, pela classe is-<chave>). Só existe aberta (o portal.hbs a cria e a
// destrói): a loja e o resumo são lidos a cada abertura
const ITENS = [
    { rota: 'portal.home', chave: 'inicio', icone: 'house', texto: 'customer-portal.ui.nav.dashboard' },
    { rota: 'portal.orders', chave: 'pedidos', icone: 'boxes-packing', texto: 'customer-portal.ui.nav.orders', contagem: true },
    { rota: 'portal.extrato', chave: 'extrato', icone: 'file-invoice-dollar', texto: 'customer-portal.ui.entregas.statement' },
    { rota: 'portal.financeiro', chave: 'financeiro', icone: 'file-invoice', texto: 'customer-portal.ui.entregas.financeiro.menu' },
    { rota: 'portal.settings', chave: 'configuracoes', icone: 'gear', texto: 'customer-portal.ui.nav.account-settings' },
];

export default class PortalGavetaComponent extends Component {
    @service fetch;
    @service theme;
    @service currentUser;
    @tracked loja = null;
    @tracked resumo = null;

    itens = ITENS;

    constructor() {
        super(...arguments);
        this.aoTeclar = (evento) => {
            if (evento.key === 'Escape') {
                this.args.onFechar?.();
            }
        };
        document.addEventListener('keydown', this.aoTeclar);
        this.carregar();
    }

    willDestroy() {
        document.removeEventListener('keydown', this.aoTeclar);
        super.willDestroy(...arguments);
    }

    async carregar() {
        // as duas leituras são independentes: falha de uma não esconde a outra (fica o nome da conta ou "–")
        const [loja, resumo] = await Promise.all([
            this.fetch.get('entregas/loja/minha-loja').catch(() => null),
            this.fetch.get('entregas/loja/resumo-do-dia').catch(() => null),
        ]);
        if (this.isDestroying || this.isDestroyed) {
            return;
        }
        this.loja = loja?.loja ?? null;
        this.resumo = resumo;
    }

    get nome() {
        return this.loja?.nome || this.args.nomeDaConta;
    }

    get endereco() {
        return this.loja?.endereco;
    }

    get iniciais() {
        return iniciais(this.nome);
    }

    get hoje() {
        return resumoDaGaveta(this.resumo);
    }

    get escuro() {
        return this.theme.currentTheme === 'dark';
    }

    @action alternarTema(evento) {
        this.theme.setTheme(evento.target.checked ? 'dark' : 'light');
    }
}
