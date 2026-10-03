// Entregas: idioma das telas de acesso do portal (portal-auth) para quem ainda não entrou.
//
// O console só aplica o idioma do usuário depois do login (current-user.setUser) e, antes disso, cai em
// en-US (routes/application.js do console: getOption('locale', 'en-US')). O Entregas é pt-BR, então o
// visitante sem sessão vê pt-BR, a menos que este navegador já tenha um idioma escolhido (a mesma opção
// `locale` que o console lê) entre os que o portal traduz. O idioma do navegador fica de fora de propósito:
// computador em inglês é comum entre donos de restaurante e traria o inglês de volta antes do login, com o
// portal em pt-BR logo depois dele (o usuário da loja nasce com o idioma pt-br, ver LojasController).

/** Idiomas com tradução no portal (translations/*.yaml), no formato do ember-intl (minúsculas). */
export const IDIOMAS_DO_PORTAL = ['pt-br', 'en-us'];

/** Idioma de quem não tem sessão e não escolheu outro. */
export const IDIOMA_PADRAO_DO_VISITANTE = 'pt-br';

/**
 * Idioma das telas de acesso para um visitante sem sessão.
 *
 * @param {string|null|undefined} escolhido idioma já escolhido neste navegador (`currentUser.getOption('locale')`)
 * @return {string} 'pt-br' ou 'en-us'
 */
export function localeDoVisitante(escolhido) {
    const locale = typeof escolhido === 'string' ? escolhido.trim().replace(/_/g, '-').toLowerCase() : null;

    return IDIOMAS_DO_PORTAL.includes(locale) ? locale : IDIOMA_PADRAO_DO_VISITANTE;
}
