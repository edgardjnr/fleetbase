import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { getOwner } from '@ember/application';

export default class PlaceFormComponent extends Component {
    @tracked coordinatesInput;

    // Entregas: posição de quem digita para a busca do Google. O formulário também abre em modais fora do engine, onde
    // o serviço location pode não existir: sem ele, o servidor usa o centro padrão.
    get posicao() {
        const location = getOwner(this)?.lookup('service:location');

        return location ? { latitude: location.latitude, longitude: location.longitude } : null;
    }

    @action onAutocomplete(selected) {
        this.args.resource.setProperties({ ...selected });

        if (this.coordinatesInput && selected.location) {
            this.coordinatesInput.updateCoordinates(selected.location);
        }
    }
}
