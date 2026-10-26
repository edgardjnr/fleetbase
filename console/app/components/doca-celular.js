import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/**
 * Entregas: rodapé do console no celular (sketch 007, variante B "Doca flutuante"). Substitui o Layout::MobileNavbar do
 * Fleetbase (12 itens rolando para o lado). Cinco destinos fixos: Início, Pedidos (quadro em cartões), Mapa, Motoboys e
 * Mais, que sobe numa folha de baixo com os atalhos em grade. O "+" flutuante abre o novo pedido no Início e em Pedidos.
 *
 * O menu lateral fica fechado no celular (CSS em celular.css); fora do Fleet-Ops, o "Menu desta área" da folha o abre.
 */
const PEDIDOS = 'console.fleet-ops.operations.orders.index';
const MOTOBOYS = 'console.fleet-ops.management.drivers.index';
const CLASSE_MENU_ABERTO = 'ent-menu-lateral-aberto';

export default class DocaCelularComponent extends Component {
    @service router;
    @service sidebar;
    @service currentUser;
    @service abilities;
    @service universe;
    @service theme;
    @service media;
    @service session;
    @service intl;
    @tracked maisAberto = false;
    @tracked rota = '';
    @tracked layout = null;

    constructor() {
        super(...arguments);
        this.lerRota();
        this.aoMudarRota = () => {
            this.lerRota();
            this.maisAberto = false;
            this.fecharMenuLateral();
        };
        this.router.on('routeDidChange', this.aoMudarRota);
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.router.off('routeDidChange', this.aoMudarRota);
        document.body.classList.remove(CLASSE_MENU_ABERTO);
    }

    lerRota() {
        this.rota = this.router.currentRouteName ?? '';
        this.layout = this.router.currentRoute?.queryParams?.layout ?? null;
    }

    get aba() {
        if (this.maisAberto) return 'mais';
        const rota = this.rota;
        if (rota === 'console.home' || rota === 'console.index') return 'inicio';
        if (rota.startsWith('console.fleet-ops.operations.orders')) return this.layout === 'kanban' || this.layout === 'table' ? 'pedidos' : 'mapa';
        if (rota.startsWith('console.fleet-ops.management.drivers')) return 'motoboys';
        return 'mais';
    }

    get abas() {
        const t = (chave) => this.intl.t(`console.ui.celular.abas.${chave}`);
        return [
            { chave: 'inicio', icone: 'house', texto: t('inicio') },
            { chave: 'pedidos', icone: 'paper-plane', texto: t('pedidos') },
            { chave: 'mapa', icone: 'map-location-dot', texto: t('mapa') },
            { chave: 'motoboys', icone: 'helmet-safety', texto: t('motoboys') },
            { chave: 'mais', icone: 'grip', texto: t('mais') },
        ].map((aba) => ({ ...aba, ativa: aba.chave === this.aba }));
    }

    /**
     * "+" flutuante: no Início e em Pedidos, só na tela em si (some no detalhe e no formulário, que abrem por cima). No Mapa,
     * a barra da tela já tem o botão e a folha de baixo ocupa o pé.
     */
    get mostrarNovo() {
        if (this.maisAberto || !this.abilities.can('fleet-ops create order')) return false;
        return this.rota === 'console.home' || ((this.rota === PEDIDOS || this.rota === `${PEDIDOS}.index`) && this.aba === 'pedidos');
    }

    get ehAdmin() {
        return this.currentUser.isAdmin === true;
    }

    /**
     * Admin do sistema ou papel Administrator na organização: vê os Saques Pix (a mesma regra do ehAdminDaCentral de
     * packages/fleetops/addon/utils/financeiro-saque-pix.js; o console não importa utils do engine).
     */
    get ehAdminDaCentral() {
        return this.currentUser.isAdmin === true || this.currentUser.roleName === 'Administrator';
    }

    get idioma() {
        return this.intl.primaryLocale;
    }

    get escuro() {
        return this.theme.currentTheme === 'dark';
    }

    /** Fora do Início e do Fleet-Ops (IAM, Desenvolvedores, Conta, Admin…), o menu do módulo só abre pelo "Menu desta área". */
    get temMenuDaArea() {
        return this.rota !== '' && this.rota !== 'console.home' && !this.rota.startsWith('console.fleet-ops');
    }

    /** Atalhos da folha "Mais": os grupos do trilho do Fleet-Ops (só as telas que ficaram) e os outros módulos. */
    get grupos() {
        const t = (chave) => this.intl.t(`console.ui.celular.mais.${chave}`);
        const pode = (permissao) => this.abilities.can(permissao);
        const item = (icone, texto, rota, visivel = true, queryParams = null) => ({ icone, texto, rota, queryParams, visivel });
        const grupos = [
            {
                titulo: t('operacao'),
                itens: [item('paper-plane', t('pedidos'), PEDIDOS, pode('fleet-ops list order'), { layout: 'kanban' }), item('map-location-dot', t('mapa'), PEDIDOS, pode('fleet-ops list order'), { layout: 'map' })],
            },
            {
                titulo: t('recursos'),
                itens: [item('helmet-safety', t('motoboys'), MOTOBOYS, pode('fleet-ops list driver')), item('store', t('lojas'), 'console.fleet-ops.management.lojas', this.ehAdmin)],
            },
            {
                titulo: t('financeiro'),
                itens: [
                    item('chart-pie', t('painel'), 'console.fleet-ops.financeiro.painel', this.ehAdmin),
                    item('motorcycle', t('extrato-motoboys'), 'console.fleet-ops.financeiro.motoboys', this.ehAdmin),
                    item('money-bill-transfer', t('saques-pix'), 'console.fleet-ops.financeiro.saques', this.ehAdminDaCentral),
                    item('file-invoice-dollar', t('faturas-lojas'), 'console.fleet-ops.financeiro.lojas', this.ehAdmin),
                    item('table', t('relatorio'), 'console.fleet-ops.financeiro.relatorio', this.ehAdmin),
                    item('cloud-rain', t('multiplicadores'), 'console.fleet-ops.financeiro.multiplicadores', this.ehAdmin),
                    item('building', t('corridas-do-sistema'), 'console.fleet-ops.financeiro.corridas-do-sistema', this.ehAdmin),
                ],
            },
            {
                titulo: t('configuracoes'),
                itens: [
                    item('sliders', t('central'), 'console.fleet-ops.settings.index', pode('fleet-ops view navigator-settings')),
                    item('location-arrow', t('app-motoboy'), 'console.fleet-ops.settings.navigator-app', pode('fleet-ops view navigator-settings')),
                    item('map', t('mapa-config'), 'console.fleet-ops.settings.map', pode('fleet-ops view map-settings')),
                    item('bell', t('notificacoes'), 'console.fleet-ops.settings.notifications', pode('fleet-ops view notification-settings')),
                ],
            },
            { titulo: t('outros'), itens: this.modulos },
        ];

        return grupos.map((grupo) => ({ ...grupo, itens: grupo.itens.filter((i) => i.visivel) })).filter((grupo) => grupo.itens.length);
    }

    /** Módulos do cabeçalho (menu de cima do desktop), sem os atalhos (Orders, Drivers…) e sem o Fleet-Ops, que já está acima. */
    get modulos() {
        return (this.universe.headerMenuItems ?? [])
            .filter((m) => !m._isShortcut && m.id !== 'fleet-ops' && this.abilities.can(`${m.id} see extension`))
            .map((m) => ({ icone: m.icon, menuItem: m, rota: m.route, visivel: true }));
    }

    @action irPara(aba) {
        if (aba === 'mais') {
            this.maisAberto = !this.maisAberto;
            return;
        }
        this.maisAberto = false;
        if (aba === 'inicio') return this.router.transitionTo('console.home');
        if (aba === 'pedidos') return this.router.transitionTo(PEDIDOS, { queryParams: { layout: 'kanban' } });
        if (aba === 'mapa') return this.router.transitionTo(PEDIDOS, { queryParams: { layout: 'map' } });
        if (aba === 'motoboys') return this.router.transitionTo(MOTOBOYS);
    }

    @action abrir(item) {
        this.maisAberto = false;
        const rota = item.rota?.startsWith('console') ? item.rota : `console.${item.rota}`;
        if (item.queryParams) return this.router.transitionTo(rota, { queryParams: item.queryParams });
        return this.router.transitionTo(rota);
    }

    @action novoPedido() {
        this.router.transitionTo(`${PEDIDOS}.new`);
    }

    @action fecharMais() {
        this.maisAberto = false;
    }

    @action abrirMenuDaArea() {
        this.maisAberto = false;
        document.body.classList.add(CLASSE_MENU_ABERTO);
        this.sidebar.show();
    }

    fecharMenuLateral() {
        if (!document.body.classList.contains(CLASSE_MENU_ABERTO)) return;
        document.body.classList.remove(CLASSE_MENU_ABERTO);
        this.sidebar.hideNow();
    }

    @action alternarTema() {
        this.theme.setTheme(this.escuro ? 'light' : 'dark');
    }

    @action irParaConta() {
        this.maisAberto = false;
        this.router.transitionTo('console.account.index');
    }

    @action async sair() {
        this.maisAberto = false;
        await this.session.invalidateWithLoader();
    }
}
