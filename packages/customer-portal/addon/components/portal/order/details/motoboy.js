import Component from '@glimmer/component';

// Entregas: o painel do motoboy no detalhe do pedido, só apresentação. O ciclo do detalhe consulta o motoboy e passa
// @motoboy ({ nome, foto, aceitou, latitude, longitude }; null = ninguém chamado ainda), @consultado (se a primeira
// resposta já veio; antes dela, o carregando) e @aceito (o detalhe relido já mostra o pedido aceito: sem motoboy na
// resposta, o painel fica no carregando em vez de "aguardando"). Com o pedido encerrado, o detalhe não mostra o painel

function coordenada(valor) {
    if (valor === null || valor === undefined || valor === '') {
        return null;
    }

    const numero = Number(valor);

    return Number.isFinite(numero) ? numero : null;
}

export default class PortalOrderDetailsMotoboyComponent extends Component {
    // só coordenadas válidas: nem nulas, nem fora da faixa, nem (0, 0), que é o "sem GPS" do Fleetbase (mesmo critério do servidor)
    get posicao() {
        const latitude = coordenada(this.args.motoboy?.latitude);
        const longitude = coordenada(this.args.motoboy?.longitude);

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
}
