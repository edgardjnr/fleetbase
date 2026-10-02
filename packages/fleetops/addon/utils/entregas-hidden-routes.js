/**
 * Entregas RestaurantePro: telas do Fleet-Ops que não fazem sentido para o delivery
 * (pedido do iFood → motoboy). Somem do menu e dos painéis, e o acesso direto pela URL
 * é redirecionado para Pedidos (routes/application.js).
 *
 * Nomes relativos ao engine (sem "console.fleet-ops."); cada item cobre as sub-rotas.
 * Para reativar uma tela, basta tirá-la desta lista.
 */
export const ENTREGAS_HIDDEN_ROUTES = [
    'operations.orchestrator',
    'operations.scheduler',
    'operations.order-config',
    'operations.service-rates',
    'operations.routes',
    'management.vehicles',
    'management.trailers',
    'management.fleets',
    'management.vendors',
    'management.fuel-reports',
    'management.fuel-transactions',
    'management.issues',
    'maintenance',
    'connectivity',
    'analytics.reports',
    'settings.payments',
    'settings.routing',
    'settings.orchestrator',
    'settings.scheduling',
    'settings.custom-fields',
    'settings.avatars',
];

const ENGINE_PREFIX = 'console.fleet-ops.';

export default function isEntregasHiddenRoute(routeName) {
    if (typeof routeName !== 'string') {
        return false;
    }

    const name = routeName.startsWith(ENGINE_PREFIX) ? routeName.slice(ENGINE_PREFIX.length) : routeName;

    return ENTREGAS_HIDDEN_ROUTES.some((hidden) => name === hidden || name.startsWith(`${hidden}.`));
}
