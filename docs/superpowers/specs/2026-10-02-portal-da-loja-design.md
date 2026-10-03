# Portal da Loja — desenho

Data: 2026-10-02 · Status: aprovado em conversa; revisado depois da verificação do código (mesmo dia)

## Objetivo

O Entregas é uma empresa de entregas que atende vários restaurantes. Cada restaurante (loja) precisa
entrar no sistema, criar e acompanhar os próprios pedidos e ver o próprio extrato **sem enxergar nada
de outra loja**. Os motoboys continuam vendo e recebendo os pedidos de **todas** as lojas.

Modelo de negócio (inalterado): uma organização só (o operador); a loja paga o operador, o operador
paga os motoboys (faixas de km, ver CLAUDE.md).

## Regra de ouro: coleta fixa

Delivery de restaurante: a retirada é **sempre** no endereço da loja.

- A loja **só** informa o destino (cliente final, endereço de entrega, observações).
- A coleta não aparece como campo editável no portal.
- O **servidor** grava a coleta como o Local da loja em todo pedido da loja, ignorando o que vier
  na requisição (não dá para burlar o km chamando a API direto).
- No painel do operador, ao escolher a loja no pedido, a coleta vira o endereço dela e fica travada.

## Abordagem escolhida

Reativar o **Portal do Cliente** do Fleetbase (`packages/customer-portal`, API
`fleetbase/customer-portal-api 0.0.13` já instalada em produção) e fechar as lacunas com código nosso
em `api/app` (o PHP do portal, do Fleet-Ops e do core vem do Composer).

Descartadas: portal próprio do zero (muito mais trabalho e isolamento por nossa conta) e uma
organização por loja (o Navigator trabalha com uma organização por vez; o motoboy teria de trocar).

## 1. Cadastro

- **Loja = Fornecedor (Vendor, tipo `customer`)** do Fleet-Ops: nome, telefone e o Local da loja
  (endereço de coleta, com coordenadas; dono = o Vendor). Só conta Vendor aceita vários usuários no
  portal (`VendorPersonnel`).
- **Usuário da loja** = Contact `type=customer` ligado ao Vendor por `VendorPersonnel` (status
  `active`), com User `type=customer` próprio.
- **Só a central cria e desativa usuários**, na tela **"Lojas"** (Fleet-Ops → Recursos, só admin, API
  nossa em `api/app/Http/Controllers/Entregas/`):
  - criar/editar loja (Vendor + Local, coordenadas copiadas do Google Maps);
  - adicionar usuários (nome, e-mail, telefone, senha inicial) — o e-mail já sai verificado, porque
    não há envio de e-mail configurado;
  - trocar senha e desativar/reativar o acesso; as duas coisas derrubam as sessões abertas (o
    Fleetbase não barra usuário inativo sozinho).
- A aba **Membros** do portal fica só para consulta.

## 2. Portal da loja

- URL: `https://entregas.restaurantepro.com.br/customer-portal` (login próprio). Usuário
  `customer` não entra no console e usuário do console não entra no portal (regras já existentes).
- Menu: **Início, Pedidos, Extrato, Configurações** (conta e membros). Ocultos (menu e rota
  direta): faturas, suporte, documentos, catálogo de endereços, notificações.
- **Novo pedido:** sem campo de coleta (mostra o endereço da loja, só leitura); destino, cliente
  final e observações. O endereço de entrega novo precisa ser **marcado no mapa** ("Selecionar no
  mapa", que abre centrado na loja): o servidor não tem geocodificação e o km da cobrança sai das
  coordenadas. Tipo `transport` sempre; sem pagamento online.
- **Despacho:** criado o pedido, ele vai **automaticamente** como pedido aberto (adhoc) aos motoboys
  disponíveis perto da loja — o mesmo fluxo que a central usa hoje.
- **Acompanhar:** status, nome e foto do motoboy e posição dele no mapa, com atualização por polling
  (~20 s). **Não** usar o canal `company.<uuid>` do socket: ele transmite pedidos de todas as lojas.
- **Cancelar:** só antes de um motoboy aceitar.
- **Extrato:** período → entregas, km, faixa e valor "loja" de cada uma, total e CSV. Endpoint nosso,
  mesmo cálculo do `PagamentoMotoboysController`, filtrado pela loja da sessão.
- Tudo em pt-BR (o módulo já tem `pt-br.yaml` completo; textos novos com prefixo `customer-portal.ui.*`).

## 3. Segurança (camada nossa em `api/app`, antes do código do Composer)

A verificação do código mostrou que o Fleetbase sozinho não isola as lojas: a API pública `v1/*`
aceita o token do usuário da loja e devolveria os pedidos de todas; o papel "Fleet-Ops Customer"
lista e apaga contatos de todas as lojas e permite trocar o próprio papel para Administrador; o
portal grava a própria configuração sem checar admin e lista os usuários de todas as lojas na aba
de membros; usuário desativado continua entrando.

1. **Nega por padrão** para o usuário de loja (middleware global `ProtegerPortalLoja`): libera as
   rotas do portal (menos as perigosas/fora do escopo), uma lista curta de rotas internas (sessão,
   idioma, marca, geocodificação, rotas `entregas/loja/*`), o próprio perfil (só nome, contato e
   foto) e a foto do perfil. Todo o resto — inclusive `v1/*` — volta 403.
2. **Usuário desativado** não passa em nada, nem no login.
3. **Coleta forçada** em `POST customer-portal/int/v1/orders` (middleware `RegrasPortalLoja`):
   descarta coleta, payload pronto, paradas extras, arquivos, meta (onde mora o cache do km) e
   agendamento; grava a coleta = Local da loja; o destino tem de ser um endereço salvo da loja com
   coordenadas.
4. **Cancelamento** recusado depois que um motoboy aceitou (ou fora de `created`/`dispatched`).
   Reagendamento negado.
5. **Endereço da loja** não pode ser editado nem apagado pelo portal.
6. **Teste de isolamento** com duas lojas, contra produção: A não lista, não abre, não cancela e não
   vê o motoboy de pedido de B; B não acessa rotas da central nem a API pública; ninguém vira admin;
   a coleta falsa é trocada pela da loja; o pedido sai despachado.

## 4. Painel do operador

- Formulário de pedido: campo **Loja ou cliente**; ao escolher uma loja, a coleta vira o Local da
  loja e fica travada.
- **Pagamento e cobrança:** cobrança agrupada pela **loja dona do pedido** (`customer` do pedido).
  Pedidos sem loja continuam agrupados pelo nome do local de coleta.
- Motoboys e Navigator: nada muda.

## Reativação técnica

- Voltar `@fleetbase/customer-portal-engine` em `console/package.json`, `console/pnpm-workspace.yaml`,
  `console/Dockerfile.dockerignore` (e no router, se não for gerado); regenerar `pnpm-lock.yaml` com
  `pnpm install`.
- Mudanças no **frontend** do portal vão em `packages/customer-portal/addon` (entram no build).
  Mudanças no **PHP** do portal não chegam à produção: regras de servidor vão em `api/app`.
- Configuração do portal em produção: habilitar só o tipo `transport`, pagamentos desligados.

## Fora desta etapa

- Chave de API por loja para o iFood (ponto de entrada nosso que prende a chave a uma loja).
- Autenticação do canal `company.*` no socket.

## Riscos

- Alguma rota interna que o portal usa pode ter ficado de fora da lista do `ProtegerPortalLoja`:
  aparece como 403 no teste do navegador (e no log `[entregas] portal da loja: acesso negado`) e é
  liberada caso a caso.
- O teste contra produção dispara um aviso real de pedido aos motoboys online perto da loja de teste
  (o próprio teste cancela o pedido em seguida).
