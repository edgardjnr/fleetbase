import Component from '@glimmer/component';

// Entregas: o painel do motoboy no detalhe do pedido, só apresentação. O ciclo do detalhe consulta o motoboy e passa
// @motoboy ({ nome, foto, aceitou }; null = ninguém chamado ainda), @consultado (se a primeira resposta já veio; antes
// dela, o carregando) e @aceito (o detalhe relido já mostra o pedido aceito: sem motoboy na resposta, o painel fica no
// carregando em vez de "aguardando"). Com o pedido encerrado, o detalhe não mostra o painel. A posição fica no mapa
// grande da tela Pedidos (Workspace::Map), com todos os motoboys online
export default class PortalOrderDetailsMotoboyComponent extends Component {}
