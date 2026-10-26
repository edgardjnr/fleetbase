import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatadores, valorInterpretado } from '../../../utils/financeiro';
import { API_DO_SAQUE_PIX, limitesParaEdicao, validarLimites } from '../../../utils/financeiro-saque-pix';

const P = 'fleet-ops.ui.financeiro.saque-pix';

/**
 * Entregas RestaurantePro: passo 3 do card Saque Pix. Interruptor do saque (travado até a validação confirmada; desligar
 * sempre pode) e os limites da central: mínimo por saque, máximo por dia, saques por dia e taxa. PUT
 * int/v1/entregas/financeiro/saque-pix (SaquePixController@configurar): o interruptor vai sozinho, os limites pelo
 * "Salvar limites", com as mesmas regras do servidor (validarLimites).
 */
export default class FinanceiroSaquePixLigarComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked campos;

    constructor() {
        super(...arguments);
        this.campos = limitesParaEdicao(this.args.conexao?.limites);
    }

    get ligado() {
        return this.args.conexao?.saque_ligado === true;
    }

    /** Ligar só com a validação confirmada; desligar sempre pode (ex.: ligado e um teste novo em andamento). */
    get interruptorTravado() {
        return (!this.args.etapas?.podeLigar && !this.ligado) || this.salvarLigado.isRunning;
    }

    get validacao() {
        return validarLimites(this.campos);
    }

    get erro() {
        const codigo = this.validacao.erro;
        return codigo ? this.intl.t(`${P}.erros.${codigo}`) : null;
    }

    /** "= R$ 1.500,00" ao lado do campo (vazio enquanto o campo é inválido). */
    @action interpretado(valor) {
        return valorInterpretado(valor, formatadores(this.intl.primaryLocale));
    }

    @action setCampo(campo, event) {
        this.campos = { ...this.campos, [campo]: event.target.value };
    }

    /** O Toggle chama com o estado novo. */
    @action ligar(ligado) {
        this.salvarLigado.perform(ligado);
    }

    @task({ drop: true }) *salvarLigado(ligado) {
        try {
            const conexao = yield this.fetch.put(API_DO_SAQUE_PIX, { saque_ligado: ligado === true });
            this.args.aoMudar(conexao);
            this.notifications.success(this.intl.t(conexao?.saque_ligado ? `${P}.ligar.ligado` : `${P}.ligar.desligado`));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *salvarLimites() {
        const { erro, corpo } = this.validacao;
        if (erro) {
            this.notifications.warning(this.erro);
            return;
        }
        try {
            const conexao = yield this.fetch.put(API_DO_SAQUE_PIX, corpo);
            this.args.aoMudar(conexao);
            this.campos = limitesParaEdicao(conexao?.limites);
            this.notifications.success(this.intl.t(`${P}.ligar.salvos`));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
