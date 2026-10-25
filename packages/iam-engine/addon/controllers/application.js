import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ApplicationController extends Controller {
    @service intl;
    @service abilities;
    @service fetch;

    get navigationItems() {
        return [
            {
                label: this.intl.t('iam.common.dashboard'),
                description: this.intl.t('iam.ui.nav.dashboard-description'),
                tooltip: true,
                icon: 'home',
                route: 'console.iam.home',
                keywords: ['home', 'overview', 'metrics'],
            },
            {
                label: this.intl.t('iam.common.user'),
                description: this.intl.t('iam.ui.nav.users-description'),
                icon: 'id-card',
                route: 'console.iam.users',
                permission: 'iam list user',
                visible: this.can('iam see user'),
                keywords: ['identity', 'accounts', 'members'],
                children: [
                    {
                        label: this.intl.t('iam.ui.nav.users'),
                        description: this.intl.t('iam.ui.nav.all-users-description'),
                        icon: 'user',
                        route: 'console.iam.users.index',
                        permission: 'iam list user',
                        visible: this.can('iam see user'),
                        keywords: ['people', 'members'],
                    },
                    {
                        label: this.intl.t('iam.ui.nav.drivers'),
                        description: this.intl.t('iam.ui.nav.drivers-description'),
                        icon: 'id-card',
                        route: 'console.iam.users.drivers',
                        permission: 'iam list user',
                        visible: this.can('iam see user'),
                        keywords: ['fleet operators'],
                    },
                    // Entregas RestaurantePro: sem "Clientes" (o login da loja nasce na tela Lojas do Fleet-Ops)
                ],
            },
            // Entregas RestaurantePro: sem Grupos
            {
                label: this.intl.t('iam.common.roles'),
                description: this.intl.t('iam.ui.nav.roles-description'),
                icon: 'tag',
                route: 'console.iam.roles',
                permission: 'iam list role',
                visible: this.can('iam see role'),
                keywords: ['access levels'],
            },
            {
                label: this.intl.t('iam.common.policies'),
                description: this.intl.t('iam.ui.nav.policies-description'),
                icon: 'shield',
                route: 'console.iam.policies',
                permission: 'iam list policy',
                visible: this.can('iam see policy'),
                keywords: ['permissions', 'rules'],
            },
        ];
    }

    can(permission) {
        try {
            return this.abilities.can(permission);
        } catch (_) {
            return true;
        }
    }

    @action
    async searchNavigation({ query, limit = 12 }) {
        const trimmedQuery = query?.trim();

        if (!trimmedQuery) {
            return [];
        }

        try {
            const response = await this.fetch.get('iam/search', {
                query: trimmedQuery,
                limit,
            });

            // Entregas RestaurantePro: a rota de Grupos saiu, então resultado que leva a ela não aparece
            return (response.results ?? []).filter((result) => !String(result?.route ?? '').startsWith('console.iam.groups'));
        } catch (_) {
            return [];
        }
    }
}
