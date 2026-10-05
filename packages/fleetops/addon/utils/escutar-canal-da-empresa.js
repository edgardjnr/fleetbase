/**
 * Entregas RestaurantePro: escuta o canal `company.<uuid>` do socket com um consumidor próprio. Outras telas usam o
 * mesmo canal, e desinscrever derrubaria a delas; por isso cada uma consome sem fechar o canal. Se o canal for fechado
 * por outra tela (o order-socket-events fecha ao sair de Pedidos) ou cair, espera `esperaMs` e se inscreve de novo.
 * Sem empresa no usuário (login ainda carregando), espera e tenta de novo.
 *
 * Sem imports do Ember, para testar no Node (scripts/teste-portal/escutar-canal-da-empresa.test.mjs).
 *
 * @param {object} opcoes
 * @param {object} opcoes.socket serviço socket do ember-core (instance() devolve o cliente SocketCluster)
 * @param {object} opcoes.currentUser serviço current-user (companyId)
 * @param {(mensagem: object) => void} opcoes.aoReceber chamado a cada mensagem do canal
 * @param {(erro: Error) => void} [opcoes.aoFalhar] erros do socket ou do aoReceber (a escuta continua)
 * @param {number} [opcoes.esperaMs] espera antes de se inscrever de novo
 * @returns {{ parar: () => void }}
 */
export default function escutarCanalDaEmpresa({ socket, currentUser, aoReceber, aoFalhar = () => {}, esperaMs = 5000 }) {
    let ativo = true;
    let consumidor = null;
    let espera = null;

    (async () => {
        while (ativo) {
            try {
                const empresa = currentUser?.companyId;
                if (empresa) {
                    consumidor = socket.instance().subscribe(`company.${empresa}`).createConsumer();
                    for await (const mensagem of consumidor) {
                        if (!ativo) break;
                        try {
                            aoReceber(mensagem);
                        } catch (erro) {
                            aoFalhar(erro);
                        }
                    }
                }
            } catch (erro) {
                aoFalhar(erro);
            }
            if (ativo) {
                await new Promise((resolve) => {
                    espera = setTimeout(resolve, esperaMs);
                });
            }
        }
    })();

    return {
        parar() {
            ativo = false;
            clearTimeout(espera);
            try {
                consumidor?.return();
            } catch (erro) {
                aoFalhar(erro);
            }
            consumidor = null;
        },
    };
}
