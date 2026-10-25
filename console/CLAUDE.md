# Console (Ember)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

Convenções de tradução pt-BR (valem também para `console/translations/`): `packages/CLAUDE.md`.

## Build do console

- O Docker usa a raiz como contexto: `docker build -f console/Dockerfile .`. O `console/Dockerfile.dockerignore` inclui só `console` e os pacotes frontend.
  - Padrões com `/` no final excluíam tudo. Valide com `@balena/dockerignore` antes de mexer.
- Build local (Windows): `cd console && DISABLE_RUNTIME_CONFIG=false pnpm build --environment production`, ~10 min.
  - O `packages/ember-ui/index.js` tem uma regex do intl-tel-input ajustada para aceitar `\`.
- Para testar o build local contra a API de produção:
  - grave `dist/fleetbase.config.json` com `API_HOST=https://entregas-api...` e sirva com `npx serve -s dist -l 4200`;
  - o CORS da API aceita `http://localhost:4200`;
  - faça login nessa aba.
- O `~/postcss.config.mjs` da home do Windows atrapalha builds Tailwind v4 em subpastas. O console usa Tailwind 3 e não é afetado.

## Admin e menus enxutos (2026-10-25)

- `console/app/router.js` é gerado do `router.map.js` no build, mas é versionado: mude os dois. O build às vezes reordena os `mount` (iam e fleet-ops); isso não é mudança, descarte.
- Admin sem "Platform API Token", Fila e Socket no menu (`controllers/console/admin.js`; as rotas ficam, sem link). Rotas mortas `admin/config/database|cache` e `auth.portal-login` apagadas. Detalhe da organização sem a aba "Extensões"; a rota `extensions-tab` fica para abas registradas por extensões (nenhuma hoje).
- "Criar ou entrar em organização" (menu da organização, `ember-ui/.../layout/header.js`) e o botão "Criar organização" (Conta → Organizações) só para `currentUser.isAdmin`; as ações `createOrJoinOrg`/`createOrganization` conferem de novo, e a API dá 403 (ver "Organização" em `deploy/CLAUDE.md`).
- Developers sem os atalhos Webhooks, Sockets e Events no menu do topo (as telas continuam dentro de Developers). IAM sem Grupos (rota fora do `routes.js`) e sem a aba Usuários → Clientes: logins de loja só pela tela Lojas.
- `console/package.json` sem fleetbase-extensions-indexer, postcss-nth-list, postcss-custom-properties, normalize.css e fs. O lock se atualiza com `npx pnpm@11.0.9 install --lockfile-only` (o `packageManager` pede o 11.0.9).

## Moldura do console (cabeçalho + menu lateral, 2026-10-25)

A moldura saiu do sketch 005 e do 006, variante C (`.planning/sketches/006-moldura-do-console/`).
- Cabeçalho e menu lateral formam uma moldura em tom de areia (`--pi-cartao-2`, que segue o claro e o escuro). O
  conteúdo fica numa folha com o canto de cima à esquerda arredondado.
- Os módulos do cabeçalho ficam numa barra segmentada, com o ativo em laranja.
- No menu, cada ícone fica num quadradinho: o ativo em laranja e o "Criar novo pedido" em tinta.
- **CSS:** `console/app/styles/moldura.css`.
  - Os seletores começam com `html body` para vencer o `light-theme.css`, o `next.css` e o `sidebar-navigator.css` do
    ember-ui.
  - Tudo fica preso a `header.next-view-header` e a `.next-view-container`, porque as sobreposições também usam
    `.next-view-header-left/right`.
  - Os tokens `--pi-*` estão no `body` (`painel-inicial.css`).
- **Fleet-Ops:**
  - O menu é um trilho de 72 px que abre por cima (256 px) ao passar o mouse ou ao focar. Vale só a partir de 768 px;
    no celular, vale o "Console no celular" (abaixo). O seletor é `body[class*='console-fleet-ops']`.
  - A lista é direta, em seções com título: o `Layout::Sidebar::Navigator` do ember-ui ganhou `@plano`, ligado pelo
    `fleet-ops-sidebar.hbs` (getter `secoes` no `navigator.js`). Sem `@plano`, os outros módulos, como o IAM,
    continuam com o "entrar no grupo e voltar".
  - A lista "Operações ao vivo" (`OperationsMonitor`) saiu do menu. Os motoboys ao vivo ficam no painel Motoboys da
    tela de pedidos e no painel inicial.
- **Para voltar:** tire o `@import 'moldura.css'` do `app.css` e o `@plano` do `fleet-ops-sidebar.hbs`.

## Console no celular (2026-10-25)

Saiu do sketch 007, variante B "Doca flutuante" (`.planning/sketches/007-console-no-celular/`). Vale abaixo de 768 px
(o `isMobile` do ember-responsive, `(max-width: 767px)`).
- **Rodapé:** `components/doca-celular.{js,hbs}`, no `templates/console.hbs` no lugar do `<Layout::MobileNavbar>` do
  Fleetbase (que listava os 12 `headerMenuItems` rolando para o lado; o componente segue no ember-ui, sem uso).
  - Cinco destinos fixos: Início (`console.home`), Pedidos (`operations.orders.index` com `layout=kanban`), Mapa (a
    mesma rota com `layout=map`), Motoboys (`management.drivers.index`) e Mais.
  - A aba ativa sai da rota e do `layout`. Tabela também conta como Pedidos.
  - O "+" flutuante (novo pedido) aparece só no Início e em Pedidos, na tela em si. No Mapa fica o botão da barra.
- **Mais:** uma folha de baixo com a conta, os atalhos em grade e o tema e o sair.
  - Os atalhos repetem as telas do trilho do Fleet-Ops que ficaram (lista fixa no getter `grupos`; o Financeiro e
    Lojas só para o admin). Os outros módulos vêm dos `universe.headerMenuItems`, pela permissão `<id> see extension`.
  - **Tela nova no menu do Fleet-Ops? Ponha também no `grupos`.**
- **Menu lateral:** fica escondido no celular por CSS. Fora do Início e do Fleet-Ops (IAM, Desenvolvedores, Conta,
  Admin), o "Menu desta área" do Mais o abre (classe `ent-menu-lateral-aberto` no `body`), e ele fecha na troca de rota.
- **Cabeçalho:** sem o botão do menu lateral, o idioma e o chat. Ficam o logo, os avisos, a organização e o avatar.
- **Pedidos:** o Quadro vira uma lista de cartões empilhados por situação (Sem motoboy, Indo à loja, A caminho,
  Concluídos). O Mapa continua com a folha de baixo da tela de pedidos.
- **Estilo:** `console/app/styles/celular.css`, com os tokens `--pi-*`. **Textos:** `console.ui.celular.*`.
- **Não ficou do sketch:** o título grande que encolhe ao rolar, porque cada tela tem o próprio cabeçalho.
- **Para voltar:** volte o `<Layout::MobileNavbar …>` no `console.hbs` e tire o `@import 'celular.css'` do `app.css`.

## Marca Entregas RestaurantePro (sem Fleetbase na tela)

- Nome: `app.name` = "Entregas RestaurantePro" em todos os idiomas do console (título da aba e `{appName}`). Os textos de tradução não citam a Fleetbase (só os de licença `ember-ui.modals.legal-notice.*`, que não aparecem).
- Imagens em `console/public`: `images/icon.png` (símbolo num quadrado branco), `images/logo.png` (logo completo) e os favicons, gerados de `Documents/RestaurantePro/logo-512.png` (Pillow).
- Marca padrão da API (ícone do cabeçalho, login, portal e e-mails): `api/config/fleetbase.php` sobrescreve só `branding` do core-api (`mergeConfigFrom`), apontando para as imagens do console. Imagem enviada em Admin → Marca tem prioridade.
- Rodapé "Fleetbase vX · Aviso legal" e o modal de licença: desligados (`DISABLE_FLEETBASE_ATTRIBUTION` padrão `true` no `config/environment.js` e no `console/Dockerfile`), por decisão do Edgard em 2026-10-04. A AGPL-3.0 continua exigindo oferecer o código modificado a quem usa pela rede.
- Fora da tela: menu do usuário sem Discord, Ajuda (GitHub), Documentação, Novidades, versão e "Atalhos de teclado"; sem o painel de documentação (`<DocsPanel />` saiu do `templates/console.hbs`; componente e serviço seguem no ember-ui, porque os engines injetam o serviço); tabelas vazias sem botão de guia (`table/empty-state.js`); hubs Recursos/Configurações sem a caixa "Guias"; painel inicial sem os cards Blog/GitHub (componentes apagados) e sem "Recursos recomendados".
- **Tela de login = landing** (2026-10-10, `/impeccable`; contexto em `PRODUCT.md`): `templates/auth/login.hbs` + `app/styles/landing.css` (classes `ent-*`, tokens OKLCH, Barlow/Barlow Condensed do Google Fonts no `index.html`). Fala com a operadora de frota: hero laranja com o login à direita (no celular, logo abaixo do título), mapa ilustrado com os capacetes do console andando (SMIL; parado com `prefers-reduced-motion`; PNGs copiados em `console/public/images/entregas/`, fora do fingerprint no `ember-cli-build.js`), a rota do pedido em 4 paradas, as três telas, a tabela de faixas de exemplo e o CTA do WhatsApp comercial do RestaurantePro ((16) 99199-9000, `whatsappComercial` no controller). Textos em `console.ui.landing.*`. O `controllers/auth.js` (`ehLanding`) tira o cartão centralizado só no `auth.login`; as outras telas de auth não mudaram. O tema escuro do Fleetbase (`body[data-theme="dark"] a:not([class*="text-"])` e os `.btn-link`) vaza para a página: os seletores do `landing.css` têm especificidade alta de propósito. Os botões do registry `auth:login` (Portal do cliente, Rastrear Pedido) continuam, no rodapé do painel.
- **Motoboy que desce a landing** (2026-10-10, skill `SKILL-videos` da área de trabalho: vídeo → quadros → canvas ligado à rolagem): componente `EntregasMotoboy` (`console/app/components/entregas-motoboy.*`, no fim do `auth/login.hbs`), trajeto em funções puras (`app/utils/entregas-motoboy-trajeto.js`, teste `scripts/teste-portal/entregas-motoboy-trajeto.test.mjs`). Personagem e vídeos gerados no Higgsfield (Kling 3, fundo verde; fontes e ângulos em `C:/tmp/motoboy`, fora do repo), recortados com ffmpeg (chroma + regra de matiz, que tira a sombra verde-escura sem comer os pretos) em WebP com transparência: `console/public/images/entregas/motoboy/<trecho>/NNN.webp` + `manifesto.json` (`versao` entra no `?v=` dos quadros: suba ao trocar os quadros). Trechos: partida (de frente → de lado), pilotando (loop), retorno (meia-volta), rampa e entrega (tira a pizza da bag); virado para a esquerda = espelhado no canvas. Roteiro (poucas paradas, para fluir): aparece assim que a página carrega, num canto livre (embaixo à direita; em telas baixas, ao lado do painel de login; no celular, em cima do painel, descendo à frente dele até a linha enquanto o painel sobe), desce a rampa e atravessa o mapa para a esquerda, meia-volta e uma travessia longa para a direita por passos e telas, meia-volta e desce devagar pelas faixas até a chamada final, onde para (no desktop, no vão entre o título e o texto; no celular, ao lado do botão) e entrega. Travessia em velocidade constante, no máximo 2 px de lado por px rolado (teste), e a posição persegue a rolagem com amortecimento (um clique da roda vira um deslize). **Rolando para cima, ele faz o caminho inverso de frente, nunca de ré** (decisão do Edgard, 2026-10-10): o lado se inverte, as rodas giram para a frente, a rampa vira pilotagem subindo e, ao trocar o sentido (depois de 6 px, `atualizarSentido`), ele dá meia-volta com o trecho "retorno" tocado pelo tempo (~0,55 s, `girar`/`quadroDoGiro`); de frente no topo ou com "reduzir animações", vira na hora. Reações: oferta aceita (verde), passos acendem na ordem, telas ganham a faixa laranja, a margem da tabela conta de zero (`data-valor` no `td`), o botão final pulsa. Entradas das seções pelo GSAP (tipos variados). GSAP 3.13 + ScrollTrigger e Lenis 1.3 ficam em `console/public/landing/` (fora do fingerprint e do bundle, carregados só pela landing; o `pnpm-lock` não muda). O Lenis rola o `.ent-landing`, e o `rolarPara` do controller usa o `scrollTo` dele. Com "reduzir animações" (no Windows, "Efeitos de animação" desligado já liga isso no Chrome) ele aparece e só anda com a rolagem: sem Lenis, sem inclinação, sem as entradas das seções e sem a contagem. Posições medidas pela cadeia de offsets (as animações de entrada deslocam o `getBoundingClientRect`). Painel de login e cartão de oferta ficam por cima dele (`z-index: 31`). Falha ao carregar = página como antes.
- **Login do portal da loja e rastreio no mesmo visual** (2026-10-10, `/impeccable`): os tokens e componentes ficam em `.ent-tema` (`landing.css`) e as telas em `console/app/styles/entregas-telas.css` (o CSS do console vale nas telas dos engines). Portal: `customer-portal/addon/templates/portal-auth/login.hbs`, faixa laranja com o que a loja faz no portal e o formulário; "É da central? Entrar no console" no lugar do "Voltar ao console"; textos em `customer-portal.ui.auth.login.*`. Rastreio (`/~/track-order`, cliente final): `fleetops/addon/components/order-tracking-lookup.*`, status em frase para o cliente (`situacao.*`), previsão em minutos, capacete na cor da situação no mapa e no bloco do motoboy, pin da loja na coleta e alfinete vermelho no destino (`createMarker` do routing machine) e itens só quando existem. Os dois são página inteira fixa por cima do layout (`portal-auth.hbs` e `virtual.hbs` não mudaram).
- Ficam com o nome da Fleetbase, de propósito: os modelos de importação baixados do S3 dela (`Fleetbase_*_Import_Template.xlsx`), os e-mails transacionais do PHP e os nomes técnicos (pacotes `@fleetbase/*`, chaves de tradução).
