import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import getCurrency from '../utils/get-currency';
import countryName from '../utils/country-name';

export default class CurrencySelectComponent extends Component {
    @service currentUser;
    @service intl;
    @tracked currencies = this.localizeCurrencies(getCurrency());
    @tracked currency;
    @tracked currencyData;

    constructor() {
        super(...arguments);

        let whois = this.currentUser.getOption('whois');

        this.currency = this.args.currency ?? this.args.value ?? whois?.currency?.code ?? 'USD';
        this.currencyData = this.localizeCurrency(this.args.currencyData ?? getCurrency(this.currency));
    }

    /** Country name shown next to the currency code, in the active language. */
    localizeCurrency(currency) {
        if (!currency || typeof currency !== 'object') return currency;
        return { ...currency, name: countryName(this.intl?.primaryLocale, currency.iso2, currency.name) };
    }

    localizeCurrencies(currencies) {
        return Array.isArray(currencies) ? currencies.map((currency) => this.localizeCurrency(currency)) : currencies;
    }

    @action onChange(currency) {
        const { onChange, onCurrencyChange, onSelect } = this.args;

        this.currency = currency.code;
        this.currencyData = currency;

        if (typeof onCurrencyChange === 'function') {
            onCurrencyChange(currency.code, currency);
        }

        if (typeof onSelect === 'function') {
            onSelect(currency.code, currency);
        }

        if (typeof onChange === 'function') {
            onChange(currency);
        }
    }

    @action searchCurrencies(currency, term) {
        if (!term || typeof term !== 'string') {
            return -1;
        }

        let name = `${currency.title} ${currency.code} ${currency.iso2}`.toLowerCase();

        return name.indexOf(term.toLowerCase());
    }
}
