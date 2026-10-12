import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { chaveDoAviso, chaveDoCanceladoPor, ehPedidoDaIntegracao, origemDoModelo } from '../../../utils/pedido-de-origem';

/**
 * Entregas: painel "Integração" no detalhe do pedido que veio de outro sistema (RestaurantePro…). Lê
 * GET int/v1/entregas/pedidos/{id}/integracao, rota só de administradores: para os demais o painel não aparece nem
 * carrega. Mostra o sistema e o id do pedido lá, a cobrança na porta, as observações, o último aviso entregue ao sistema,
 * a última falha de aviso da loja e o cancelamento. Nunca mostra o telefone do cliente (o servidor nem manda).
 */
export default class OrderDetailsIntegracaoComponent extends Component {
    @service fetch;
    @service currentUser;
    @service intl;
    @tracked painel = null;
    @tracked erro = false;
    // o pedido do último carregamento (não rastreado: só o `recarregar` lê e grava)
    ultimoId = null;

    get mostrar() {
        return ehPedidoDaIntegracao(this.args.resource) && this.currentUser.isAdmin === true;
    }

    get id() {
        return encodeURIComponent(this.args.resource?.public_id ?? this.args.resource?.id ?? '');
    }

    get titulo() {
        const origem = origemDoModelo(this.args.resource);

        return this.intl.t('fleet-ops.ui.integracao.painel-titulo', {
            sistema: this.painel?.sistema ?? origem?.sistema ?? '',
            numero: this.painel?.numero ?? origem?.numero ?? '',
        });
    }

    get cobrancaTexto() {
        const cobranca = this.painel?.cobranca;
        if (cobranca?.texto) {
            return cobranca.texto;
        }

        return cobranca?.forma === 'pago' ? this.intl.t('fleet-ops.ui.integracao.pago') : this.intl.t('fleet-ops.ui.integracao.sem-cobranca');
    }

    get avisoTexto() {
        const aviso = this.painel?.ultimo_aviso;

        return aviso ? this.intl.t(`fleet-ops.ui.integracao.aviso.${chaveDoAviso(aviso)}`) : this.intl.t('fleet-ops.ui.integracao.nenhum-aviso');
    }

    get canceladoTexto() {
        const painel = this.painel;
        if (!painel?.cancelado_em) {
            return null;
        }
        const por = this.intl.t(`fleet-ops.ui.integracao.cancelado-por.${chaveDoCanceladoPor(painel.cancelado_por)}`);

        return this.intl.t(painel.pago_mesmo_cancelado ? 'fleet-ops.ui.integracao.cancelado-pago' : 'fleet-ops.ui.integracao.cancelado', { por });
    }

    get falhaTexto() {
        const painel = this.painel;
        if (!painel?.ultima_falha_aviso) {
            return null;
        }

        return this.intl.t('fleet-ops.ui.integracao.falha', { texto: painel.ultima_falha_aviso, quando: this.dataHora(painel.ultima_falha_aviso_em) });
    }

    dataHora(iso) {
        const data = iso ? new Date(iso) : null;
        if (!data || Number.isNaN(data.getTime())) {
            return '—';
        }

        return data.toLocaleString(this.intl.primaryLocale ?? 'pt-BR', { dateStyle: 'short', timeStyle: 'short' });
    }

    /**
     * Mesmo cuidado do painel iFood: o Glimmer reaproveita o componente quando o @resource muda. Carrega na entrada e a
     * cada mudança de id, status ou atualização do pedido. Se trocou de pedido, zera o painel antes.
     */
    @action recarregar() {
        const id = this.id;
        if (id !== this.ultimoId) {
            this.ultimoId = id;
            this.painel = null;
            this.erro = false;
        }
        if (this.mostrar) {
            this.carregar.perform();
        }
    }

    @task({ restartable: true }) *carregar() {
        try {
            this.painel = yield this.fetch.get(`entregas/pedidos/${this.id}/integracao`);
            this.erro = false;
        } catch (error) {
            this.erro = true;
        }
    }
}
