// Completa o .js dos imports relativos sem extensão (registrado pelo resolver.mjs).
export async function resolve(especificador, contexto, proximo) {
    if (/^\.\.?\//.test(especificador) && !/\.[cm]?js$/.test(especificador)) {
        try {
            return await proximo(`${especificador}.js`, contexto);
        } catch {
            // segue com o especificador original
        }
    }

    return proximo(especificador, contexto);
}
