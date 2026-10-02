import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';

const ENDPOINT = 'entregas/pagamento-motoboys';
const pad = (n) => String(n).padStart(2, '0');
const isoDate = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const numero = (valor) => Number(String(valor ?? '').trim().replace(',', '.'));
const faixaParaEdicao = (faixa) => ({ ate_km: String(faixa.ate_km), motoboy: String(faixa.motoboy), loja: String(faixa.loja) });

export default class ManagementDriverPayoutsController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked inicio = isoDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
    @tracked fim = isoDate(new Date());
    // faixas de km em edição (strings, como digitadas); a mesma tabela vale para motoboy e loja
    @tracked faixas = [];
    @tracked faixasAlteradas = false;
    @tracked relatorio = null;
    @tracked motoboySelecionado = null;
    @tracked lojaSelecionada = null;

    get motoboys() {
        return this.relatorio?.motoboys ?? [];
    }

    get lojas() {
        return this.relatorio?.lojas ?? [];
    }

    get entregas() {
        let entregas = this.relatorio?.entregas ?? [];
        if (this.motoboySelecionado) {
            entregas = entregas.filter((entrega) => entrega.motoboy === this.motoboySelecionado.motoboy);
        }
        if (this.lojaSelecionada) {
            entregas = entregas.filter((entrega) => entrega.loja === this.lojaSelecionada.loja);
        }
        return entregas;
    }

    get tituloEntregas() {
        const t = (key, opts) => this.intl.t(`fleet-ops.ui.driver-payouts.${key}`, opts);
        const loja = this.lojaSelecionada ? this.lojaSelecionada.loja_nome || t('without-store') : null;
        if (this.motoboySelecionado && loja) return t('deliveries-of-from', { name: this.motoboySelecionado.motoboy_nome, store: loja });
        if (this.motoboySelecionado) return t('deliveries-of', { name: this.motoboySelecionado.motoboy_nome });
        if (loja) return t('deliveries-from', { name: loja });
        return t('all-deliveries');
    }

    get temFiltro() {
        return Boolean(this.motoboySelecionado || this.lojaSelecionada);
    }

    get totais() {
        return this.relatorio?.totais ?? { entregas: 0, km: 0, valor: 0, cobrar: 0, margem: 0 };
    }

    get temEstimativa() {
        return (this.relatorio?.entregas ?? []).some((entrega) => entrega.fonte === 'estimativa');
    }

    formatarValor(valor) {
        return new Intl.NumberFormat(this.intl.primaryLocale ?? 'pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor) || 0);
    }

    formatarKm(km) {
        return new Intl.NumberFormat(this.intl.primaryLocale ?? 'pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(km) || 0);
    }

    formatarData(iso) {
        return iso ? new Intl.DateTimeFormat(this.intl.primaryLocale ?? 'pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso)) : '';
    }

    @action formatar(tipo, valor) {
        if (tipo === 'valor') return this.formatarValor(valor);
        if (tipo === 'km') return this.formatarKm(valor);
        return this.formatarData(valor);
    }

    @action setPeriodo(atalho) {
        const hoje = new Date();
        if (atalho === 'this-month') {
            this.inicio = isoDate(new Date(hoje.getFullYear(), hoje.getMonth(), 1));
            this.fim = isoDate(hoje);
        } else if (atalho === 'last-month') {
            this.inicio = isoDate(new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1));
            this.fim = isoDate(new Date(hoje.getFullYear(), hoje.getMonth(), 0));
        } else if (atalho === 'last-7-days') {
            this.inicio = isoDate(new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate() - 6));
            this.fim = isoDate(hoje);
        }
        this.load.perform();
    }

    @action setData(campo, event) {
        this[campo] = event.target.value;
    }

    /** Faixas com o "de km" (limite da faixa anterior) para exibir. */
    get linhasFaixas() {
        return this.faixas.map((faixa, indice) => ({ ...faixa, indice, de_km: indice === 0 ? '0' : this.faixas[indice - 1].ate_km || '?' }));
    }

    get faixasValidas() {
        let anterior = 0;
        for (const faixa of this.faixas) {
            const ate = numero(faixa.ate_km);
            if (!(ate > anterior) || !(numero(faixa.motoboy) >= 0) || !(numero(faixa.loja) >= 0)) {
                return false;
            }
            anterior = ate;
        }
        return true;
    }

    @action setFaixa(indice, campo, event) {
        this.faixas = this.faixas.map((faixa, i) => (i === indice ? { ...faixa, [campo]: event.target.value } : faixa));
        this.faixasAlteradas = true;
    }

    @action adicionarFaixa() {
        const ultima = this.faixas[this.faixas.length - 1];
        const proximoAte = ultima ? (numero(ultima.ate_km) || 0) + 1 : 1;
        this.faixas = [...this.faixas, { ate_km: String(proximoAte), motoboy: ultima?.motoboy ?? '', loja: ultima?.loja ?? '' }];
        this.faixasAlteradas = true;
    }

    @action removerFaixa(indice) {
        this.faixas = this.faixas.filter((_, i) => i !== indice);
        this.faixasAlteradas = true;
    }

    @action selecionarMotoboy(motoboy) {
        this.motoboySelecionado = this.motoboySelecionado?.motoboy === motoboy?.motoboy ? null : motoboy;
    }

    @action selecionarLoja(loja) {
        this.lojaSelecionada = this.lojaSelecionada && this.lojaSelecionada.loja === loja?.loja ? null : loja;
    }

    @action limparFiltros() {
        this.motoboySelecionado = null;
        this.lojaSelecionada = null;
    }

    @task({ restartable: true }) *load() {
        if (!this.inicio || !this.fim) {
            return;
        }

        try {
            // a API calcula no máximo ~40 rotas novas por chamada; repete enquanto houver pendentes
            for (let tentativa = 0; tentativa < 50; tentativa++) {
                const relatorio = yield this.fetch.get(ENDPOINT, { inicio: this.inicio, fim: this.fim });
                const pendentesAntes = this.relatorio?.pendentes;
                this.relatorio = relatorio;
                if (!this.faixasAlteradas) {
                    this.faixas = (relatorio.faixas ?? []).map(faixaParaEdicao);
                }

                if (this.motoboySelecionado && !relatorio.motoboys.some((m) => m.motoboy === this.motoboySelecionado.motoboy)) {
                    this.motoboySelecionado = null;
                }
                if (this.lojaSelecionada && !(relatorio.lojas ?? []).some((l) => l.loja === this.lojaSelecionada.loja)) {
                    this.lojaSelecionada = null;
                }

                // pendentes que não diminuem = entregas sem endereço; não adianta repetir
                if (!relatorio.pendentes || (tentativa > 0 && relatorio.pendentes === pendentesAntes)) {
                    break;
                }
                yield timeout(300);
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *salvarFaixas() {
        if (!this.faixasValidas) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.driver-payouts.invalid-bands'));
            return;
        }

        try {
            const faixas = this.faixas.map((faixa) => ({ ate_km: numero(faixa.ate_km), motoboy: numero(faixa.motoboy), loja: numero(faixa.loja) }));
            const resposta = yield this.fetch.put(`${ENDPOINT}/faixas`, { faixas });
            this.faixas = (resposta.faixas ?? []).map(faixaParaEdicao);
            this.faixasAlteradas = false;
            this.notifications.success(this.intl.t('fleet-ops.ui.driver-payouts.bands-saved'));
            yield this.load.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action textoFaixa(faixa) {
        if (!faixa) return '';
        const km = (valor) => this.formatarKm(valor).replace(/,00$/, '');
        return faixa.acima ? `> ${km(faixa.de_km)} km` : `${km(faixa.de_km)}–${km(faixa.ate_km)} km`;
    }

    @action exportarCsv() {
        const t = (key) => this.intl.t(`fleet-ops.ui.driver-payouts.${key}`);
        const num = (valor) => String(Number(valor) || 0).replace('.', ',');
        const celula = (valor) => `"${String(valor ?? '').replace(/"/g, '""')}"`;
        const linhas = [];

        linhas.push([t('driver'), t('deliveries'), t('km'), t('amount')].map(celula).join(';'));
        for (const motoboy of this.motoboys) {
            linhas.push([celula(motoboy.motoboy_nome), motoboy.entregas, num(motoboy.km), num(motoboy.valor)].join(';'));
        }
        linhas.push([celula(t('total')), this.totais.entregas, num(this.totais.km), num(this.totais.valor)].join(';'));
        linhas.push('');
        linhas.push([t('store'), t('deliveries'), t('km'), t('charge')].map(celula).join(';'));
        for (const loja of this.lojas) {
            linhas.push([celula(loja.loja_nome || t('without-store')), loja.entregas, num(loja.km), num(loja.valor)].join(';'));
        }
        linhas.push([celula(t('total')), this.totais.entregas, num(this.totais.km), num(this.totais.cobrar)].join(';'));
        linhas.push([celula(t('margin')), '', '', num(this.totais.margem)].join(';'));
        linhas.push('');
        linhas.push([t('store'), t('driver'), t('order'), t('completed-at'), t('pickup'), t('dropoff'), t('km'), t('source'), t('band'), t('driver-amount'), t('store-amount')].map(celula).join(';'));
        for (const entrega of this.relatorio?.entregas ?? []) {
            linhas.push(
                [
                    celula(entrega.loja_nome || t('without-store')),
                    celula(entrega.motoboy_nome),
                    celula(entrega.id_interno || entrega.pedido),
                    celula(this.formatarData(entrega.concluido_em)),
                    celula(entrega.origem),
                    celula(entrega.destino),
                    entrega.km === null ? '' : num(entrega.km),
                    celula(entrega.fonte ? t(`source-${entrega.fonte}`) : t('missing-address')),
                    celula(this.textoFaixa(entrega.faixa)),
                    entrega.valor_motoboy === null ? '' : num(entrega.valor_motoboy),
                    entrega.valor_loja === null ? '' : num(entrega.valor_loja),
                ].join(';')
            );
        }

        const blob = new Blob(['﻿' + linhas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `pagamento-e-cobranca_${this.inicio}_${this.fim}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }
}
