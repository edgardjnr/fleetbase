# Chat da loja com os motoboys — plano de implementação

> Execução inline nesta sessão (pedido do Edgard: autônomo até commit e push). Spec:
> `docs/superpowers/specs/2026-10-04-chat-da-loja-design.md`.

**Objetivo:** o operador da loja conversa, pelo portal, com o motoboy do pedido e com qualquer motoboy do mapa; a central
participa e o motoboy usa o chat do app.

**Arquitetura:** rotas nossas (`int/v1/entregas/loja/conversas*`) por cima das tabelas de chat do Fleetbase; o canal é
marcado no `meta` (loja × motoboy). No portal, um serviço do engine guarda o estado, uma gaveta mostra a lista e a
conversa, e a consulta é periódica.

**Stack:** Laravel 10 (api/app), Ember (customer-portal engine), php-wasm e node --test.

## Arquivos

| Arquivo | Responsabilidade |
|---|---|
| `api/app/Support/Entregas/ConversasDaLoja.php` (novo) | abrir/achar o canal, participantes, listar, mensagens, enviar, ler; funções puras (nome, papel, texto, formato) |
| `api/app/Http/Controllers/Entregas/ConversasDaLojaController.php` (novo) | as 4 rotas, validação e respostas |
| `api/app/Support/Entregas/MotoboysNoMapaDaLoja.php` | `motoboyDoIdOpaco()`: o Driver visível no mapa pelo id opaco |
| `api/app/Providers/RouteServiceProvider.php` | rotas e o limitador `entregas-loja-conversas` |
| `scripts/teste-php/conversas-da-loja.php` (novo) | teste das funções puras |
| `packages/customer-portal/addon/utils/conversas.js` (novo) | funções puras do front |
| `scripts/teste-portal/conversas.test.mjs` (novo) | teste das funções puras do front |
| `packages/customer-portal/addon/services/entregas-conversas.js` (+ `app/`) | estado: gaveta aberta, lista, conversa atual, consultas |
| `packages/customer-portal/addon/components/portal/conversas.{hbs,js}` (+ `app/`) | a gaveta (lista, nova conversa, conversa) |
| `packages/customer-portal/addon/components/portal/order/workspace.{hbs,js}` | botão Conversas com o total de não lidas e a gaveta |
| `packages/customer-portal/addon/components/portal/order/details/motoboy.{hbs,js}` + `details.hbs` | botão Conversar com o motoboy |
| `packages/customer-portal/addon/styles/customer-portal-engine.css` | estilos da gaveta |
| `packages/customer-portal/translations/{en-us,pt-br}.yaml` | textos `customer-portal.ui.entregas.chat-*` |
| `CLAUDE.md` | seção do portal da loja |

## Tarefas

- [ ] 1. Funções puras do PHP (`ConversasDaLoja`: `nome`, `papel`, `textoValido`, `mensagem`) com teste php-wasm primeiro.
- [ ] 2. `ConversasDaLoja` com banco (`abrir`, `daLoja`, `listar`, `mensagens`, `enviar`) e `MotoboysNoMapaDaLoja::motoboyDoIdOpaco`; sintaxe pelo `sintaxe.mjs`.
- [ ] 3. Controller e rotas; sintaxe.
- [ ] 4. Funções puras do front (`utils/conversas.js`) com teste node primeiro.
- [ ] 5. Serviço `entregas-conversas` (consultas com `espera()` e pausa com a aba oculta).
- [ ] 6. Gaveta `Portal::Conversas`, botão no cabeçalho, botão no painel do motoboy, CSS e traduções.
- [ ] 7. Verificação: testes, `i18n-check`, `@babel/parser`, build do console.
- [ ] 8. CLAUDE.md, commits (tradução das atividades, app, chat da loja) e push dos dois repositórios.
