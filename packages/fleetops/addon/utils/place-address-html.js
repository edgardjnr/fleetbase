import { htmlSafe } from '@ember/template';
import { linhaDoBairroECidade } from '@fleetbase/ember-ui/utils/endereco-brasileiro';

function escapeHtml(value) {
    return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function line(content, className = '') {
    const classAttribute = className ? ` class="${className}"` : '';

    return `<div${classAttribute}>${escapeHtml(content)}</div>`;
}

export default function placeAddressHtml(place, options = {}) {
    if (!place) {
        return htmlSafe('');
    }

    const { showTitle = true } = options;
    const name = place.name === place.street1 ? null : place.name;
    // Entregas: "Jardim Paulista, Ribeirão Preto - SP, 14025-150" (bairro, cidade - UF, CEP)
    const cityStatePostalCode = linhaDoBairroECidade(place);
    const lines = [];

    if (name) {
        if (showTitle) lines.push(line(name, 'font-semibold'));
        lines.push(line(place.street1));
    } else if (place.street1) {
        lines.push(line(place.street1, 'font-semibold'));
    }

    if (place.street2) {
        lines.push(line(place.street2));
    }

    lines.push(line(cityStatePostalCode));

    if (place.country) {
        lines.push(line(place.country_name));
    }

    return htmlSafe(`<address class="uppercase truncate w-full">${lines.join('')}</address>`);
}
