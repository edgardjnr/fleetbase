import buildRoutes from 'ember-engines/routes';

export default buildRoutes(function () {
    this.route('portal-auth', { path: '/auth' }, function () {
        this.route('login');
        this.route('two-fa');
        this.route('verification');
        this.route('forgot-password');
        // Entregas: o link do e-mail traz o id do código (customer-portal/auth/reset-password/<uuid>?code=...); a rota
        // lê model({ id }) e, sem o segmento, validava um id vazio
        this.route('reset-password', { path: '/reset-password/:id' });
    });
    this.route('portal', { path: '/' }, function () {
        this.route('home', { path: '/' });
        this.route('orders', function () {
            this.route('index', { path: '/' });
            this.route('new');
            this.route('details', { path: '/:id' });
        });
        // Entregas: billing, support, documents, address-book e notifications só redirecionam para Pedidos
        // (links antigos); as telas foram apagadas
        this.route('billing', function () {
            this.route('index', { path: '/' });
            this.route('details', { path: '/:id' });
        });
        this.route('support', function () {
            this.route('index', { path: '/' });
            this.route('new');
            this.route('details', { path: '/:id' });
        });
        this.route('documents');
        this.route('address-book');
        this.route('notifications');
        this.route('settings', function () {
            this.route('account');
            this.route('members');
        });
        this.route('account');
        // Entregas: extrato de entregas da loja (antes de `virtual`, que é o catch-all `/:slug`)
        this.route('extrato');
        // Entregas: faturas da loja e o ciclo em andamento; o detalhe da fatura é também a página de impressão
        this.route('financeiro');
        this.route('fatura', { path: '/financeiro/:id' });
        // Entregas: a loja conecta sozinha os sistemas dela (iFood, RestaurantePro…); `?sistema=<id>` abre a conexão
        this.route('integracoes');
        this.route('virtual', { path: '/:slug' });
    });
});
