# Busca de endereço pelo Google Places Autocomplete

**Data:** 2026-10-07 · **Estado:** desenho aprovado na conversa; falta o plano.

## 1. Problema

A busca de endereço do console e do portal usa a Geocoding API do Google sem dizer país, cidade nem região:

- "rua olinda, 45" traz ruas de outras cidades e estados; só acha a de Ribeirão Preto com a cidade ou o CEP no texto;
- a Geocoding foi feita para converter um endereço completo em coordenadas, não para sugerir enquanto se digita: devolve 1 resultado, quase nunca o certo com texto parcial;
- o Fleet-Ops grava a rua no padrão dos EUA (`street1` = "45 Rua Olinda"), muitas vezes sem cidade (o Google não devolve a `locality` no Brasil) e nunca com o estado.

## 2. Requisitos

1. A busca usa o **Google Places Autocomplete** (Places API New), restrito ao Brasil e em pt-BR, com preferência pelos endereços perto de quem digita. As sugestões aparecem enquanto se digita (até 5).
2. **Formato brasileiro** nas sugestões e no que é gravado: `street1` = "Rua Olinda, 45", com bairro, cidade, UF e CEP nos campos próprios. Exibição nas telas do console e do portal: "Rua Olinda, 45 - Jardim Paulista, Ribeirão Preto - SP, 14025-150".
3. **No campo Entrega/Coleta/Retorno do novo pedido**, as sugestões do Google aparecem direto na lista, abaixo dos locais salvos. Escolher uma sugestão cria o local na hora (decisão do Edgard em 2026-10-07).

Fora do escopo: converter os locais já gravados no formato antigo; o "Localizar" do mapa (`CoordinatesInput`), que continua na Geocoding; o texto de endereço que o servidor do Fleet-Ops monta (`Place::address`, em maiúsculas, sem bairro), usado pela API v1 e pelo app.

## 3. Arquitetura

Tudo passa pelo nosso servidor (`api/app`): a chave do Google fica no servidor, restrita ao IP da VPS e às APIs Geocoding e Places (New). O navegador nunca a vê.

### 3.1 Servidor

- **`App\Support\Entregas\Enderecos\ClienteGooglePlaces`**: única porta HTTP para a Places API (New). A chave é lida de `config('services.google_maps.api_key')` a cada chamada (vem de Admin → Serviços, mesclada por requisição; não depende do `config:cache`).
  - `sugestoes(texto, latitude, longitude, sessao)`: `POST https://places.googleapis.com/v1/places:autocomplete` com `input`, `includedRegionCodes: ["br"]`, `languageCode: "pt-BR"`, `locationBias.circle` (centro = posição de referência, raio de 30 km) e `sessionToken`.
  - `detalhes(placeId, sessao)`: `GET https://places.googleapis.com/v1/places/{placeId}` com `languageCode=pt-BR`, `sessionToken` e `X-Goog-FieldMask: addressComponents,location,formattedAddress`.
  - Erros tipados (rede = status 0), como o `ClienteIfood`. Sem chave configurada: não chama e devolve vazio.
- **`App\Support\Entregas\Enderecos\EnderecoBrasileiro`** (funções puras):
  - dos `addressComponents` monta rua (`route`), número (`street_number`), bairro (`sublocality_level_1`, `sublocality` ou `neighborhood`), cidade (`administrative_area_level_2`, ou `locality`), UF (`administrative_area_level_1`, forma curta), CEP (`postal_code`) e país (`BR`);
  - `street1` = "Rua Olinda, 45"; sem número no Google, usa o número digitado (o primeiro número depois do nome da rua no texto da busca); sem nenhum, só a rua;
  - texto de exibição no formato da seção 2.
- **Referência de posição** (centro do `locationBias`), nesta ordem: a posição enviada pela tela; no portal, o Local da loja; senão, o centro padrão do mapa (Ribeirão Preto, -21.1775, -47.8103).
- **Rotas** (`Entregas/EnderecosController`, limitador `entregas-enderecos`, 120 por minuto por usuário):
  - `GET int/v1/entregas/enderecos/sugestoes?texto=&latitude=&longitude=&sessao=` → `[{place_id, principal, secundario}]`. Texto com menos de 3 caracteres: lista vazia, sem chamar o Google.
  - `GET int/v1/entregas/enderecos/detalhes/{placeId}?sessao=&texto=` → atributos do Place (`street1`, `neighborhood`, `city`, `province`, `postal_code`, `country`, `location`, `address`).
  - `GET int/v1/entregas/enderecos/busca?query=&latitude=&longitude=&sessao=` → para o campo do pedido: os locais salvos da empresa (`PlaceSearch::search` do Fleet-Ops com `geo` desligado, até 10) seguidos das sugestões do Google em forma de Place sem coordenadas, marcadas em `meta.entregas_sugestao = {place_id, sessao}`.
  - `sugestoes` e `detalhes` valem para a central e para o usuário de loja (entram nas `PERMITIDAS_INTERNAS` do `ProtegerPortalLoja`). A `busca` é só da central: ela lista locais salvos da empresa toda, então fica fora das permitidas e o usuário de loja recebe 403.
- **Erros**: falha do Google nas sugestões → lista vazia (a tela segue com a marcação no mapa); nos detalhes → 502 `{"errors": ["Não foi possível carregar o endereço. Marque o ponto no mapa."]}`. Log `[entregas] endereços: <ação> falhou (<status>)`, sem o texto digitado nem a chave.

### 3.2 Telas

- **Componente `EnderecoGoogleInput`** (ember-ui, usado pelo console e pelo portal): campo com a lista de sugestões (debounce de 300 ms, mínimo de 3 caracteres). Gera um token de sessão por busca (novo depois de cada escolha). Ao escolher, chama `detalhes` e entrega os atributos do Place ao `@onSelect`. Usa a posição do serviço `location` quando existe.
- **Cadastro de local** (`fleetops/components/place/form`, usado em Locais, Novo endereço do cliente e Criar Novo Local do pedido): a "Rua 1" troca o `AutocompleteInput` (`places/lookup`) pelo `EnderecoGoogleInput`; ao escolher, preenche rua, bairro, cidade, UF, CEP, país e move o ponto do mapa.
- **Novo endereço de entrega do portal** (`customer-portal/components/modals/portal-order-place-form`): a busca da rua passa para `sugestoes`/`detalhes`, com o mesmo preenchimento.
- **Campo Entrega/Coleta/Retorno do pedido** (`fleetops/components/order/form/route`): o `ModelSelect` passa a usar `entregas/enderecos/busca` (com a posição do `location`). Escolhida uma sugestão (`meta.entregas_sugestao`), a tela chama `detalhes`, preenche o Place e só então o põe no pedido (`setPayloadPlace`), que o grava junto com o pedido como hoje. Falha nos detalhes: aviso e o campo volta vazio.
- **Exibição**: o componente `Place::Address` e o helper `place-address-html` passam a montar o texto no formato brasileiro a partir dos campos (função pura compartilhada, `utils/endereco-brasileiro.js`), com fallback para o texto do servidor quando faltam campos.

## 4. Google Cloud (projeto `entregas-restaurantepro`)

- Ativar a **Places API (New)** e acrescentá-la às APIs da chave "Entregas servidor - Geocoding" (restrita ao IP 163.176.162.141).
- Limite diário nas duas APIs (Geocoding e Places) como teto.
- Antes de ligar, conferir na página de preços: sugestões de uma sessão que termina em detalhes sem cobrança, e o custo por chamada de detalhes com os campos pedidos.
- Toda mudança na conta do Google exige o "sim" do Edgard em cada ação.

## 5. Testes

- php-wasm (`scripts/teste-php/enderecos.php`): `EnderecoBrasileiro` (componentes do Google → campos; número digitado quando falta; cidade pela `administrative_area_level_2`; UF curta), o corpo e os cabeçalhos das chamadas do `ClienteGooglePlaces` (`Http::fake`), as regras das rotas (mínimo de 3 caracteres, sem chave, referência de posição, sugestões marcadas na `busca`) e a liberação no `ProtegerPortalLoja`.
- Node (`scripts/teste-portal/endereco-brasileiro.test.mjs`): formato de exibição e a resolução da sugestão escolhida no campo do pedido.
- Manual, depois do deploy: "rua olinda 45" no novo pedido, no cadastro de cliente e no portal traz a Rua Olinda de Ribeirão Preto entre as sugestões e grava "Rua Olinda, 45".

## 6. Implantação

API (`bash deploy/atualizar.sh api`) → Places API ativada e liberada na chave → console (`bash deploy/atualizar.sh console`). Sem a Places liberada, as sugestões voltam vazias e as telas seguem com o mapa.
