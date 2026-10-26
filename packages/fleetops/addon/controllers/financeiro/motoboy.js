import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import {
    TIPOS_CHAVE_PIX,
    agruparPorDia,
    corDaSituacao,
    estaExcluido,
    formatadores,
    linhasCsvDoExtrato,
    periodoAoAbrir,
    periodoDoAtalho,
    podeEstornar,
    rotuloDaSituacao,
    rotuloDoTipo,
    validarPix,
} from '../../utils/financeiro';
import { selosDaCorrida } from '../../utils/financeiro-multiplicadores';
import { avisoDeCarencia, linkSeguro, seloDoSaque } from '../../utils/financeiro-saque-pix';

const ENDPOINT = 'entregas/financeiro/motoboys';
// tipos que aparecem na conta do motoboy (o filtro "tipo" do extrato)
const TIPOS_DO_MOTOBOY = ['corrida', 'diaria', 'bonus', 'ajuste', 'saldo_inicial', 'pagamento', 'estorno'];

/**
 * Entregas RestaurantePro: extrato de um motoboy no Financeiro. Saldo de agora, Pix (copiar e editar), filtros de período
 * e tipo, saldo anterior e totais do período, lançamentos agrupados por dia com a situação de cada crédito (calculada no
 * servidor com a conta inteira), Registrar pagamento, Lançar, Estornar por linha e CSV. Saque Pix pelo app: o interruptor
 * "Saque liberado / bloqueado" e o aviso de carência depois da troca da chave (bloco `saque` do extrato, só com o saque
 * ligado na central) e o selo "Saque Pix · app" com a situação e o comprovante do Asaas no pagamento de saque.
 * Formato "Extrato"/"Lancamento" do contrato (plano do financeiro, 1.5).
 */
export default class FinanceiroMotoboyController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;
    @service hostRouter;

    @tracked motoboyId = null;
    @tracked extrato = null;
    @tracked inicio = periodoDoAtalho('this-month').inicio;
    @tracked fim = periodoDoAtalho('this-month').fim;
    @tracked tipo = '';
    // edição da chave Pix (formulário na própria tela)
    @tracked editandoPix = false;
    @tracked pixTipo = 'cpf';
    @tracked pixChave = '';
    // atalho do período em uso (recalculado ao voltar à tela); null = datas escolhidas à mão
    atalho = 'this-month';

    get f() {
        return formatadores(this.intl.primaryLocale);
    }

    get nome() {
        return this.extrato?.conta?.nome ?? '';
    }

    /** Motoboy excluído do cadastro que ainda tem saldo (conta.excluido do extrato; ausente = não excluído). */
    get excluido() {
        return estaExcluido(this.extrato?.conta);
    }

    get pix() {
        return this.extrato?.conta?.pix ?? null;
    }

    get saldo() {
        return Number(this.extrato?.saldo) || 0;
    }

    get grupos() {
        return agruparPorDia(this.extrato?.lancamentos);
    }

    /** {bloqueado, liberado_em, alterada_por} do saque Pix; null com o saque desligado na central (sem interruptor). */
    get saque() {
        return this.extrato?.saque ?? null;
    }

    get carencia() {
        return avisoDeCarencia(this.saque);
    }

    get tiposDoFiltro() {
        return TIPOS_DO_MOTOBOY.map((tipo) => ({ valor: tipo, chave: rotuloDoTipo(tipo) }));
    }

    get tiposDePix() {
        return TIPOS_CHAVE_PIX.map((tipo) => ({ valor: tipo, chave: `fleet-ops.ui.financeiro.pix.tipos.${tipo}` }));
    }

    get erroDoPix() {
        const erro = validarPix(this.pixTipo, this.pixChave);
        return erro ? this.intl.t(`fleet-ops.ui.financeiro.erros.${erro}`) : null;
    }

    /** Abre o extrato de um motoboy; trocar de motoboy zera a tela (o período escolhido fica). */
    abrir(id) {
        if (this.motoboyId !== id) {
            this.limpar();
            this.motoboyId = id;
        }
        this.carregar.perform();
    }

    /** Na entrada da tela (setupController): o período do atalho em uso recalculado para hoje; datas à mão ficam. */
    atualizarPeriodo() {
        const periodo = periodoAoAbrir(this.atalho, { inicio: this.inicio, fim: this.fim });
        this.inicio = periodo.inicio;
        this.fim = periodo.fim;
    }

    limpar() {
        this.carregar.cancelAll();
        this.motoboyId = null;
        this.extrato = null;
        this.tipo = '';
        this.fecharPix();
    }

    @action voltar() {
        this.hostRouter.transitionTo('console.fleet-ops.financeiro.motoboys');
    }

    @action valor(numero) {
        return this.f.valor(numero);
    }

    @action dia(data) {
        return this.f.diaComSemana(data);
    }

    @action km(valor) {
        return this.f.km(valor);
    }

    @action rotuloTipo(tipo) {
        return this.intl.t(rotuloDoTipo(tipo));
    }

    @action rotuloSituacao(situacao) {
        const chave = rotuloDaSituacao(situacao);
        return chave ? this.intl.t(chave) : '';
    }

    @action corSituacao(situacao) {
        return corDaSituacao(situacao);
    }

    @action podeEstornar(lancamento) {
        return podeEstornar(lancamento);
    }

    /** Selos da corrida: "2ª entrega" e "Chuva ×1,2" (o fator desta conta, dos detalhes do lançamento). */
    @action selos(detalhes) {
        return selosDaCorrida(detalhes, this.intl.primaryLocale);
    }

    /** Selo "Saque Pix · app" do pagamento de saque: {status do Badge, chave da situação}. */
    @action seloDoSaque(saque) {
        return seloDoSaque(saque);
    }

    @action linkSeguro(url) {
        return linkSeguro(url);
    }

    /** O Toggle chama com o estado novo: ligado = saque liberado. */
    @action alternarSaque(liberado) {
        this.salvarSaque.perform(liberado);
    }

    @action setData(campo, event) {
        this[campo] = event.target.value;
        this.atalho = null;
    }

    @action setTipo(event) {
        this.tipo = event.target.value;
        this.carregar.perform();
    }

    @action setPeriodo(atalho) {
        const periodo = periodoDoAtalho(atalho);
        if (periodo) {
            this.atalho = atalho;
            this.inicio = periodo.inicio;
            this.fim = periodo.fim;
            this.carregar.perform();
        }
    }

    @action abrirPix() {
        this.pixTipo = this.pix?.tipo ?? 'cpf';
        this.pixChave = this.pix?.chave ?? '';
        this.editandoPix = true;
    }

    @action fecharPix() {
        this.editandoPix = false;
        this.pixChave = '';
    }

    @action setPix(campo, event) {
        this[campo] = event.target.value;
    }

    @action registrarPagamento() {
        this.modalsManager.show('modals/registrar-pagamento', {
            contaTipo: 'motoboy',
            contaId: this.motoboyId,
            nome: this.nome,
            saldo: this.saldo,
            onFinish: (opcoes) => {
                if (opcoes?.registrado) {
                    this.carregar.perform();
                }
            },
        });
    }

    @action lancar() {
        this.modalsManager.show('modals/lancar-no-financeiro', {
            contaTipo: 'motoboy',
            contas: [{ id: this.motoboyId, nome: this.nome }],
            onFinish: (opcoes) => {
                if (opcoes?.lancado) {
                    this.carregar.perform();
                }
            },
        });
    }

    @action estornar(lancamento) {
        this.modalsManager.show('modals/estornar-no-financeiro', {
            lancamento,
            onFinish: (opcoes) => {
                if (opcoes?.estornado) {
                    this.carregar.perform();
                }
            },
        });
    }

    @action exportarCsv() {
        if (!this.extrato) {
            return;
        }
        const linhas = linhasCsvDoExtrato(this.extrato, (chave) => this.intl.t(chave));
        const blob = new Blob(['﻿' + linhas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        const nome = String(this.nome || this.motoboyId)
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/[^a-zA-Z0-9]+/g, '-')
            .replace(/^-|-$/g, '')
            .toLowerCase();
        link.href = url;
        link.download = `extrato_${nome}_${this.inicio}_${this.fim}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    @task({ restartable: true }) *carregar() {
        const id = this.motoboyId;
        if (!id || !this.inicio || !this.fim) {
            return;
        }

        const consulta = { inicio: this.inicio, fim: this.fim };
        if (this.tipo) {
            consulta.tipo = this.tipo;
        }

        try {
            const extrato = yield this.fetch.get(`${ENDPOINT}/${id}/extrato`, consulta);
            // resposta atrasada de outro motoboy não regrava a tela
            if (this.motoboyId === id) {
                this.extrato = extrato;
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *salvarPix() {
        if (this.erroDoPix) {
            this.notifications.warning(this.erroDoPix);
            return;
        }

        try {
            yield this.fetch.put(`${ENDPOINT}/${this.motoboyId}/pix`, { tipo: this.pixTipo, chave: this.pixChave.trim() });
            this.fecharPix();
            this.notifications.success(this.intl.t('fleet-ops.ui.financeiro.pix.salvo'));
            yield this.carregar.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *salvarSaque(liberado) {
        try {
            yield this.fetch.put(`${ENDPOINT}/${this.motoboyId}/saque`, { bloqueado: liberado !== true });
            this.notifications.success(this.intl.t(`fleet-ops.ui.financeiro.saque-pix.motoboys.${liberado ? 'salvo-liberado' : 'salvo-bloqueado'}`, { nome: this.nome }));
            yield this.carregar.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
