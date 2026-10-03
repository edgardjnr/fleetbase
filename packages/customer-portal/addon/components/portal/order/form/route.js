import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { restartableTask, timeout } from 'ember-concurrency';
import { valueFor } from '../../../../utils/model-access';

/** Entregas: número de um campo de coordenada (número ou texto numérico); o resto vira NaN. */
function numero(valor) {
    if (typeof valor === 'number') {
        return valor;
    }

    return typeof valor === 'string' && valor.trim() !== '' ? Number(valor) : NaN;
}

/** Entregas: o mesmo critério do servidor (RegrasPortalLoja): números, dentro dos limites e fora do (0, 0). */
function coordenadasValidas(latitude, longitude) {
    return (
        Number.isFinite(latitude) &&
        Number.isFinite(longitude) &&
        Math.abs(latitude) <= 90 &&
        Math.abs(longitude) <= 180 &&
        (Math.abs(latitude) > 0.0001 || Math.abs(longitude) > 0.0001)
    );
}

/**
 * Entregas: coordenadas marcadas no mapa do endereço novo, ou null. O mapa grava em `location`
 * (GeoJSON, [lng, lat]) a cada movimento, então ele vem antes de `latitude`/`longitude`.
 */
function coordenadasDoEndereco(place) {
    const [lng, lat] = Array.isArray(place?.location?.coordinates) ? place.location.coordinates : [];
    const candidatas = [
        [numero(lat), numero(lng)],
        [numero(place?.latitude), numero(place?.longitude)],
    ];

    for (const [latitude, longitude] of candidatas) {
        if (coordenadasValidas(latitude, longitude)) {
            return { latitude, longitude };
        }
    }

    return null;
}

// Entregas: só o destino. A coleta é fixa (o endereço da loja, só leitura), sem várias paradas nem retorno, e
// endereço salvo não se edita (o servidor nega PATCH/DELETE): para corrigir, a loja cadastra um endereço novo.
export default class PortalOrderFormRouteComponent extends Component {
    @service customerPortalOrderActions;
    @service customerPortalOrderCreation;
    @service customerPortalOrderRoutePreview;
    @service modalsManager;
    @service notifications;
    @service intl;

    @tracked createdPlaces = [];
    @tracked placeFormContext;

    get places() {
        // Entregas: o Local da loja não aparece como destino
        return [...this.createdPlaces, ...(this.args.places ?? [])].filter((place) => !this.isStorePlace(place));
    }

    get actionButtons() {
        // Entregas: o endereço novo é o destino; sem botão de parada extra
        return [
            {
                text: this.intl.t('customer-portal.ui.place.new-address'),
                icon: 'plus',
                size: 'xs',
                wrapperClass: 'portal-order-panel-action-button',
                onClick: () => this.openPlaceForm('dropoff'),
            },
        ];
    }

    /** Entregas: o mapa do endereço novo abre na loja, quando ela tem coordenadas. */
    get mapCenter() {
        const coleta = this.args.loja?.coleta;
        const latitude = numero(coleta?.latitude);
        const longitude = numero(coleta?.longitude);

        return coordenadasValidas(latitude, longitude) ? { latitude, longitude } : null;
    }

    @restartableTask *searchPlaces(query) {
        const searchQuery = typeof query === 'string' ? query.trim() : '';

        if (searchQuery.length < 2) {
            return [];
        }

        yield timeout(350);

        const center = this.customerPortalOrderRoutePreview.centerCoordinates ?? {};

        const places = yield this.customerPortalOrderActions.searchPlaceCandidates.perform({
            query: searchQuery,
            geo: true,
            latitude: center.latitude,
            longitude: center.longitude,
        });

        // Entregas: a busca também não oferece o Local da loja
        return places.filter((place) => !this.isStorePlace(place));
    }

    @action setPayloadPlace(field, place) {
        this.customerPortalOrderCreation.setPayloadField(field, place);
        this.updateRoutePreview();
    }

    @action openPlaceForm(field) {
        this.placeFormContext = { field };
        const place = {};

        this.modalsManager.show('modals/portal-order-place-form', {
            title: this.intl.t('customer-portal.ui.place.new-address'),
            modalClass: 'modal-md',
            acceptButtonText: this.intl.t('customer-portal.ui.place.save-address'),
            declineButtonText: this.intl.t('customer-portal.ui.common.cancel'),
            place,
            // Entregas: o modal só fecha quando o endereço é salvo; sem coordenadas ou com erro do servidor, fica aberto
            mapCenter: this.mapCenter,
            keepOpen: true,
            confirm: (modal, done) => this.savePlace(place, done),
        });
    }

    @action closePlaceForm() {
        this.placeFormContext = null;
    }

    @action async savePlace(place, done) {
        // Entregas: o local marcado no mapa é obrigatório (o servidor também recusa sem ele)
        const coordenadas = coordenadasDoEndereco(place);
        if (!coordenadas) {
            this.notifications.warning(this.intl.t('customer-portal.ui.entregas.map-location-required'));
            return;
        }

        let createdPlace;
        this.modalsManager.startLoading();

        try {
            createdPlace = await this.customerPortalOrderActions.createPlace.perform({
                ...place,
                // o PlaceController do portal dá erro 500 sem a chave `name`, mesmo nula
                name: place.name ?? null,
                latitude: coordenadas.latitude,
                longitude: coordenadas.longitude,
            });
        } catch (error) {
            // ex.: endereço repetido (422); o modal continua aberto para a loja corrigir
            this.modalsManager.stopLoading();
            this.notifications.serverError(error);
            return;
        }

        this.createdPlaces = [createdPlace, ...this.createdPlaces];
        this.applyPlaceSelection(createdPlace);
        this.closePlaceForm();
        this.updateRoutePreview();
        this.notifications.success(this.intl.t('customer-portal.ui.place.address-saved'));
        done();
    }

    applyPlaceSelection(place) {
        this.customerPortalOrderCreation.setPayloadField(this.placeFormContext?.field ?? 'dropoff', place);
    }

    /** Entregas: o endereço é o Local da loja (a coleta)? Compara uuid e public_id. */
    isStorePlace(place) {
        const coleta = this.args.loja?.coleta;
        const daLoja = [coleta?.uuid, coleta?.public_id].filter(Boolean);

        if (!place || daLoja.length === 0) {
            return false;
        }

        return [valueFor(place, 'uuid'), valueFor(place, 'public_id'), valueFor(place, 'id')].some((id) => id && daLoja.includes(id));
    }

    updateRoutePreview() {
        // Entregas: a rota é só coleta (a loja) → destino
        const payload = this.customerPortalOrderCreation.draft?.payload ?? {};
        const places = [payload.pickup, payload.dropoff].filter(Boolean);
        this.customerPortalOrderCreation.updateRoutePreview(this.customerPortalOrderActions.coordinatesFromPlaces(places));
    }
}
