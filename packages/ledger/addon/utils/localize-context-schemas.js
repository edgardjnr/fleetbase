/**
 * Translate the normalised template context schemas (from `templates/context-schemas`)
 * shown in the invoice template builder variable picker.
 *
 * The API registers the namespace label and each variable description in English. The
 * namespace and the variable `path` are stable identifiers, so text is looked up under
 * `ledger.ui.api.template-context.*` and falls back to the server text when no key exists.
 * Paths (inserted into templates) are never changed.
 */
const slugify = (value) =>
    String(value ?? '')
        .replace(/[^a-zA-Z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .toLowerCase();

export default function localizeContextSchemas(intl, schemas) {
    if (!intl || !Array.isArray(schemas)) {
        return schemas;
    }

    const base = 'ledger.ui.api.template-context';
    const tr = (key, fallback) => (intl.exists(key) ? intl.t(key) : fallback);

    return schemas.map((schema) => ({
        ...schema,
        label: tr(`${base}.namespaces.${slugify(schema.namespace)}`, schema.label),
        variables: (schema.variables ?? []).map((variable) => ({
            ...variable,
            description: variable.description ? tr(`${base}.variables.${slugify(variable.path)}`, variable.description) : variable.description,
        })),
    }));
}
