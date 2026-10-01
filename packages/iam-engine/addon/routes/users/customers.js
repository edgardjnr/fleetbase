import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class UsersCustomersRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        status: { refreshModel: true },
        role: { refreshModel: true },
        name: { refreshModel: true },
        phone: { refreshModel: true },
        email: { refreshModel: true },
    };

    model(params) {
        return this.store.query('user', { ...params, is_customer: 1 });
    }
}
