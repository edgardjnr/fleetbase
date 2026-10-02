/**
 * Translate the gateway driver manifest from `ledger/int/v1/gateways/drivers`.
 *
 * The API builds the driver name and the config schema (field label, description,
 * placeholder) in English, but every driver carries a stable `code` and every field a
 * stable `key`, so the text is looked up under `ledger.ui.api.gateway.<code>.*` and falls
 * back to the server text when no key exists. Only display copies are produced: the
 * original driver/field objects (whose values are sent back to the API) are untouched.
 */
const slugify = (value) =>
    String(value ?? '')
        .replace(/[^a-zA-Z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .toLowerCase();

const tr = (intl, key, fallback) => (intl && intl.exists(key) ? intl.t(key) : fallback);

export function localizeDriverName(intl, driver) {
    if (!driver) {
        return driver?.name;
    }

    return tr(intl, `ledger.ui.api.gateway.${slugify(driver.code)}.name`, driver.name);
}

export function localizeConfigField(intl, driverCode, field) {
    if (!field || typeof field !== 'object') {
        return field;
    }

    const base = `ledger.ui.api.gateway.${slugify(driverCode)}.fields.${slugify(field.key)}`;

    return {
        ...field,
        label: tr(intl, `${base}.label`, field.label),
        description: field.description ? tr(intl, `${base}.description`, field.description) : field.description,
        placeholder: field.placeholder ? tr(intl, `${base}.placeholder`, field.placeholder) : field.placeholder,
    };
}

export default function localizeConfigSchema(intl, driverCode, schema = []) {
    return (schema ?? []).map((field) => localizeConfigField(intl, driverCode, field));
}
