# Portal da Loja — continuação (o que falta)

**Situação (2026-10-03, fim da 2ª sessão):** a implementação está completa no ramo **`portal-da-loja`**: tasks revisadas, conformidade e qualidade aprovadas. Não houve push. A `main` foi trazida para o ramo em `027dfbe7`. Falta só o **deploy e o teste com o usuário** (Task 14, a partir do passo 4).

Referências: o plano em `2026-10-02-portal-da-loja.md`, o desenho em `../specs/2026-10-02-portal-da-loja-design.md` e a seção "Portal da loja" do `CLAUDE.md`. O código no repo é a referência: o plano ficou como histórico, e algumas constantes e limites mudaram nas revisões, como mostram as tabelas abaixo.

## Como retomar

1. **Conferir o ramo:** `git branch --show-current` e `git log --oneline main..portal-da-loja`.
2. **Merge na `main` e push:** só com o aval do usuário.
3. **Deploy**, feito pelo usuário na VPS: `cd ~/entregas && bash deploy/atualizar.sh` (API e console). Depois, `docker service update --force entregas_queue`.
4. **Configurar o portal**, no console aberto em **janela anônima** (a lista de extensões fica no localStorage por 1 h, e o Ctrl+Shift+R não a limpa): Admin → Customer Portal com só o tipo `transport`, pagamentos desligados, e o endereço de acesso definido.
5. **Criar as lojas de teste** em Fleet-Ops → Recursos → Lojas: "Loja Teste A" e "Loja Teste B", com endereços diferentes e coordenadas, e um usuário em cada uma.
   - Quem grava as credenciais em `deploy/teste-lojas.env` é o usuário, não o Claude. O arquivo é ignorado pelo git, e o cabeçalho do script explica o formato.
   - Opcionais: `CHAVE_API` + `MOTOBOY_ID` (aceite de pedido cancelado), `LOJA_DESATIVADA_*` e `LOJA_DUPLA_*`.
6. **Rodar o teste:** `node scripts/teste-isolamento-lojas.mjs` tem de dar todos os itens PASSOU (os opcionais podem sair PULADO).
   - Antes, avisar o usuário: o teste dispara um aviso real aos motoboys online perto da Loja Teste A. O próprio teste cancela o pedido.
   - O teste também deixa endereços de teste salvos na Loja A.
7. **Testar no navegador**, em **janela anônima** (o console guarda a lista de extensões no localStorage por 1 h), logado como Loja A em `/customer-portal`:
   - passar por Início, Pedidos, novo pedido (endereço novo marcado no mapa, arrastando), detalhe, Extrato e Configurações;
   - conferir no log `[entregas] portal da loja: acesso negado` que nenhuma chamada necessária voltou 403. Se voltar, liberar só aquele método e caminho;
   - aceitar o pedido de teste no Navigator: o motoboy aparece no portal em até ~20 s e o cancelamento fica bloqueado.
8. **Limpar:** desativar ou apagar as lojas de teste, conforme o usuário decidir.

## Situação por task

| Task | Situação | Commits principais |
|---|---|---|
| 0–5 | fechadas (1ª sessão) | ver o plano |
| 6A ProtegerPortalLoja | fechada; Minor do login aplicado | 7b5f8e3d, 87c6b8f6, 40412c7a, 4cc30105 |
| 6B RegrasPortalLoja | fechada. Cancelamento atômico (`TravaDoPedido`), atividade e evento, sai dos pedidos abertos. `BarrarAceiteDePedidoEncerrado` no grupo `fleetbase.api`, que também põe o cancelamento da API v1 na trava (no timeout, segue sem a trava). `StatusDoPedido`. Descarta `internal_id`/`pod_*` | f74e8f6e, 618b40d1, 14dcff9f, bd710f2c, 3e1c67dd, b288e32a, 1b798b27 |
| 6C RestringirChaveDoApp | fechada, **desligada** | 16860aef, 88fec82a |
| 7 Tela Lojas | fechada; Minors aplicados | f7b24744, d90f5258 |
| 8 Formulário do operador | fechada | a50a5669, 8ee706ab |
| 9 Menu, telas ocultas, membros | fechada; painel da home só leitura | 76e0f4ef, d037f5c5, d7020515 |
| 10 Novo pedido só com destino | fechada. Destino a pelo menos 30 m da coleta (front e servidor). O mapa só marca com arrasto, autocomplete ou Localizar. Rua obrigatória | 813820c6, 83c0d09e, ef536cc6, dc0b1ed0, bc04c6b0, 880d4ad4 |
| 11 Acompanhamento | fechada. Ciclo único (motoboy a cada 20 s, detalhe ao mudar ou a cada ~60 s), espera crescente, pausa com a aba oculta. Endpoint do motoboy aceita o contato da loja | c0666a3b, 36eb59b1, 8931c039, 5f442cef, 349f7341 e o acabamento final |
| 12 Extrato | fechada. Até 3 meses (92 dias), tabela em partes, aviso de período desatualizado, fuso da organização | 0baf62da, 7cf987f2, 2607e5bb, 8fde868d |
| 13 Build local | ok (exit 0). Engine do portal no `dist`, ícones do mapa com fingerprint, `.h-56` no CSS. Refazer depois dos últimos commits | — |
| 14 Teste de isolamento | script pronto e revisado. Deploy e execução com o usuário | da753f54 |
| 15 Documentação | `CLAUDE.md` e este arquivo | 1ae01d5e |
| extra | 429 e 404 genérico do core traduzidos | f570d347 |

## Decidido em 2026-10-03

- **A integração iFood manda `customer` = Fornecedor da loja (`vendor_…`) e `pickup` = Local da loja (`place_…`).** Os dois ids aparecem na tela Lojas. O pedido do iFood entra no portal, no extrato e na cobrança da loja, e a loja pode cancelá-lo antes do aceite.
  - **Pendente, fora deste repo:** ajustar a integração.
  - Até lá, os pedidos do iFood ficam fora do extrato da loja e, na cobrança, agrupados pelo nome do Local.

## Decisões pendentes do usuário

- **6D (sugerida, não feita): limitar o login por SMS.**
  - O problema é anterior a este projeto e foi achado na revisão da 6C. O código tem 6 dígitos e não expira. Cada pedido cria um código novo sem invalidar os anteriores, e o throttle é de 120/min por IP. Com a chave do APK, a força bruta é viável, e um token de motoboy lista os pedidos de todas as lojas.
  - Correção em `api/app`:
    - limite por telefone no `login-with-sms`;
    - limite de tentativas por identidade no `verify-code`;
    - recusar código vencido;
    - apagar os códigos depois do login.
- **App do motoboy (repo `entregas-navigator`):**
  - criar o Driver com o token do motoboy e deixar o `useFleetbase` síncrono. É o pré-requisito para ligar a 6C;
  - mostrar ao motoboy o erro do aceite. Hoje sai só `console.warn`, em `AdhocOrderCard.tsx` e `OrderScreen.tsx`, e o motoboy não fica sabendo que o pedido foi cancelado.
- **Ligar a 6C:** só com o APK novo nos celulares. Os passos e a checagem com `curl` estão no docblock de `RestringirChaveDoApp.php`.
- **CI:** o `.github/workflows/api.yml` tem um gate de 100% de cobertura em `api/app`. O código do Entregas não tem testes, então confira se o workflow está ativo antes de abrir um PR para a `main`.

## Minors avaliados e não aplicados (registro)

- **6C:** a deduplicação do log é por IP + rota, e não por IP + motivo.
- **6B:**
  - não há alerta automático se o Fleet-Ops renomear `startOrder`/`cancelOrder` (fica a nota no `CLAUDE.md`);
  - sem testes PHP (não há PHP local).
- **8:**
  - o segundo `ModelSelect` desabilitado;
  - nenhum aviso quando paradas são descartadas;
  - na troca de loja com a busca pendente, a coleta da loja anterior aparece por um instante.
- **9:** o `POST dashboards/(switch|reset-default)` ficou no `PERMITIDAS_INTERNAS`.
- **10:**
  - os campos lat/lng visíveis do modal não são lidos (vale o ponto do mapa);
  - o `checkForCheckoutSession` continua ativo, o que só importa com URL forjada.
- **11:**
  - a posição do motoboy vem sem horário (o rótulo diz "Última posição");
  - a busca de pedidos faz 2 consultas por tecla, o que já existia antes.
- **12:**
  - não há detecção de "pendente para sempre";
  - o pedido do extrato não tem link para o detalhe.
- **Código morto do upstream:** mantido de propósito, para o diff contra o upstream ficar pequeno.

## Notas para o deploy

- O `atualizar.sh api` roda o `deploy.sh`. Não há migration nova neste trabalho. O papel "Fleet-Ops Customer" vem do `fleetbase:create-permissions`.
- O `throttle:60,1` vale por usuário para as rotas `loja/*`.
- O extrato aceita no máximo 92 dias.
- O teste de isolamento depende dessas regras já em produção, como o limite de 92 dias e a trava do aceite.
