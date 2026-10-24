// Entregas: os produtos do pedido no portal da loja (GET int/v1/entregas/loja/pedidos/{id}/itens), os mesmos que o motoboy
// vê na comanda do app (App\Support\Entregas\ItensDoPedido): [{quantidade, nome, opcoes: [{quantidade, nome}], observacao}].
// Funções puras; teste em scripts/teste-portal/itens-do-pedido.test.mjs.

function texto(valor) {
    return typeof valor === 'string' ? valor.trim() : '';
}

function quantidade(valor) {
    const numero = Number(valor);

    return Number.isInteger(numero) && numero >= 1 ? numero : 1;
}

/** A lista da resposta, só com os itens que têm nome; resposta inválida ou sem itens = []. */
export function itensDaResposta(resposta) {
    const itens = Array.isArray(resposta?.itens) ? resposta.itens : [];

    return itens
        .filter((item) => item && texto(item.nome) !== '')
        .map((item) => ({
            quantidade: quantidade(item.quantidade),
            nome: texto(item.nome),
            opcoes: textoDasOpcoes(item),
            observacao: texto(item.observacao) || null,
        }));
}

/** "Borda, 2× Bacon": as opções do item, com a quantidade só quando passa de 1 (a mesma regra do app); sem opções, null. */
export function textoDasOpcoes(item) {
    const partes = (Array.isArray(item?.opcoes) ? item.opcoes : [])
        .filter((opcao) => opcao && texto(opcao.nome) !== '')
        .map((opcao) => (quantidade(opcao.quantidade) > 1 ? `${quantidade(opcao.quantidade)}× ${texto(opcao.nome)}` : texto(opcao.nome)));

    return partes.length ? partes.join(', ') : null;
}

/** A observação do cliente para o pedido todo; vazia = null. */
export function observacaoDaResposta(resposta) {
    return texto(resposta?.observacao) || null;
}

/** Soma das quantidades (o "3 itens" do título). */
export function totalDeItens(itens) {
    return (itens ?? []).reduce((soma, item) => soma + quantidade(item?.quantidade), 0);
}
