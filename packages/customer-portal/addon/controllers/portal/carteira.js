import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { restartableTask, dropTask, timeout, waitForEvent, race } from 'ember-concurrency';
import { espera } from '../../utils/entregas-pedido';
import {
    ATALHOS,
    EVENTO_DA_CARTEIRA,
    INTERVALO_DO_PIX_MS,
    MOTIVO_CANCELADA,
    resumoDaCarteira,
    valorDevido,
    valorDigitado,
    problemaDoValor,
    deveAcompanhar,
    seloDaRecarga,
    tempoRestante,
    imagemDoQr,
} from '../../utils/carteira-da-loja';

const SEM_VALOR = '—';

/** O relógio do tempo restante do Pix anda a cada segundo (só com a aba à vista e o QR na tela). */
const TIQUE_DO_RELOGIO_MS = 1000;

/** A recarga como entra na lista das últimas: sem o QR (a lista do servidor também vem sem). */
const semPix = (recarga) => ({ ...recarga, pix: null });

/**
 * Entregas: a "Minha carteira" da loja pré-paga no portal (GET int/v1/entregas/loja/carteira): o crédito disponível, a
 * recarga por Pix (gera o QR, acompanha o pagamento a cada 5 s e cancela) e as últimas recargas. O crédito só entra pelo
 * servidor, que confere o pagamento no Asaas: a tela só pergunta e mostra. Loja pós-paga que abre a tela pela URL vê o
 * atalho para o Financeiro. Servidor: api/app/Support/Entregas/PrePago/CLAUDE.md.
 */
export default class PortalCarteiraController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;
    @service modalsManager;

    /** A resposta de `loja/carteira`: {carteira, em_andamento, recargas}. */
    @tracked dados = null;
    /** A recarga acompanhada na tela (criando ou aguardando), com o `pix`. */
    @tracked recarga = null;
    @tracked valorTexto = '';
    /** Mostra o problema do valor só depois da primeira tentativa de gerar (não acusa enquanto a loja digita). */
    @tracked tentouGerar = false;
    @tracked agora = Date.now();

    atalhos = ATALHOS;

    get carteira() {
        return resumoDaCarteira(this.dados?.carteira);
    }

    // a resposta chegou e a loja não é pré-paga (paga por fatura)
    get posPaga() {
        return Boolean(this.dados) && this.carteira === null;
    }

    get semCredito() {
        return this.carteira !== null && !this.carteira.podePedir;
    }

    get devido() {
        return valorDevido(this.carteira);
    }

    // "Crédito R$ 150,00 · R$ 30,00 reservados em entregas em andamento" (o reservado só quando há)
    get detalheDoSaldo() {
        const { carteira } = this;

        if (!carteira || carteira.credito === null) {
            return '';
        }
        if (carteira.reservado !== null && carteira.reservado > 0) {
            return this.intl.t('customer-portal.ui.entregas.carteira.credito-e-reservado', {
                credito: this.dinheiro(carteira.credito),
                reservado: this.dinheiro(carteira.reservado),
            });
        }

        return this.intl.t('customer-portal.ui.entregas.carteira.credito', { credito: this.dinheiro(carteira.credito) });
    }

    get recargaLigada() {
        return this.carteira?.recarga?.ligada === true;
    }

    // a recarga em andamento na tela (o Pix sendo gerado ou o QR à espera do pagamento)
    get recargaAtual() {
        return this.recarga && deveAcompanhar(this.recarga.situacao) ? this.recarga : null;
    }

    get aguardando() {
        return this.recargaAtual?.situacao === 'aguardando';
    }

    get copiaECola() {
        return this.recargaAtual?.pix?.copia_e_cola ?? '';
    }

    get imagemDoQr() {
        return imagemDoQr(this.recargaAtual?.pix?.imagem);
    }

    get tempo() {
        return tempoRestante(this.recargaAtual?.expira_em, this.agora);
    }

    get valorDaRecarga() {
        return this.dinheiro(this.recargaAtual?.valor);
    }

    get valor() {
        return valorDigitado(this.valorTexto);
    }

    // o problema do valor digitado, já traduzido, ou '' (só depois da primeira tentativa)
    get erroDoValor() {
        if (!this.tentouGerar) {
            return '';
        }
        const problema = problemaDoValor(this.valor, this.carteira?.recarga);

        if (!problema) {
            return '';
        }

        return this.intl.t(`customer-portal.ui.entregas.carteira.valor-${problema.chave}`, { limite: this.dinheiro(problema.limite) });
    }

    get podeGerar() {
        return this.recargaLigada && !this.recargaAtual && !this.gerar.isRunning;
    }

    // As últimas recargas com o selo, a data e o valor prontos (o servidor manda a mais nova primeiro)
    get recargas() {
        return (this.dados?.recargas ?? []).map((recarga) => {
            const selo = seloDaRecarga(recarga);

            return { recarga, rotulo: this.intl.t(selo.traducao), classe: selo.classe, data: this.dataEHora(recarga.criada_em), valor: this.dinheiro(recarga.valor) };
        });
    }

    // Já há dados na tela e uma nova consulta está em andamento (os dados antigos continuam visíveis, só esmaecidos)
    get atualizando() {
        return Boolean(this.dados) && this.carregar.isRunning;
    }

    @restartableTask *carregar() {
        try {
            const dados = yield this.fetch.get('entregas/loja/carteira');
            this.dados = dados;

            // o Pix aguardando (inclusive o gerado em outra aba ou antes de sair da tela) volta a ser acompanhado
            const andamento = dados?.em_andamento;
            if (andamento && deveAcompanhar(andamento.situacao)) {
                const jaAcompanha = this.acompanhar.isRunning && this.recarga?.id === andamento.id;
                this.recarga = andamento;
                if (!jaAcompanha) {
                    this.acompanhar.perform();
                }
            } else if (!this.acompanhar.isRunning) {
                this.recarga = null;
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // Gera o Pix do valor digitado. O valor é conferido aqui antes (mínimo e máximo da carteira), e de novo no servidor
    @dropTask *gerar() {
        this.tentouGerar = true;
        const { valor } = this;

        if (!this.recargaLigada || problemaDoValor(valor, this.carteira?.recarga)) {
            return;
        }

        try {
            const resposta = yield this.fetch.post('entregas/loja/carteira/recargas', { valor });
            this.valorTexto = '';
            this.tentouGerar = false;
            this.aplicar(resposta);
        } catch (error) {
            // 422 (limite, Pix já aguardando, limite do dia), 502 (o Asaas não gerou o Pix) ou 503: a mensagem vem do
            // servidor em pt-BR. Relê a carteira: a recarga que falhou entra na lista, e um Pix já aguardando volta à tela
            this.notifications.serverError(error);
            this.carregar.perform();
        }
    }

    // Pergunta ao servidor a cada 5 s enquanto o Pix está sendo gerado ou na tela, com a aba do navegador à vista (com a
    // aba oculta, espera ela voltar; na volta, pergunta na hora: a loja costuma voltar do app do banco). Em erro, a espera
    // dobra até 2 min. Para quando a recarga vira paga, expirada ou falhou
    @restartableTask *acompanhar() {
        let falhas = 0;

        this.relogio.perform();
        while (this.recargaAtual) {
            if (document.hidden) {
                yield waitForEvent(document, 'visibilitychange');
                continue;
            }
            yield race([timeout(espera(INTERVALO_DO_PIX_MS, falhas)), waitForEvent(document, 'visibilitychange')]);
            if (document.hidden || !this.recargaAtual) {
                continue;
            }

            try {
                const resposta = yield this.fetch.get(`entregas/loja/carteira/recargas/${encodeURIComponent(this.recargaAtual.id)}`);
                falhas = 0;
                this.aplicar(resposta);
            } catch {
                // sem resposta (rede, 429, 500): o QR continua na tela e a próxima pergunta espera mais
                falhas++;
            }
        }
    }

    // O tempo restante do Pix, pelo relógio do aparelho; para com a aba oculta e com o QR fora da tela
    @restartableTask *relogio() {
        while (this.recargaAtual) {
            this.agora = Date.now();
            if (document.hidden) {
                yield waitForEvent(document, 'visibilitychange');
            } else {
                yield race([timeout(TIQUE_DO_RELOGIO_MS), waitForEvent(document, 'visibilitychange')]);
            }
        }
    }

    @dropTask *cancelar() {
        const recarga = this.recargaAtual;

        if (!recarga) {
            return;
        }

        try {
            const resposta = yield this.fetch.delete(`entregas/loja/carteira/recargas/${encodeURIComponent(recarga.id)}`);
            this.aplicar(resposta);
        } catch (error) {
            // 422: o Pix já foi pago ou já venceu; relê para mostrar como ficou
            this.notifications.serverError(error);
            this.carregar.perform();
        }
    }

    /**
     * Aplica a resposta do servidor ({recarga, carteira}): atualiza a carteira, a recarga na tela e na lista. Recarga que
     * acabou de chegar ao fim (paga, expirada, cancelada ou falhou) sai da tela com o aviso, e a carteira é relida.
     */
    aplicar(resposta) {
        const recarga = resposta?.recarga;
        const carteira = resposta?.carteira;

        if (carteira || recarga) {
            const lista = this.dados?.recargas ?? [];
            const recargas = recarga ? [semPix(recarga), ...lista.filter((item) => item.id !== recarga.id)] : lista;
            this.dados = { ...this.dados, carteira: carteira ?? this.dados?.carteira, recargas };
        }
        if (!recarga) {
            return;
        }

        if (deveAcompanhar(recarga.situacao)) {
            this.recarga = recarga;
            if (!this.acompanhar.isRunning) {
                this.acompanhar.perform();
            }

            return;
        }

        this.recarga = null;
        this.avisarFim(recarga);
        this.carregar.perform();
    }

    avisarFim(recarga) {
        const motivo = String(recarga.motivo ?? '').trim();

        if (recarga.situacao === 'paga') {
            this.notifications.success(this.intl.t('customer-portal.ui.entregas.carteira.paga', { valor: this.dinheiro(recarga.valor) }));
            document.dispatchEvent(new CustomEvent(EVENTO_DA_CARTEIRA));
        } else if (recarga.situacao === 'expirada' && motivo === MOTIVO_CANCELADA) {
            this.notifications.info(this.intl.t('customer-portal.ui.entregas.carteira.cancelado'));
        } else if (recarga.situacao === 'expirada') {
            // o motivo do servidor é sempre "O Pix expirou sem pagamento.": vai pela tradução
            this.notifications.warning(this.intl.t('customer-portal.ui.entregas.carteira.expirou'));
        } else {
            // falhou: o motivo é texto livre do servidor (em pt-BR)
            this.notifications.error(motivo || this.intl.t('customer-portal.ui.entregas.carteira.falhou'));
        }
    }

    // Entregas: chamado pela rota ao sair da tela
    reiniciar() {
        this.carregar.cancelAll();
        this.gerar.cancelAll();
        this.acompanhar.cancelAll();
        this.relogio.cancelAll();
        this.cancelar.cancelAll();
        this.dados = null;
        this.recarga = null;
        this.valorTexto = '';
        this.tentouGerar = false;
    }

    @action atualizar() {
        this.carregar.perform();
    }

    @action digitarValor(event) {
        this.valorTexto = event?.target?.value ?? '';
    }

    @action escolherAtalho(valor) {
        this.valorTexto = String(valor);
        this.tentouGerar = false;
    }

    @action enviar(event) {
        event?.preventDefault?.();
        this.gerar.perform();
    }

    @action async pedirCancelamento() {
        await this.modalsManager.confirm({
            title: this.intl.t('customer-portal.ui.entregas.carteira.cancelar-titulo'),
            body: this.intl.t('customer-portal.ui.entregas.carteira.cancelar-texto'),
            acceptButtonText: this.intl.t('customer-portal.ui.entregas.carteira.cancelar-confirmar'),
            acceptButtonType: 'danger',
            declineButtonText: this.intl.t('customer-portal.ui.entregas.carteira.cancelar-voltar'),
            confirm: async () => {
                try {
                    await this.cancelar.perform();
                } catch {
                    // a tela foi fechada no meio (a tarefa foi cancelada): nada a fazer
                }
            },
        });
    }

    // Copia o código do Pix. Sem a área de transferência (navegador antigo ou página sem HTTPS), seleciona o campo e usa o
    // copiar antigo
    @action async copiar() {
        const codigo = this.copiaECola;

        if (!codigo) {
            return;
        }

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(codigo);
            } else {
                const campo = document.getElementById('entregas-pix-copia-e-cola');
                campo?.select?.();
                if (!document.execCommand('copy')) {
                    throw new Error('copy');
                }
            }
            this.notifications.success(this.intl.t('customer-portal.ui.entregas.carteira.copiado'));
        } catch {
            this.notifications.warning(this.intl.t('customer-portal.ui.entregas.carteira.copiar-falhou'));
        }
    }

    // Seleciona o código inteiro ao tocar no campo (para copiar à mão)
    @action selecionar(event) {
        event?.target?.select?.();
    }

    // Data e hora no idioma ativo (27/10, 20:15), pelo relógio do aparelho
    dataEHora(iso) {
        const ms = typeof iso === 'string' && iso ? Date.parse(iso) : NaN;

        return Number.isFinite(ms) ? this.intl.formatDate(new Date(ms), { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : SEM_VALOR;
    }

    @action dinheiro(valor) {
        return valor === null || valor === undefined || valor === '' ? SEM_VALOR : this.intl.formatNumber(Number(valor) || 0, { style: 'currency', currency: 'BRL' });
    }
}
