import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { restartableTask, timeout } from 'ember-concurrency';
import { format, isValid, parseISO, startOfMonth } from 'date-fns';
import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';

// Entregas: o navegador dispara o `change` do campo de data a cada data válida durante a digitação (o ano 2026 passa por
// 0002, 0020 e 0202). A consulta só sai depois deste tempo sem novas alterações
const ESPERA_ALTERACAO_MS = 500;

// Entregas: quanto tempo o endereço do download (blob) continua válido. Revogar logo depois do click() cancela o download
// em navegadores que só leem o blob depois do click
const VALIDADE_DOWNLOAD_MS = 60000;

const FORMATO_DATA_API = 'yyyy-MM-dd';
const DATA_API = /^\d{4}-\d{2}-\d{2}$/;
const SEM_VALOR = '—';
const BOM_UTF8 = '\uFEFF';

// O Excel executa como fórmula a célula de texto que começa com = + - @ (o destino vem de endereço digitado por terceiros)
const INICIO_DE_FORMULA = /^[=+\-@\t\r]/;

const hoje = () => format(new Date(), FORMATO_DATA_API);
const inicioDoMes = () => format(startOfMonth(new Date()), FORMATO_DATA_API);

// Entregas: o campo de data entrega texto vazio enquanto a data está incompleta, e o ano pode ter 5 ou 6 dígitos. A comparação
// como texto vale porque as duas datas têm o formato AAAA-MM-DD
const periodoValido = (inicio, fim) => DATA_API.test(inicio) && DATA_API.test(fim) && inicio <= fim;

// Número com vírgula decimal e duas casas, como o Excel brasileiro lê
const decimalCsv = (valor) => Number(valor).toFixed(2).replace('.', ',');

const celulaCsv = (valor) => {
    let texto = String(valor ?? '');

    if (INICIO_DE_FORMULA.test(texto)) {
        texto = `'${texto}`;
    }

    return `"${texto.replace(/"/g, '""')}"`;
};

export default class PortalExtratoController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;

    @tracked inicio = inicioDoMes();
    @tracked fim = hoje();
    @tracked dados = null;

    get entregas() {
        return this.dados?.entregas ?? [];
    }

    get temEntregas() {
        return this.entregas.length > 0;
    }

    get pendentes() {
        return Number(this.dados?.pendentes) || 0;
    }

    // Já há dados na tela e uma nova consulta está em andamento (os dados antigos continuam visíveis, só esmaecidos)
    get atualizando() {
        return Boolean(this.dados) && this.carregar.isRunning;
    }

    get totalEntregas() {
        return this.dados ? this.intl.formatNumber(Number(this.dados.totais?.entregas) || 0) : SEM_VALOR;
    }

    get totalKm() {
        return this.dados ? this.intl.formatNumber(Number(this.dados.totais?.km) || 0, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : SEM_VALOR;
    }

    get totalValor() {
        return this.dados ? this.dinheiro(Number(this.dados.totais?.valor) || 0) : SEM_VALOR;
    }

    // Entregas: uma consulta por vez. Uma nova (outro período, "Atualizar") cancela a anterior, inclusive a que ainda espera
    // o fim da digitação. O período vale o que está nos campos quando a espera termina. Com erro (inclusive o limite de
    // consultas por minuto) os dados que já estavam na tela ficam
    @restartableTask *carregar(espera = 0) {
        if (typeof espera === 'number' && espera > 0) {
            yield timeout(espera);
        }

        const { inicio, fim } = this;

        // o servidor responderia em inglês a um período invertido, então nem é consultado
        if (!periodoValido(inicio, fim)) {
            this.notifications.warning(this.intl.t('customer-portal.ui.entregas.period-invalid'));
            return;
        }

        try {
            this.dados = yield this.fetch.get('entregas/loja/extrato', { inicio, fim });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // Entregas: chamado pela rota ao sair da tela
    reiniciar() {
        this.carregar.cancelAll();
        this.inicio = inicioDoMes();
        this.fim = hoje();
        this.dados = null;
    }

    @action atualizar() {
        this.carregar.perform();
    }

    @action setInicio(event) {
        this.inicio = event.target.value;
        this.carregar.perform(ESPERA_ALTERACAO_MS);
    }

    @action setFim(event) {
        this.fim = event.target.value;
        this.carregar.perform(ESPERA_ALTERACAO_MS);
    }

    // Data e hora da conclusão no idioma ativo (date-fns com o locale compartilhado, como no resto do portal)
    @action dataHora(valor) {
        const data = valor ? parseISO(valor) : null;

        return data && isValid(data) ? format(data, 'P p', dateFnsLocaleOptions()) : SEM_VALOR;
    }

    @action distancia(valor) {
        return valor == null ? SEM_VALOR : this.intl.formatNumber(Number(valor), { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    @action textoFaixa(faixa) {
        if (!faixa) {
            return SEM_VALOR;
        }

        const de = this.intl.formatNumber(Number(faixa.de_km), { maximumFractionDigits: 2 });

        if (faixa.acima) {
            return this.intl.t('customer-portal.ui.entregas.band-above', { from: de });
        }

        return this.intl.t('customer-portal.ui.entregas.band-range', { from: de, to: this.intl.formatNumber(Number(faixa.ate_km), { maximumFractionDigits: 2 }) });
    }

    // Valor em reais. Sem valor (entrega sem km calculado, ou sem faixa cadastrada) mostra o traço, e não R$ 0,00
    @action dinheiro(valor) {
        return valor == null ? SEM_VALOR : this.intl.formatNumber(Number(valor), { style: 'currency', currency: 'BRL' });
    }

    // CSV para o Excel brasileiro: separador ";", vírgula decimal e BOM do UTF-8 (sem ele os acentos saem errados)
    @action baixarCsv() {
        if (!this.temEntregas) {
            return;
        }

        const cabecalho = [
            this.intl.t('customer-portal.ui.entregas.completed-at'),
            this.intl.t('customer-portal.ui.entregas.order'),
            this.intl.t('customer-portal.ui.entregas.destination'),
            this.intl.t('customer-portal.ui.entregas.km'),
            this.intl.t('customer-portal.ui.entregas.band'),
            this.intl.t('customer-portal.ui.entregas.amount'),
        ];
        const linhas = this.entregas.map((entrega) => [
            this.dataHora(entrega.concluido_em),
            entrega.id_interno || entrega.pedido,
            entrega.destino ?? '',
            entrega.km == null ? '' : decimalCsv(entrega.km),
            entrega.faixa ? this.textoFaixa(entrega.faixa) : '',
            entrega.valor == null ? '' : decimalCsv(entrega.valor),
        ]);
        const csv = BOM_UTF8 + [cabecalho, ...linhas].map((linha) => linha.map(celulaCsv).join(';')).join('\r\n');

        // o nome vem do período que os dados trazem: com um erro depois de mudar a data, os campos podem estar em outro
        const inicio = this.dados.inicio ?? this.inicio;
        const fim = this.dados.fim ?? this.fim;
        const endereco = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');

        link.href = endereco;
        link.download = `extrato_${inicio}_${fim}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();

        setTimeout(() => URL.revokeObjectURL(endereco), VALIDADE_DOWNLOAD_MS);
    }
}
