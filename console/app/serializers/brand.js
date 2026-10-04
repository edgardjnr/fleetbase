import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';
import { EmbeddedRecordsMixin } from '@ember-data/serializer/rest';

// Entregas RestaurantePro: ícone e logo padrão da Fleetbase que a API manda quando ninguém enviou outra imagem em
// Admin → Marca. Vazios, as telas usam o fallback local (/images/icon.png e /images/logo.png, do RestaurantePro).
const IMAGEM_PADRAO_FLEETBASE = /flb-assets[^/]*\/static\/fleetbase-(icon|logo)\.(png|svg)$/i;

export function semImagemDaFleetbase(url) {
    return typeof url === 'string' && IMAGEM_PADRAO_FLEETBASE.test(url) ? null : url;
}

export default class BrandSerializer extends ApplicationSerializer.extend(EmbeddedRecordsMixin) {
    normalize(modelClass, hash, ...rest) {
        if (hash && typeof hash === 'object') {
            hash = { ...hash, icon_url: semImagemDaFleetbase(hash.icon_url), logo_url: semImagemDaFleetbase(hash.logo_url) };
        }

        return super.normalize(modelClass, hash, ...rest);
    }
}
