# Busca de endereço (Google Places)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

- **Busca de endereço (Google Places)** (decisão de 2026-10-07; desenho: `docs/superpowers/specs/2026-10-07-busca-de-endereco-google-places-design.md`).
  - Servidor (`api/app/Support/Entregas/Enderecos/`): `ClienteGooglePlaces` (única porta para a Places API New; chave de Admin → Serviços lida a cada chamada), `EnderecoBrasileiro` (rua antes do número, cidade pela `administrative_area_level_2`, UF curta; sem número no Google, o digitado) e `BuscaDeEnderecos` (referência = posição de quem digita, a loja no portal ou o centro de Ribeirão Preto; mínimo de 3 caracteres; até 5 sugestões; falha do Google = lista vazia, log `[entregas] endereços:` só com o status).
  - Rotas (`Entregas/EnderecosController`, limitador `entregas-enderecos`, 120 por minuto por usuário): `GET int/v1/entregas/enderecos/sugestoes`, `.../detalhes/{placeId}` (502 sem o ponto) e `.../busca` (campo do pedido: locais salvos + sugestões marcadas em `meta.entregas_sugestao`; só da central, o `ProtegerPortalLoja` libera só `sugestoes` e `detalhes` à loja).
  - Telas: `EnderecoGoogleInput` (ember-ui) na "Rua 1" do cadastro de local (Locais, novo endereço do cliente, Criar Novo Local do pedido) e no novo endereço do portal; no novo pedido, as sugestões aparecem no próprio campo de coleta, entrega, retorno e paradas, e o console busca os detalhes ao escolher (`resolverSugestao` no `order/form/route.js`). A linha "bairro, cidade - UF, CEP" da exibição vem do `utils/endereco-brasileiro.js` do ember-ui.
  - Os locais antigos gravados como "45 RUA OLINDA" ficam como estão. O "Localizar" do mapa e o texto do endereço montado pelo servidor do Fleet-Ops (API v1, app) não mudaram.
  - Testes: `scripts/teste-php/enderecos.php` e `scripts/teste-portal/endereco-brasileiro.test.mjs`.
