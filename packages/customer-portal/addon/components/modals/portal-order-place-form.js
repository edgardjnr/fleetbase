import Component from '@glimmer/component';
import { action, setProperties } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class ModalsPortalOrderPlaceFormComponent extends Component {
    @tracked coordinatesInput;

    get place() {
        const place = this.args.options.place;

        if (place && typeof place.setProperties !== 'function') {
            place.setProperties = (properties = {}) => setProperties(place, properties);
        }

        return place;
    }

    // Entregas: endereço escolhido nas sugestões do Google (EnderecoGoogleInput): rua e número, bairro, cidade, UF, CEP
    // e o ponto no mapa
    @action onAutocomplete(selected) {
        this.place.setProperties({ ...selected });

        if (this.coordinatesInput && selected.location) {
            this.coordinatesInput.updateCoordinates(selected.location);
        }
    }
}
