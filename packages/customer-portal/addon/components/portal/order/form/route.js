import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { restartableTask, timeout } from 'ember-concurrency';
import { valueFor } from '../../../../utils/model-access';

/**
 * Entregas: distância mínima entre o destino e a loja. O km da cobrança e do pagamento do motoboy sai das
 * coordenadas, e um ponto marcado na loja (o mapa abre nela) daria km ~0. O servidor confere o mesmo (RegrasPortalLoja).
 */
const DISTANCIA_MINIMA_DA_LOJA_METROS = 30;

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
 * Entregas: coordenadas do endereço, ou null. O mapa grava em `location` (GeoJSON, [lng, lat]) a cada movimento,
 * então ele vem antes de `latitude`/`longitude`; os endereços salvos também trazem `location`.
 */
function coordenadasDoEndereco(place) {
    const location = valueFor(place, 'location');
    const [lng, lat] = Array.isArray(location?.coordinates) ? location.coordinates : [];
    const candidatas = [
        [numero(lat), numero(lng)],
        [numero(valueFor(place, 'latitude')), numero(valueFor(place, 'longitude'))],
    ];

    for (const [latitude, longitude] of candidatas) {
        if (coordenadasValidas(latitude, longitude)) {
            return { latitude, longitude };
        }
    }

    return null;
}

/** Entregas: distância em metros entre dois pontos { latitude, longitude } (haversine). */
function metrosEntre(a, b) {
    const radianos = (graus) => (graus * Math.PI) / 180;
    const dLat = radianos(b.latitude - a.latitude);
    const dLng = radianos(b.longitude - a.longitude);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(radianos(a.latitude)) * Math.cos(radianos(b.latitude)) * Math.sin(dLng / 2) ** 2;

    return 2 * 6371000 * Math.asin(Math.min(1, Math.sqrt(h)));
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
        // Entregas: só endereços que o servidor aceita como destino
        return [...this.createdPlaces, ...(this.args.places ?? [])].filter((place) => this.canBeDropoff(place));
    }

    get actionButtons() {
        // Entregas: o endereço novo é o destino; sem botão de parada extra. Sem a loja com coordenadas (o minha-loja
        // falhou ou a loja não tem endereço), o botão fica desligado: o mapa não abriria na loja, a distância até ela
        // não seria conferida e o pedido nem pode ser criado (o aviso store-without-address aparece no bloco da coleta)
        return [
            {
                text: this.intl.t('customer-portal.ui.place.new-address'),
                icon: 'plus',
                size: 'xs',
                wrapperClass: 'portal-order-panel-action-button',
                disabled: !this.mapCenter,
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

        // Entregas: sem geocodificação (geo: false). Ela traria candidatos não salvos, sem uuid, que o servidor
        // recusa como destino; a latitude/longitude seguem só para ordenar os endereços salvos
        const places = yield this.customerPortalOrderActions.searchPlaceCandidates.perform({
            query: searchQuery,
            geo: false,
            latitude: center.latitude,
            longitude: center.longitude,
        });

        return places.filter((place) => this.canBeDropoff(place));
    }

    @action setPayloadPlace(field, place) {
        this.customerPortalOrderCreation.setPayloadField(field, place);
        this.updateRoutePreview();
    }

    @action openPlaceForm(field) {
        const place = {};

        this.modalsManager.show('modals/portal-order-place-form', {
            title: this.intl.t('customer-portal.ui.place.new-address'),
            modalClass: 'modal-md',
            acceptButtonText: this.intl.t('customer-portal.ui.place.save-address'),
            declineButtonText: this.intl.t('customer-portal.ui.common.cancel'),
            place,
            // Entregas: o modal só fecha quando o endereço é salvo; com dado faltando ou erro do servidor, fica aberto
            mapCenter: this.mapCenter,
            keepOpen: true,
            confirm: (modal, done) => this.savePlace(place, done),
        });

        // Entregas: o modal que acabou de abrir (o do topo); o loading do salvar vai para ele, e não para outro
        this.placeFormContext = { field, modalId: this.modalsManager.getTopModal()?.id };
    }

    @action closePlaceForm() {
        this.placeFormContext = null;
    }

    @action async savePlace(place, done) {
        // Entregas: rua obrigatória (o motoboy precisa do texto); só espaços conta como vazio
        if (typeof place.street1 !== 'string' || place.street1.trim() === '') {
            this.notifications.warning(this.intl.t('customer-portal.ui.entregas.street-required'));
            return;
        }

        // Entregas: o local marcado no mapa é obrigatório (o servidor também recusa sem ele)
        const coordenadas = coordenadasDoEndereco(place);
        if (!coordenadas) {
            this.notifications.warning(this.intl.t('customer-portal.ui.entregas.map-location-required'));
            return;
        }

        // Entregas: ponto na loja = km ~0 na cobrança e no pagamento; endereço salvo não se edita nem se apaga
        if (this.isAtStore(coordenadas)) {
            this.notifications.warning(this.intl.t('customer-portal.ui.entregas.map-location-at-store'));
            return;
        }

        const modalId = this.placeFormContext?.modalId;
        this.modalsManager.startLoading(modalId);

        try {
            const createdPlace = await this.customerPortalOrderActions.createPlace.perform({
                ...place,
                // o PlaceController do portal dá erro 500 sem a chave `name`, mesmo nula
                name: place.name ?? null,
                latitude: coordenadas.latitude,
                longitude: coordenadas.longitude,
            });

            this.createdPlaces = [createdPlace, ...this.createdPlaces];
            this.applyPlaceSelection(createdPlace);
            this.closePlaceForm();
            this.updateRoutePreview();
            this.notifications.success(this.intl.t('customer-portal.ui.place.address-saved'));
            done();
        } catch (error) {
            // ex.: endereço repetido (422); o modal continua aberto para a loja corrigir
            this.notifications.serverError(error);
        } finally {
            // Entregas: o loading sempre para (depois do done() o modal já fechou e isto não faz nada)
            this.modalsManager.stopLoading(modalId);
        }
    }

    applyPlaceSelection(place) {
        this.customerPortalOrderCreation.setPayloadField(this.placeFormContext?.field ?? 'dropoff', place);
    }

    /** Entregas: o ponto fica a menos de DISTANCIA_MINIMA_DA_LOJA_METROS da loja? Sem coordenadas da loja, não há como saber. */
    isAtStore(coordenadas) {
        const loja = this.mapCenter;

        return Boolean(loja) && metrosEntre(loja, coordenadas) < DISTANCIA_MINIMA_DA_LOJA_METROS;
    }

    /** Entregas: destino aceito pelo servidor: endereço salvo (com identificador), com coordenadas válidas e fora da loja. */
    canBeDropoff(place) {
        if (!place || this.isStorePlace(place) || !(valueFor(place, 'uuid') ?? valueFor(place, 'public_id') ?? valueFor(place, 'id'))) {
            return false;
        }

        const coordenadas = coordenadasDoEndereco(place);

        return Boolean(coordenadas) && !this.isAtStore(coordenadas);
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
