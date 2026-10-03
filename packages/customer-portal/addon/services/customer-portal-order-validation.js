import Service from '@ember/service';

export default class CustomerPortalOrderValidationService extends Service {
    canCreate(draft) {
        return this.canQuote(draft);
    }

    canQuote(draft) {
        if (!draft?.orderConfig) {
            return false;
        }

        // Entregas: a coleta é sempre o endereço da loja (o servidor grava); a loja só informa o destino
        return Boolean(draft.payload?.dropoff);
    }
}
