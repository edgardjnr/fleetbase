/**
 * Entregas RestaurantePro: escuta o canal `company.<uuid>` do socket com um consumidor próprio. Outras telas usam o
 * mesmo canal, e desinscrever derrubaria a delas; por isso cada uma consome sem fechar o canal.
 * Se o canal for fechado por outra tela, espera `esperaMs` e se inscreve de novo; se outra tela só o desinscrever (sem
 * fechar), o vigia refaz a inscrição; na reconexão do socket, o cliente refaz a inscrição sozinho.
 * Sem empresa no usuário (login ainda carregando), espera e tenta de novo.
 *
 * Sem imports do Ember, para testar no Node (scripts/teste-portal/escutar-canal-da-empresa.test.mjs).
 *
 * @param {object} opcoes
 * @param {object} opcoes.socket serviço socket do ember-core (instance() devolve o cliente SocketCluster)
 * @param {object} opcoes.currentUser serviço current-user (companyId)
 * @param {(mensagem: object) => void} opcoes.aoReceber chamado a cada mensagem do canal; deve ser síncrono (erro
 *   lançado vai ao aoFalhar; Promise rejeitada não é capturada)
 * @param {(erro: Error) => void} [opcoes.aoFalhar] erros do socket ou do aoReceber (a escuta continua)
 * @param {number} [opcoes.esperaMs] espera antes de se inscrever de novo e intervalo do vigia
 * @returns {{ parar: () => void }}
 */
export default function escutarCanalDaEmpresa({ socket, currentUser, aoReceber, aoFalhar = () => {}, esperaMs = 5000 }) {
    let ativo = true;
    let consumidor = null;
    let espera = null;
    let nomeDoCanal = null;

    const falhar = (erro) => {
        try {
            aoFalhar(erro);
        } catch {
            // o aviso de erro não pode derrubar a escuta
        }
    };

    // Outra tela que só chama unsubscribe() (sem close()) tira o canal do cliente sem terminar o nosso consumidor, que
    // ficaria vivo e surdo. O data stream do cliente é por nome: inscrever de novo faz o consumidor voltar a receber.
    // Com includePending, não interfere na reconexão do socket (o canal fica pendente e o cliente o refaz sozinho).
    const vigia = setInterval(() => {
        if (!ativo || !nomeDoCanal) return;
        try {
            const cliente = socket.instance();
            if (!cliente.isSubscribed(nomeDoCanal, true)) {
                cliente.subscribe(nomeDoCanal);
            }
        } catch (erro) {
            falhar(erro);
        }
    }, esperaMs);

    (async () => {
        while (ativo) {
            try {
                const empresa = currentUser?.companyId;
                if (empresa) {
                    nomeDoCanal = `company.${empresa}`;
                    consumidor = socket.instance().subscribe(nomeDoCanal).createConsumer();
                    for await (const mensagem of consumidor) {
                        if (!ativo) break;
                        try {
                            aoReceber(mensagem);
                        } catch (erro) {
                            falhar(erro);
                        }
                    }
                }
            } catch (erro) {
                falhar(erro);
            }
            nomeDoCanal = null;
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
            clearInterval(vigia);
            clearTimeout(espera);
            try {
                consumidor?.return();
            } catch (erro) {
                falhar(erro);
            }
            consumidor = null;
        },
    };
}
