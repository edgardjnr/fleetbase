import Component from '@glimmer/component';
import iconeDoLocal from '../../utils/entregas-icone-da-loja';

export default class PlaceDetailsComponent extends Component {
    /** Entregas: pin laranja da loja no lugar do predinho preto (avatar próprio do local continua valendo). */
    get icone() {
        return iconeDoLocal(this.args.resource, 40);
    }
}
