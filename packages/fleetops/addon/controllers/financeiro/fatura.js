import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatadores } from '../../utils/financeiro';
import { chaveDoNaoEnvio, corDaFatura, dataBr, jaNaoCobrado, linhasCsvDaFatura, numeroOuRascunho, podeNaoCobrar, rotuloDaFatura } from '../../utils/financeiro-faturas';
import { selosDaCorrida } from '../../utils/financeiro-multiplicadores';

const ENDPOINT = 'entregas/financeiro/faturas';
const PREFIXO = 'fleet-ops.ui.financeiro-lojas';
const TIPOS = ['corrida', 'diaria', 'bonus', 'ajuste', 'saldo_inicial', 'pagamento', 'recebimento', 'estorno'];

/**
 * Entregas RestaurantePro: uma fatura de loja (Financeiro → Lojas e faturas → fatura).
 * - Rascunho: "Não cobrar" por corrida (ajuste negativo do valor inteiro, na própria fatura), "+ Ajuste" (desconto, taxa,
 *   mensalidade), vencimento editável e Emitir (número, total gravado, portal e e-mail da loja).
 * - Emitida: Registrar recebimento (modal da tela de motoboys, conta da loja), Reenviar e-mail, Imprimir e Cancelar (os
 *   lançamentos voltam para a próxima fatura; o número não é reaproveitado).
 * Itens = lançamentos da conta da loja ligados à fatura, mais antigos primeiro.
 */
export default class FinanceiroFaturaController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;
    @service hostRouter;

    @tracked id = null;
    @tracked fatura = null;
    @tracked itens = [];
    @tracked carregado = false;
    // vencimento digitado no rascunho ('Y-m-d'); igual ao da fatura = nada a salvar
    @tracked vencimento = '';

    get rascunho() {
        return this.fatura?.situacao === 'rascunho';
    }

    get emitida() {
        return this.fatura?.situacao === 'emitida';
    }

    get emAberto() {
        return this.emitida && Number(this.fatura?.em_aberto) > 0;
    }

    get numero() {
        return numeroOuRascunho(this.fatura, this.intl.t(`${PREFIXO}.rascunho`));
    }

    get rotulo() {
        return this.fatura ? this.intl.t(`${PREFIXO}.situacoes.${rotuloDaFatura(this.fatura)}`) : '';
    }

    get cor() {
        return corDaFatura(this.fatura);
    }

    get vencida() {
        return rotuloDaFatura(this.fatura) === 'vencida';
    }

    get vencimentoAlterado() {
        return this.rascunho && Boolean(this.vencimento) && this.vencimento !== this.fatura?.vencimento;
    }

    get temItens() {
        return this.itens.length > 0;
    }

    abrir(id) {
        if (this.id !== id) {
            this.limpar();
        }
        this.id = id;
        this.carregar.perform();
    }

    /** Zera a tela ao sair dela (resposta atrasada não regrava outra fatura). */
    limpar() {
        this.carregar.cancelAll();
        this.fatura = null;
        this.itens = [];
        this.carregado = false;
        this.vencimento = '';
    }

    @action valor(numero) {
        return formatadores(this.intl.primaryLocale).valor(numero);
    }

    @action km(valor) {
        if (valor === null || valor === undefined) {
            return '—';
        }
        return `${formatadores(this.intl.primaryLocale).km(Number(valor) || 0)} km`;
    }

    @action data(iso) {
        return dataBr(iso);
    }

    @action instante(iso) {
        return formatadores(this.intl.primaryLocale).instante(iso);
    }

    @action tipo(item) {
        return TIPOS.includes(item?.tipo) ? this.intl.t(`${PREFIXO}.tipos.${item.tipo}`) : String(item?.tipo ?? '');
    }

    @action podeNaoCobrar(item) {
        return podeNaoCobrar(item, this.fatura, this.itens);
    }

    @action naoCobrado(item) {
        return item?.tipo === 'corrida' && jaNaoCobrado(item, this.itens);
    }

    /** Selos da corrida: "2ª entrega" e "Chuva ×1,2" (o fator desta conta, dos detalhes do item). */
    @action selos(detalhes) {
        return selosDaCorrida(detalhes, this.intl.primaryLocale);
    }

    @action voltar() {
        return this.hostRouter.transitionTo('console.fleet-ops.financeiro.lojas');
    }

    @action setVencimento(event) {
        this.vencimento = event.target.value;
    }

    @task({ restartable: true }) *carregar() {
        const id = this.id;
        if (!id) {
            return;
        }

        try {
            const resposta = yield this.fetch.get(`${ENDPOINT}/${id}`);
            this.fatura = resposta?.fatura ?? null;
            this.itens = resposta?.itens ?? [];
            this.vencimento = this.fatura?.vencimento ?? '';
            this.carregado = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *salvarVencimento() {
        if (!this.vencimentoAlterado) {
            return;
        }

        try {
            yield this.fetch.put(`${ENDPOINT}/${this.id}`, { vencimento: this.vencimento });
            this.notifications.success(this.intl.t(`${PREFIXO}.vencimento-salvo`));
            yield this.carregar.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** "Não cobrar": ajuste negativo do valor inteiro da corrida, dentro do rascunho. */
    @action naoCobrar(item) {
        this.modalsManager.confirm({
            title: this.intl.t(`${PREFIXO}.nao-cobrar-titulo`),
            body: this.intl.t(`${PREFIXO}.nao-cobrar-texto`, { pedido: item.pedido?.numero || item.pedido?.id, valor: this.valor(item.valor) }),
            acceptButtonText: this.intl.t(`${PREFIXO}.nao-cobrar`),
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post(`${ENDPOINT}/${this.id}/nao-cobrar`, { lancamento_id: item.id });
                    this.notifications.success(this.intl.t(`${PREFIXO}.nao-cobrado`));
                    await this.carregar.perform();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    /** "+ Ajuste" no rascunho (modal ajuste-na-fatura). */
    @action adicionarAjuste() {
        this.modalsManager.show('modals/ajuste-na-fatura', {
            fatura: this.fatura,
            onSalvo: () => this.carregar.perform(),
        });
    }

    @action emitir() {
        this.modalsManager.confirm({
            title: this.intl.t(`${PREFIXO}.emitir-titulo`),
            body: this.intl.t(`${PREFIXO}.emitir-texto`, { loja: this.fatura?.loja?.nome, valor: this.valor(this.fatura?.total) }),
            acceptButtonText: this.intl.t(`${PREFIXO}.emitir`),
            acceptButtonIcon: 'paper-plane',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post(`${ENDPOINT}/${this.id}/emitir`);
                    this.notifications.success(this.intl.t(`${PREFIXO}.emitida`));
                    await this.carregar.perform();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action registrarRecebimento() {
        const fatura = this.fatura;
        this.modalsManager.show('modals/registrar-pagamento', {
            contaTipo: 'loja',
            contaId: fatura.loja?.id,
            nome: fatura.loja?.nome,
            // saldo real da loja: o modal busca (o recebimento quita primeiro a fatura mais antiga em aberto)
            sugestao: Number(fatura.em_aberto) || 0,
            fatura: fatura.id,
            onFinish: () => this.carregar.perform(),
        });
    }

    @task({ drop: true }) *reenviar() {
        try {
            const resposta = yield this.fetch.post(`${ENDPOINT}/${this.id}/reenviar`);
            if (resposta?.enviado === false) {
                this.notifications.warning(this.intl.t(`${PREFIXO}.${chaveDoNaoEnvio(resposta.motivo)}`));
            } else {
                this.notifications.success(this.intl.t(`${PREFIXO}.email-reenviado`));
            }
            yield this.carregar.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action cancelar() {
        const recebido = Number(this.fatura?.recebido) > 0;
        this.modalsManager.confirm({
            title: this.intl.t(`${PREFIXO}.cancelar-fatura-titulo`, { numero: this.numero }),
            body: recebido ? `${this.intl.t(`${PREFIXO}.cancelar-fatura-texto`)} ${this.intl.t(`${PREFIXO}.cancelar-fatura-recebido`)}` : this.intl.t(`${PREFIXO}.cancelar-fatura-texto`),
            acceptButtonText: this.intl.t(`${PREFIXO}.cancelar-fatura`),
            acceptButtonScheme: 'danger',
            acceptButtonIcon: 'ban',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post(`${ENDPOINT}/${this.id}/cancelar`);
                    this.notifications.success(this.intl.t(`${PREFIXO}.fatura-cancelada`));
                    await this.carregar.perform();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    /**
     * Imprime só a fatura: copia o bloco `[data-fatura-impressao]` para o <body> e o CSS de impressão
     * (fleetops-engine.css, "entregas-impressao") esconde o resto do console e os botões (`.entregas-nao-imprimir`).
     */
    @action imprimir() {
        const original = document.querySelector('[data-fatura-impressao]');
        if (!original) {
            return;
        }

        // cópia de uma impressão anterior sem afterprint (fica escondida na tela, mas não pode sair duas vezes no papel)
        document.querySelectorAll('.entregas-impressao-copia').forEach((velha) => velha.remove());

        const copia = original.cloneNode(true);
        copia.classList.add('entregas-impressao-copia');
        document.body.appendChild(copia);
        document.body.classList.add('entregas-imprimindo');

        const limpar = () => {
            copia.remove();
            document.body.classList.remove('entregas-imprimindo');
            window.removeEventListener('afterprint', limpar);
        };
        // o afterprint vem depois do diálogo, bloqueante ou não; a cópia fica com display:none fora da impressão
        window.addEventListener('afterprint', limpar);
        window.print();
    }

    @action exportarCsv() {
        const t = (chave) => this.intl.t(`${PREFIXO}.${chave}`);
        const rotulos = {
            fatura: t('fatura'),
            loja: t('loja'),
            periodo: t('periodo'),
            vencimento: t('vencimento'),
            data: t('data'),
            pedido: t('pedido'),
            km: t('km'),
            destino: t('destino'),
            motivo: t('motivo'),
            valor: t('valor'),
            total: t('total'),
            recebido: t('recebido'),
            emAberto: t('em-aberto'),
            rascunho: t('rascunho'),
            tipos: Object.fromEntries(TIPOS.map((tipo) => [tipo, t(`tipos.${tipo}`)])),
        };
        const linhas = linhasCsvDaFatura(this.fatura, this.itens, rotulos);
        const nome = this.fatura?.numero ? `fatura_${this.fatura.numero}` : `fatura_rascunho_${this.fatura?.periodo_fim ?? ''}`;

        const blob = new Blob(['﻿' + linhas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${nome}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }
}
