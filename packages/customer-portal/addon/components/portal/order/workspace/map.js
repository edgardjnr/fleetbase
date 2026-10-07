import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { race, task, timeout, waitForEvent } from 'ember-concurrency';
import CamadaDeMotoboys from '../../../../utils/camada-de-motoboys';
import { motoboyDoPedido, motoboysValidos } from '../../../../utils/motoboys-no-mapa';
import { INTERVALO_MAPA_MS, espera } from '../../../../utils/entregas-pedido';
import { ALFINETE, mesmaLista, nomeNoAlfinete, pedidosValidos, semOPedidoAberto, tempoDesde } from '../../../../utils/pedidos-no-mapa';
import { PIN_DA_LOJA, lojaNoMapa, mostrarPinDaLoja } from '../../../../utils/loja-no-mapa';

export default class PortalOrderWorkspaceMapComponent extends Component {
    @service customerPortalOrderRoutePreview;
    @service fetch;
    @service intl;

    // Entregas: os capacetes dos motoboys (utils/camada-de-motoboys) e a última lista que a API devolveu
    camadaDeMotoboys = null;
    ultimosMotoboys = [];

    // Entregas: os pedidos em andamento da loja (alfinete vermelho no endereço de entrega) e o relógio do "há X min"
    @tracked pedidosNoMapa = [];
    @tracked relogio = Date.now();
    _iconeDoAlfinete = null;
    nomeNoAlfinete = nomeNoAlfinete;

    // Entregas: o pin da loja no Local dela (a coleta), lido uma vez de entregas/loja/minha-loja
    @tracked loja = null;
    _iconeDaLoja = null;

    // Entregas: o mapa acompanha o tamanho do palco (que segue a altura da tela); o Leaflet só percebe a janela mudar
    _observadorDoTamanho = null;

    willDestroy() {
        super.willDestroy(...arguments);
        this._observadorDoTamanho?.disconnect();
        this._observadorDoTamanho = null;
        this.camadaDeMotoboys?.destruir();
        this.camadaDeMotoboys = null;
        this.customerPortalOrderRoutePreview.unregisterMap();
    }

    get center() {
        const firstMarker = this.markers[0] ?? this.loja;

        // Entregas: sem marcadores o mapa abre na loja e, sem ela, em Ribeirão Preto (e não em Singapura)
        return {
            latitude: Number(firstMarker?.latitude) || -21.1775,
            longitude: Number(firstMarker?.longitude) || -47.8103,
        };
    }

    get markers() {
        return [
            ...this.customerPortalOrderRoutePreview.routePoints.map((point) => this.markerForRoutePoint(point)),
            ...this.customerPortalOrderRoutePreview.selectedOrderRoutePoints.map((point) => this.markerForRoutePoint(point, 'selected')),
        ].slice(0, 150);
    }

    // Entregas: o pedido aberto no detalhe já mostra P e D pela rota: o alfinete dele sai
    get alfinetes() {
        return semOPedidoAberto(this.pedidosNoMapa, this.args.selectedOrder?.public_id ?? null);
    }

    // Entregas: o pin da loja, menos quando o mapa já mostra a coleta pelo P (pedido aberto ou novo pedido)
    get pinDaLoja() {
        return mostrarPinDaLoja(this.loja, this.markers) ? this.loja : null;
    }

    get iconeDaLoja() {
        const L = globalThis.L;

        if (!this._iconeDaLoja && L?.icon) {
            this._iconeDaLoja = L.icon({
                iconUrl: PIN_DA_LOJA.url,
                iconSize: PIN_DA_LOJA.tamanho,
                iconAnchor: PIN_DA_LOJA.ponta,
                popupAnchor: PIN_DA_LOJA.popup,
                tooltipAnchor: PIN_DA_LOJA.dica,
            });
        }

        return this._iconeDaLoja ?? undefined;
    }

    // um ícone só para todos os alfinetes (criado quando o Leaflet já existe)
    get iconeDoAlfinete() {
        const L = globalThis.L;

        if (!this._iconeDoAlfinete && L?.icon) {
            this._iconeDoAlfinete = L.icon({ iconUrl: ALFINETE.url, iconSize: ALFINETE.tamanho, iconAnchor: ALFINETE.ponta, popupAnchor: ALFINETE.popup });
        }

        return this._iconeDoAlfinete ?? undefined;
    }

    // "há X min" do alfinete; o relogio (atualizado a cada consulta) faz o texto andar
    tempoDoPedido = (pedido, relogio) => {
        const tempo = tempoDesde(pedido?.criado_em, relogio);

        return tempo ? this.intl.t(`customer-portal.ui.entregas.mapa-pedido-ha-${tempo.unidade}`, { n: tempo.n }) : '';
    };

    markersForOrder(order, source = 'order') {
        const payload = order?.payload;
        const markers = [];

        if (payload?.pickup) {
            markers.push(this.markerForPlace(payload.pickup, order, this.intl.t('customer-portal.ui.order.pickup'), 'pickup', 0, source));
        }

        (payload?.waypoints ?? []).forEach((waypoint, index) => {
            markers.push(this.markerForPlace(waypoint.place ?? waypoint, order, this.intl.t('customer-portal.ui.order.stop-n', { n: index + 1 }), 'waypoint', index + 1, source));
        });

        if (payload?.dropoff) {
            markers.push(this.markerForPlace(payload.dropoff, order, this.intl.t('customer-portal.ui.order.dropoff'), 'dropoff', markers.length, source));
        }

        return markers.filter((marker) => marker?.latitude && marker?.longitude);
    }

    markerForPlace(place, order, label, type, sequence, source) {
        const coordinates = this.customerPortalOrderRoutePreview.coordinatesFromPlace(place);

        if (!coordinates) {
            return null;
        }

        return {
            ...place,
            id: `${source}-${order?.public_id ?? order?.id ?? 'draft'}-${type}-${sequence}`,
            order,
            label,
            source,
            type,
            latitude: coordinates[0],
            longitude: coordinates[1],
            icon: this.iconForMarker(type, sequence, source),
        };
    }

    markerForRoutePoint(point, source = 'draft') {
        return {
            ...(point.place ?? {}),
            id: point.id,
            order: null,
            label: point.label,
            source,
            type: point.type,
            latitude: point.coordinates[0],
            longitude: point.coordinates[1],
            icon: this.iconForMarker(point.type, point.index, source),
        };
    }

    iconForMarker(type, sequence, source) {
        const L = globalThis.L;

        if (!L?.divIcon) {
            return undefined;
        }

        const label = this.markerLabel(type, sequence);
        const markerType = ['pickup', 'dropoff', 'return'].includes(type) ? type : 'waypoint';

        return L.divIcon({
            className: 'portal-order-route-marker-icon',
            html: `<div class="portal-order-route-marker portal-order-route-marker-${markerType} portal-order-route-marker-${source}"><span>${label}</span></div>`,
            iconSize: [34, 42],
            iconAnchor: [17, 38],
            popupAnchor: [0, -36],
            tooltipAnchor: [0, -30],
        });
    }

    markerLabel(type, sequence) {
        if (type === 'pickup') {
            return 'P';
        }

        if (type === 'dropoff') {
            return 'D';
        }

        if (type === 'return') {
            return 'R';
        }

        return String(Number(sequence ?? 0) + 1);
    }

    @action setupMap(event) {
        this.customerPortalOrderRoutePreview.registerMap(event);
        this.adicionarZoom(event?.target ?? event);
        this.acompanharTamanho(event?.target ?? event);
        this.carregarLoja.perform();
        // Entregas: os capacetes dos motoboys, relidos a cada 5 s enquanto o mapa existe
        this.camadaDeMotoboys?.destruir();
        this.camadaDeMotoboys = new CamadaDeMotoboys(event?.target ?? event);
        this.syncRoutePreview();
        this.acompanharMotoboys.perform();
    }

    // Entregas: botões + e − no canto inferior direito (o canto superior esquerdo fica sob a lista de pedidos), com as dicas
    // no idioma ativo. O CSS os afasta do painel do pedido quando ele está aberto
    adicionarZoom(map) {
        const L = globalThis.L;

        if (!L?.control?.zoom || typeof map?.addControl !== 'function') {
            return;
        }

        map.addControl(
            L.control.zoom({
                position: 'bottomright',
                zoomInTitle: this.intl.t('customer-portal.ui.entregas.zoom-in'),
                zoomOutTitle: this.intl.t('customer-portal.ui.entregas.zoom-out'),
            })
        );
    }

    // Entregas: o palco do mapa muda de altura com a tela (CSS) e com o cabeçalho; sem o invalidateSize, o Leaflet fica com
    // o tamanho antigo (faixa cinza sem mapa ou marcadores fora do lugar)
    acompanharTamanho(map) {
        const container = map?.getContainer?.();

        this._observadorDoTamanho?.disconnect();
        this._observadorDoTamanho = null;

        if (!container || typeof ResizeObserver === 'undefined') {
            return;
        }

        this._observadorDoTamanho = new ResizeObserver(() => {
            if (!this.isDestroying && !this.isDestroyed) {
                map.invalidateSize({ pan: false });
            }
        });
        this._observadorDoTamanho.observe(container);
    }

    // Entregas: a loja (nome, endereço e Local) para o pin; em falha, tenta mais duas vezes e desiste sem pin
    @task({ drop: true }) *carregarLoja() {
        for (let tentativa = 1; tentativa <= 3 && !this.loja; tentativa++) {
            try {
                this.loja = lojaNoMapa(yield this.fetch.get('entregas/loja/minha-loja'));
                return;
            } catch {
                if (tentativa < 3) {
                    yield timeout(espera(INTERVALO_MAPA_MS, tentativa));
                }
            }
        }
    }

    @action syncRoutePreview() {
        if (this.args.selectedOrder) {
            this.customerPortalOrderRoutePreview.updateSelectedOrder(this.args.selectedOrder);
        } else {
            this.customerPortalOrderRoutePreview.clearSelectedOrder();
            this.customerPortalOrderRoutePreview.updateDraft(this.args.draft);
        }

        // Entregas: outro pedido aberto (ou nenhum): o destaque e o enquadramento do motoboy mudam na hora, com a última lista
        this.desenharMotoboys();
    }

    // Entregas: os motoboys no mapa (GET int/v1/entregas/loja/motoboys) a cada 5 s, enquanto o mapa existe. Com a aba
    // oculta, não consulta; a próxima volta começa assim que ela aparece. Em falha (429 inclusive), os capacetes ficam onde
    // estavam e a espera dobra até 2 min. O EC cancela a task quando o mapa sai da tela (Tabela ou outra página)
    @task({ restartable: true }) *acompanharMotoboys() {
        let falhas = 0;

        while (!this.isDestroying && !this.isDestroyed) {
            const oculta = document.hidden;
            let decorrido = 0;

            if (!oculta) {
                const inicio = Date.now();
                let resposta;

                try {
                    resposta = yield this.fetch.get('entregas/loja/motoboys');
                    falhas = 0;
                } catch {
                    falhas++;
                }

                decorrido = Date.now() - inicio;

                if (resposta) {
                    this.ultimosMotoboys = Array.isArray(resposta.motoboys) ? resposta.motoboys : [];

                    // os alfinetes dos pedidos da loja vêm na mesma resposta; API antiga (sem pedidos) = nenhum alfinete
                    const pedidos = pedidosValidos(resposta.pedidos);
                    if (!mesmaLista(pedidos, this.pedidosNoMapa)) {
                        this.pedidosNoMapa = pedidos;
                    }
                    this.relogio = Date.now();

                    // erro ao desenhar não é falha da consulta: não aumenta a espera
                    try {
                        this.desenharMotoboys();
                    } catch (erro) {
                        debug('Entregas: falha ao desenhar os motoboys no mapa: ' + erro.message);
                    }
                }
            }

            // com a consulta em dia, a próxima sai 5 s depois do início desta, e não do fim: o deslize de 4,5 s termina perto
            // da posição seguinte, e o capacete não para a cada volta. Em falha, vale a espera crescente inteira
            const proxima = timeout(Math.max(0, espera(INTERVALO_MAPA_MS, falhas) - (falhas ? 0 : decorrido)));
            yield oculta ? race([proxima, waitForEvent(document, 'visibilitychange')]) : proxima;
        }
    }

    // Entregas: desenha os capacetes da última consulta, com o motoboy do pedido aberto destacado, e informa ao serviço da
    // rota onde ele está (para o enquadramento)
    desenharMotoboys() {
        const motoboys = motoboysValidos(this.ultimosMotoboys);
        const publicId = this.args.selectedOrder?.public_id ?? null;
        const doPedido = motoboyDoPedido(motoboys, publicId);

        this.camadaDeMotoboys?.atualizar(motoboys, { destaque: doPedido?.id ?? null });
        this.customerPortalOrderRoutePreview.definirMotoboyDoPedido(publicId, doPedido ? [Number(doPedido.latitude), Number(doPedido.longitude)] : null);
    }
}
