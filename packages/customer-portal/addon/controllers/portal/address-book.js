import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class PortalAddressBookController extends Controller {
    @service fetch;
    @service modalsManager;
    @service notifications;
    @service intl;

    get places() {
        return this.model?.places ?? [];
    }

    get actionButtons() {
        return [
            {
                text: this.intl.t('customer-portal.ui.address-book.new-place'),
                icon: 'plus',
                size: 'xs',
                wrapperClass: 'portal-order-panel-action-button',
                onClick: this.createPlace,
            },
        ];
    }

    @action createPlace() {
        const place = {};

        this.modalsManager.show('modals/portal-order-place-form', {
            title: this.intl.t('customer-portal.ui.address-book.new-place'),
            modalClass: 'modal-md',
            acceptButtonText: this.intl.t('customer-portal.ui.address-book.save-place'),
            declineButtonText: this.intl.t('customer-portal.ui.common.cancel'),
            place,
            confirm: () => this.savePlace(place),
        });
    }

    @action editPlace(place) {
        const editablePlace = {
            uuid: place.uuid,
            public_id: place.public_id,
            name: place.name,
            street1: place.street1 ?? place.address,
            street2: place.street2,
            neighborhood: place.neighborhood,
            building: place.building,
            security_access_code: place.security_access_code,
            postal_code: place.postal_code,
            city: place.city,
            province: place.province,
            country: place.country,
            phone: place.phone,
            latitude: place.latitude,
            longitude: place.longitude,
            location: place.location,
            address: place.address,
        };

        this.modalsManager.show('modals/portal-order-place-form', {
            title: this.intl.t('customer-portal.ui.address-book.edit-place-title'),
            modalClass: 'modal-md',
            acceptButtonText: this.intl.t('customer-portal.ui.common.save-changes'),
            declineButtonText: this.intl.t('customer-portal.ui.common.cancel'),
            place: editablePlace,
            confirm: () => this.savePlace(editablePlace),
        });
    }

    @action async deletePlace(place) {
        await this.modalsManager.confirm({
            title: this.intl.t('customer-portal.ui.address-book.delete-title'),
            body: this.intl.t('customer-portal.ui.address-book.delete-body'),
            acceptButtonText: this.intl.t('customer-portal.ui.common.delete'),
            acceptButtonType: 'danger',
            confirm: async () => {
                try {
                    await this.fetch.delete(`places/${this.identifier(place)}`, {}, { namespace: 'customer-portal/int/v1' });
                    this.model = {
                        ...this.model,
                        places: this.places.filter((candidate) => this.identifier(candidate) !== this.identifier(place)),
                    };
                    this.notifications.success(this.intl.t('customer-portal.ui.address-book.removed'));
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    async savePlace(place) {
        try {
            const id = this.identifier(place);
            const response = id
                ? await this.fetch.patch(`places/${id}`, place, { namespace: 'customer-portal/int/v1' })
                : await this.fetch.post('places', place, { namespace: 'customer-portal/int/v1' });
            const savedPlace = response.place ?? response;
            const existingIndex = this.places.findIndex((candidate) => this.identifier(candidate) === this.identifier(savedPlace));
            const places = [...this.places];

            if (existingIndex > -1) {
                places.splice(existingIndex, 1, savedPlace);
            } else {
                places.unshift(savedPlace);
            }

            this.model = {
                ...this.model,
                places,
            };
            this.notifications.success(id ? this.intl.t('customer-portal.ui.address-book.updated') : this.intl.t('customer-portal.ui.address-book.saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    identifier(place) {
        return place?.uuid ?? place?.public_id ?? (typeof place?.id === 'string' ? place.id : null);
    }
}
