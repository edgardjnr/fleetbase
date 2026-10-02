import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';

const ENDPOINT = 'entregas/pagamento-motoboys';
const pad = (n) => String(n).padStart(2, '0');
const isoDate = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

export default class ManagementDriverPayoutsController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked inicio = isoDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
    @tracked fim = isoDate(new Date());
    @tracked valorKm = '';
    @tracked relatorio = null;
    @tracked motoboySelecionado = null;

    get motoboys() {
        return this.relatorio?.motoboys ?? [];
    }

    get entregas() {
        const entregas = this.relatorio?.entregas ?? [];
        return this.motoboySelecionado ? entregas.filter((entrega) => entrega.motoboy === this.motoboySelecionado.motoboy) : entregas;
    }

    get totais() {
        return this.relatorio?.totais ?? { entregas: 0, km: 0, valor: 0 };
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

    @action setValorKm(event) {
        this.valorKm = event.target.value;
    }

    @action selecionarMotoboy(motoboy) {
        this.motoboySelecionado = this.motoboySelecionado?.motoboy === motoboy?.motoboy ? null : motoboy;
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
                this.valorKm = String(relatorio.valor_km ?? '');

                if (this.motoboySelecionado && !relatorio.motoboys.some((m) => m.motoboy === this.motoboySelecionado.motoboy)) {
                    this.motoboySelecionado = null;
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

    @task *salvarValorKm() {
        try {
            const { valor_km } = yield this.fetch.put(`${ENDPOINT}/valor-km`, { valor_km: String(this.valorKm).replace(',', '.') });
            this.valorKm = String(valor_km);
            this.notifications.success(this.intl.t('fleet-ops.ui.driver-payouts.rate-saved'));
            yield this.load.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
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
        linhas.push([t('driver'), t('order'), t('completed-at'), t('pickup'), t('dropoff'), t('km'), t('source')].map(celula).join(';'));
        for (const entrega of this.relatorio?.entregas ?? []) {
            linhas.push(
                [
                    celula(entrega.motoboy_nome),
                    celula(entrega.id_interno || entrega.pedido),
                    celula(this.formatarData(entrega.concluido_em)),
                    celula(entrega.origem),
                    celula(entrega.destino),
                    entrega.km === null ? '' : num(entrega.km),
                    celula(entrega.fonte ? t(`source-${entrega.fonte}`) : t('missing-address')),
                ].join(';')
            );
        }

        const blob = new Blob(['﻿' + linhas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `pagamento-motoboys_${this.inicio}_${this.fim}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }
}
