import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { later } from '@ember/runloop';
import { dropTask, restartableTask, timeout } from 'ember-concurrency';
import { novaSessaoDeBusca, temTextoParaBuscar } from '../utils/endereco-brasileiro';

/**
 * Entregas RestaurantePro: campo de rua com as sugestões do Google Places (GET int/v1/entregas/enderecos/sugestoes).
 * Ao escolher, busca os detalhes (rua e número, bairro, cidade, UF, CEP e o ponto em GeoJSON) e os entrega ao @onSelect.
 *
 * Argumentos: @value (o texto da rua, ligado ao campo), @onSelect(endereco), @latitude/@longitude (posição de quem
 * digita; sem elas o servidor usa a loja ou o centro padrão), @placeholder, @disabled, @wrapperClass.
 */
export default class EnderecoGoogleInputComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;

    @tracked sugestoes = [];
    @tracked aberto = false;

    sessao = novaSessaoDeBusca();
    ultimoTexto = '';

    get carregando() {
        return this.buscar.isRunning || this.escolher.isRunning;
    }

    @action aoDigitar(event) {
        this.buscar.perform(event.target.value);
    }

    @action aoEntrar() {
        this.aberto = this.sugestoes.length > 0;
    }

    @action aoSair() {
        // o mousedown da sugestão vem antes do blur; a espera só fecha a lista sem perder o clique
        later(this, () => (this.aberto = false), 200);
    }

    @action selecionar(sugestao, event) {
        event?.preventDefault();
        this.escolher.perform(sugestao);
    }

    @restartableTask *buscar(texto) {
        this.ultimoTexto = texto ?? '';
        if (!temTextoParaBuscar(texto)) {
            this.sugestoes = [];
            this.aberto = false;
            return;
        }

        yield timeout(300);

        const parametros = { texto: texto.trim(), sessao: this.sessao };
        if (this.args.latitude && this.args.longitude) {
            parametros.latitude = this.args.latitude;
            parametros.longitude = this.args.longitude;
        }

        try {
            const resultado = yield this.fetch.get('entregas/enderecos/sugestoes', parametros);
            this.sugestoes = Array.isArray(resultado) ? resultado : [];
        } catch {
            this.sugestoes = [];
        }
        this.aberto = this.sugestoes.length > 0;
    }

    @dropTask *escolher(sugestao) {
        this.aberto = false;

        try {
            const endereco = yield this.fetch.get(`entregas/enderecos/detalhes/${encodeURIComponent(sugestao.place_id)}`, {
                sessao: this.sessao,
                texto: this.ultimoTexto.trim(),
            });
            this.sessao = novaSessaoDeBusca();
            this.sugestoes = [];

            if (typeof this.args.onSelect === 'function') {
                this.args.onSelect(endereco);
            }
        } catch (error) {
            this.notifications.serverError(error, this.intl.t('ember-ui.endereco-google-input.erro'));
        }
    }
}
