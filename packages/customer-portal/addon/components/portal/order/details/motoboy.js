import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { registerDestructor } from '@ember/destroyable';
import { task, timeout } from 'ember-concurrency';
import { valueFor } from '../../../../utils/model-access';

// Entregas: o motoboy do pedido no portal da loja (nome, foto e posição), relido a cada 20 s enquanto o pedido está
// aberto. GET int/v1/entregas/loja/pedidos/{id}/motoboy → { motoboy: null | { nome, foto, aceitou, latitude, longitude } }
const INTERVALO_MS = 20000;
// Entregas: a mesma lista do servidor (StatusDoPedido::ENCERRADOS). Com o pedido encerrado, o servidor não mostra mais
// o motoboy e o painel some
const ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled', 'order_canceled', 'expired'];

function coordenada(valor) {
    if (valor === null || valor === undefined || valor === '') {
        return null;
    }

    const numero = Number(valor);

    return Number.isFinite(numero) ? numero : null;
}

// quem está com o pedido: ninguém, um motoboy chamado (ainda sem aceitar) ou um motoboy que aceitou
function situacao(motoboy) {
    if (!motoboy) {
        return 'ninguem';
    }

    return motoboy.aceitou ? 'aceito' : 'chamado';
}

export default class PortalOrderDetailsMotoboyComponent extends Component {
    @service fetch;

    // o último motoboy que o servidor devolveu (null = ninguém chamado ainda)
    @tracked motoboy = null;
    // se já veio uma resposta 2xx; antes dela o painel mostra só o carregando
    @tracked consultado = false;

    constructor() {
        super(...arguments);
        this.acompanhar.perform();
        registerDestructor(this, () => this.acompanhar.cancelAll());
    }

    // public_id ou uuid (o id do record): o servidor aceita os dois
    get pedidoId() {
        return valueFor(this.args.resource, 'public_id') ?? valueFor(this.args.resource, 'id');
    }

    get encerrado() {
        return ENCERRADOS.includes(valueFor(this.args.resource, 'status'));
    }

    get visivel() {
        return Boolean(this.pedidoId) && !this.encerrado;
    }

    // só coordenadas válidas: nem nulas, nem fora da faixa, nem (0, 0), que é o "sem GPS" do Fleetbase (mesmo critério do servidor)
    get posicao() {
        const latitude = coordenada(this.motoboy?.latitude);
        const longitude = coordenada(this.motoboy?.longitude);

        if (latitude === null || longitude === null || Math.abs(latitude) > 90 || Math.abs(longitude) > 180) {
            return null;
        }

        if (Math.abs(latitude) <= 0.0001 && Math.abs(longitude) <= 0.0001) {
            return null;
        }

        return { latitude, longitude };
    }

    get temPosicao() {
        return Boolean(this.posicao);
    }

    @task *acompanhar() {
        while (this.visivel) {
            try {
                const resposta = yield this.fetch.get(`entregas/loja/pedidos/${encodeURIComponent(this.pedidoId)}/motoboy`);
                const motoboy = resposta?.motoboy;

                // só uma resposta 2xx no formato esperado troca o motoboy exibido (null = ninguém chamado ainda)
                if (motoboy !== undefined) {
                    // mudou quem está com o pedido (aceite, desistência ou pedido encerrado): o detalhe relê o pedido antes,
                    // para o status, o menu de ações e este painel mudarem juntos. Encerrado, o painel some, sem passar
                    // por "aguardando"
                    if (this.consultado && situacao(motoboy) !== situacao(this.motoboy) && typeof this.args.onMudou === 'function') {
                        yield this.args.onMudou();
                    }

                    this.motoboy = motoboy;
                    this.consultado = true;
                }
            } catch {
                // rede instável ou limite de consultas (429, throttle:60,1): mantém o último motoboy conhecido e tenta de
                // novo no próximo ciclo
            }

            yield timeout(INTERVALO_MS);
        }
    }
}
