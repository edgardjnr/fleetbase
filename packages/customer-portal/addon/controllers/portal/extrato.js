import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { restartableTask, timeout } from 'ember-concurrency';
import { differenceInCalendarDays, format, isValid, parseISO, startOfMonth } from 'date-fns';
import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';

// Entregas: o navegador dispara o `change` do campo de data a cada data válida durante a digitação (o ano 2026 passa por
// 0002, 0020 e 0202). A consulta só sai depois deste tempo sem novas alterações
const ESPERA_ALTERACAO_MS = 500;

// Entregas: quanto tempo o endereço do download (blob) continua válido. Revogar logo depois do click() cancela o download
// em navegadores que só leem o blob depois do click
const VALIDADE_DOWNLOAD_MS = 60000;

// Entregas: maior período por consulta, em dias entre o início e o fim (3 meses). É o MAX_DIAS_EXTRATO do servidor
// (PortalLojaController): conferir aqui evita a ida e a resposta em inglês
const MAX_DIAS = 92;

// Entregas: a tabela desenha este tanto de linhas por vez e "Mostrar mais" acrescenta outro tanto. Em 3 meses uma loja
// movimentada passa de 10 mil entregas, e cada linha tem uns 14 nós no DOM
const LINHAS_POR_VEZ = 200;

const FORMATO_DATA_API = 'yyyy-MM-dd';
const DATA_API = /^\d{4}-\d{2}-\d{2}$/;
const SEM_VALOR = '—';
const BOM_UTF8 = '\uFEFF';

// O Excel executa como fórmula a célula de texto que começa com = + - @ (o destino vem de endereço digitado por terceiros)
const INICIO_DE_FORMULA = /^[=+\-@\t\r]/;

const hoje = () => format(new Date(), FORMATO_DATA_API);
const inicioDoMes = () => format(startOfMonth(new Date()), FORMATO_DATA_API);

// Entregas: data que o usuário ainda está digitando. O campo de data entrega texto vazio enquanto está incompleto e, a cada
// dígito do ano, uma data válida (0002, 0020, 0202, até 2026); o ano também pode ter 5 ou 6 dígitos. Por isso só vale data
// no formato AAAA-MM-DD e de 2000 em diante. Comparar como texto serve porque todas têm esse formato
const DATA_MINIMA = '2000-01-01';
const dataCompleta = (data) => DATA_API.test(data) && data >= DATA_MINIMA;

// 'AAAA-MM-DD' no idioma ativo (sem hora nem fuso, o parseISO a lê como data local)
const dataCurta = (valor) => {
    const data = valor ? parseISO(String(valor)) : null;

    return data && isValid(data) ? format(data, 'P', dateFnsLocaleOptions()) : SEM_VALOR;
};

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
    @tracked linhasVisiveis = LINHAS_POR_VEZ;

    get entregas() {
        return this.dados?.entregas ?? [];
    }

    // Entregas: a tabela desenha só as primeiras linhasVisiveis; o CSV e os totais usam todas
    get entregasVisiveis() {
        return this.entregas.slice(0, this.linhasVisiveis);
    }

    get restantes() {
        return Math.max(0, this.entregas.length - this.linhasVisiveis);
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

    // Entregas: os campos mostram um período e os cartões e a tabela são de outro. Acontece depois de um erro (o limite de
    // chamadas, por exemplo), de um período inválido ou com a data ainda sendo digitada
    get desatualizado() {
        return Boolean(this.dados) && !this.carregar.isRunning && (this.dados.inicio !== this.inicio || this.dados.fim !== this.fim);
    }

    get esmaecido() {
        return this.atualizando || this.desatualizado;
    }

    get avisoDesatualizado() {
        if (!this.dados) {
            return '';
        }

        return this.intl.t('customer-portal.ui.entregas.stale-period', { start: dataCurta(this.dados.inicio), end: dataCurta(this.dados.fim) });
    }

    // Entregas: por que o período dos campos, já completo, não pode ser consultado: 'period-invalid' (fim antes do início) ou
    // 'period-too-long' (mais de 3 meses). null quando pode, ou quando uma data ainda está sendo digitada. Para trocar de
    // período o usuário passa por estados assim (volta no tempo mudando o início antes do fim, por exemplo), então na
    // digitação o motivo só aparece no aviso da tela, sem toast e sem consulta; o toast fica para o "Atualizar"
    get problemaPeriodo() {
        const { inicio, fim } = this;

        if (!dataCompleta(inicio) || !dataCompleta(fim)) {
            return null;
        }

        if (inicio > fim) {
            return 'period-invalid';
        }

        return differenceInCalendarDays(parseISO(fim), parseISO(inicio)) > MAX_DIAS ? 'period-too-long' : null;
    }

    get textoProblemaPeriodo() {
        switch (this.problemaPeriodo) {
            case 'period-invalid':
                return this.intl.t('customer-portal.ui.entregas.period-invalid');
            case 'period-too-long':
                return this.intl.t('customer-portal.ui.entregas.period-too-long');
            default:
                return '';
        }
    }

    // Entregas: há o que dizer sobre o período dos campos (o motivo de ele não ser consultado, dados de outro período na
    // tela, ou os dois). Vale também sem dados ainda, na primeira carga
    get temAvisoPeriodo() {
        return Boolean(this.problemaPeriodo) || this.desatualizado;
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
    // consultas por minuto) os dados que já estavam na tela ficam. O servidor responde em inglês ao período que recusa, então
    // o que ele recusaria (fim antes do início, mais de 3 meses) é conferido aqui e nem é consultado. Na digitação (com
    // espera) isso não vira toast: o motivo está no aviso da tela (problemaPeriodo) e o toast é do "Atualizar"
    @restartableTask *carregar(espera = 0) {
        const comEspera = typeof espera === 'number' && espera > 0;

        if (comEspera) {
            yield timeout(espera);
        }

        const { inicio, fim } = this;

        // data ainda sendo digitada: o aviso só sai no "Atualizar" (sem espera). Na digitação fica quieto e a consulta sai
        // quando a data fechar
        if (!dataCompleta(inicio) || !dataCompleta(fim)) {
            if (!comEspera) {
                this.notifications.warning(this.intl.t('customer-portal.ui.entregas.period-invalid'));
            }

            return;
        }

        // período completo que o servidor recusaria (fim antes do início, mais de 3 meses): igual à data incompleta, o aviso
        // só sai no "Atualizar"
        if (this.problemaPeriodo) {
            if (!comEspera) {
                this.notifications.warning(this.textoProblemaPeriodo);
            }

            return;
        }

        try {
            const dados = yield this.fetch.get('entregas/loja/extrato', { inicio, fim });

            // outro período volta às primeiras linhas; o "Atualizar" do mesmo período mantém as que já estavam abertas
            if (dados?.inicio !== this.dados?.inicio || dados?.fim !== this.dados?.fim) {
                this.linhasVisiveis = LINHAS_POR_VEZ;
            }

            this.dados = dados;
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
        this.linhasVisiveis = LINHAS_POR_VEZ;
    }

    @action atualizar() {
        this.carregar.perform();
    }

    @action mostrarMais() {
        this.linhasVisiveis += LINHAS_POR_VEZ;
    }

    @action setInicio(event) {
        this.inicio = event.target.value;
        this.carregar.perform(ESPERA_ALTERACAO_MS);
    }

    @action setFim(event) {
        this.fim = event.target.value;
        this.carregar.perform(ESPERA_ALTERACAO_MS);
    }

    // Data e hora da conclusão no idioma ativo (date-fns com o locale compartilhado, como no resto do portal). O servidor manda
    // no fuso da organização ("2026-10-31T22:30:00-03:00"): vale o relógio da própria string, sem o deslocamento, e não o do
    // navegador, para a entrega não mudar de dia (e de período) para quem está em outro fuso
    @action dataHora(valor) {
        const data = valor ? parseISO(String(valor).slice(0, 19)) : null;

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
            this.intl.t('customer-portal.ui.entregas.canceled-ifood-charged'),
        ];
        const linhas = this.entregas.map((entrega) => [
            this.dataHora(entrega.concluido_em),
            entrega.id_interno || entrega.pedido,
            entrega.destino ?? '',
            entrega.km == null ? '' : decimalCsv(entrega.km),
            entrega.faixa ? this.textoFaixa(entrega.faixa) : '',
            entrega.valor == null ? '' : decimalCsv(entrega.valor),
            // pedido do iFood cancelado pelo iFood depois da coleta, cobrado mesmo assim: vazio nas demais
            entrega.cancelado_pago ? this.intl.t('customer-portal.ui.entregas.canceled-ifood-charged') : '',
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
