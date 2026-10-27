import Controller, { inject as controller } from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { debug } from '@ember/debug';

export default class OperationsOrdersIndexNewController extends Controller {
    @controller('operations.orders.index') index;
    @service loader;
    @service intl;
    @service universe;
    @service notifications;
    @service hostRouter;
    @service placeActions;
    @service customerActions;
    @service vendorActions;
    @service entityActions;
    @service orderImport;
    @service orderCreation;
    @service orderValidation;
    @service events;
    @service sidebar;
    @tracked order = this.orderCreation.newOrder();
    @tracked overlay;

    /** action buttons */
    get actionButtons() {
        // Entregas: sem Importar (planilha de paradas/entidades) e sem "Criar novo facilitador" (fornecedores ocultos)
        return [
            {
                icon: 'ellipsis-h',
                triggerClass: 'hidden md:flex',
                items: [
                    {
                        icon: 'user-plus',
                        text: this.intl.t('order.actions.create-new-customer'),
                        fn: this.customerActions.modal.create,
                    },
                    {
                        icon: 'map-pin',
                        text: this.intl.t('order.actions.create-new-place'),
                        fn: this.placeActions.modal.create,
                    },
                ],
            },
        ];
    }

    @task *save(order) {
        if (this.orderValidation.validationFails(order)) return;

        // Display loader
        this.loader.showLoader('body', { loadingMessage: this.intl.t('common.creating-resource', { resource: this.intl.t('resource.order') }) + '...' });

        try {
            // Trigger creating event
            this.universe.trigger('fleet-ops.order.creating', order);

            // Save custom field values
            if (order.cfManager) {
                const { created: customFieldValues } = yield order.cfManager.saveTo(order);
                order.custom_field_values.pushObjects(customFieldValues);
            }

            // Save order
            const createdOrder = yield order.save();
            this.events.trackResourceCreated(order);

            // Trigger created event
            this.universe.trigger('fleet-ops.order.created', createdOrder);

            // Entregas: abre direto no mapa com a folha do pedido (sketch 008). Ir à rota de detalhes fazia o beforeModel
            // dela redirecionar, e o yield recebia o TransitionAborted da transição trocada: o pedido era criado, mas o
            // salvar mostrava "TransitionAborted" no lugar do aviso de sucesso.
            yield this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index', {
                queryParams: { layout: 'map', pedido: createdOrder.public_id, de: null },
            });
            this.notifications.success(this.intl.t('common.resource-created-success-name', { resource: this.intl.t('resource.order'), resourceName: createdOrder.tracking }));
        } catch (err) {
            console.error(err);
            debug('Error creating order: ' + err.message);
            this.notifications.serverError(err);
        } finally {
            this.loader.removeLoader();
        }
    }

    @action setup() {
        this.index.changeLayout('map');
    }

    reset() {
        this.order = this.orderCreation.newOrder();
    }
}
