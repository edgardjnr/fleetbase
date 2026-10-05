/**
 * Entregas RestaurantePro: grava o online do motoboy no registro do store, para a lista "Operações ao vivo" da barra
 * lateral (bolinha e total de online) acompanhar o capacete do mapa.
 *
 * O toggle-online do app grava com updateQuietly e não gera evento do Fleet-Ops; quem avisa é o nosso
 * `entregas.motoboy_online` (App\Events\Entregas\OnlineDoMotoboyMudou), com `data = {id: public_id, uuid, online}`.
 * A releitura do mapa (GET int/v1/entregas/mapa/motoboys) traz o mesmo `online` como reserva.
 *
 * Usa store.push (e não `driver.online = ...`) para não deixar o registro "com alteração não salva". Motoboy fora do
 * store fica de fora: o push criaria um registro só com o online.
 *
 * @param {object} store serviço store do Ember Data
 * @param {{uuid?: string, id?: string, public_id?: string, online?: boolean}} dados
 * @returns {boolean} true quando o registro mudou
 */
export default function aplicarOnlineDoMotoboy(store, dados) {
    if (!store || !dados || typeof dados.online !== 'boolean') return false;

    const publicId = dados.public_id ?? dados.id;
    const motoboy =
        (dados.uuid ? store.peekRecord('driver', dados.uuid) : null) ??
        (publicId ? (store.peekAll('driver') ?? []).find((driver) => driver.public_id === publicId) : null);

    if (!motoboy || motoboy.isDeleted || Boolean(motoboy.online) === dados.online) return false;

    store.push({ data: { id: motoboy.id, type: 'driver', attributes: { online: dados.online } } });
    return true;
}
