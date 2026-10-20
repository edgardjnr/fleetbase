# Desempate por corridas do dia na distribuição

Decisão do Edgard de 2026-10-19. Complementa a "A fila" de `api/app/Support/Entregas/Distribuicao/CLAUDE.md`.

## Objetivo

Repartir melhor as corridas entre os motoboys. Quando vários motoboys entregariam o cliente novo com até **3 min** de
diferença, a oferta vai primeiro a quem fez **menos corridas hoje**. O cliente pode esperar até 3 min a mais por isso.

## Regra da fila

Muda só a ordenação do `FilaDeCandidatos::para`. A fila é a mesma nos dois ciclos (com e sem rodadas) e é recalculada a
cada oferta.

1. O tempo até o cliente (`tempo_s`) de cada candidato é calculado como hoje: encaixe, linha reta ou OSRM.
2. `melhor` = o menor `tempo_s` da fila.
3. **Grupo de desempate:** quem tem `tempo_s <= melhor + 180` (`Distribuicao::DESEMPATE_CORRIDAS_S = 180`; o limite
   exato entra no grupo).
4. O grupo vem primeiro, ordenado por `corridas_hoje` (menor primeiro). Empate: menor `tempo_s`; depois o livre; depois o
   `public_id`.
5. Os outros vêm depois, como hoje: menor `tempo_s`, o livre, o `public_id`.
6. Recusa ou vencimento: quem respondeu sai da fila, e o grupo é refeito a partir do mais rápido que sobrou.
7. Em rodadas, a fila só tem os candidatos do raio da rodada atual. O grupo é formado entre eles.

Exemplo (limite de 3 min):

| Motoboy | Tempo | Corridas hoje | Posição |
|---|---|---|---|
| Ana | 10 min | 4 | 3ª (no grupo, mais corridas) |
| Beto | 12 min | 1 | 1ª |
| Caio | 13 min | 2 | 2ª |
| Davi | 14 min | 0 | 4ª (fora do grupo: 4 min acima da Ana) |

Se o Beto recusar, a fila refeita fica Caio, Ana (grupo até 13 min) e Davi.

## Corridas hoje

Nova consulta `Candidatos::corridasHoje(string $empresa, array $motoboyUuids): array<uuid, int>`, chamada no
`Candidatos::elegiveis` junto das paradas. Atrás de uma closure trocável (`$buscarCorridasHoje`), como as outras duas.

- Pedidos da empresa com `driver_assigned_uuid` do motoboy e momento do aceite em hoje, no horário de Brasília (a partir
  das 00:00 de hoje, no fuso do app). Momento do aceite: `started_at`; se nulo, `dispatched_at` (pedido atribuído pela
  central e ainda não iniciado). As duas são `DATETIME` em hora de Brasília: compare com datas no fuso do app (regra do
  `api/CLAUDE.md`).
- Contam os pedidos em andamento e os concluídos. **Não contam** os cancelados (`StatusDoPedido::CANCELADOS`), os
  `expired` e os apagados (`deleted_at`).
- O pedido trocado de motoboy conta só para o motoboy atual (`driver_assigned_uuid`).
- Uma consulta para todos os candidatos (`GROUP BY driver_assigned_uuid`). Quem não aparece tem 0.
- **Falha da consulta:** log warning `[entregas] distribuição: não deu para contar as corridas de hoje; fila só por
  tempo` e todos com 0. Assim, a fila volta a ser só por tempo, e a oferta não trava.
- O candidato passa a ter `corridas_hoje` (int), e o `FilaDeCandidatos` o lê. O candidato sem a chave (testes antigos,
  chamadas diretas) vale 0.

## Saída da fila

Cada linha da fila ganha:

- `corridas_hoje` (int);
- `desempate` (bool): true quando a linha subiu por causa da regra, ou seja, ficou à frente de alguém com `tempo_s`
  menor.

As duas vão no JSON `fila` da distribuição (`Distribuicoes::gravarFila`) e no `GET int/v1/entregas/pedidos/{id}/distribuicao`.
A fila gravada antes do deploy não tem as chaves, e o painel trata a falta.

## Sem chave nova

A regra entra com o deploy da API, sem variável no stack. Para voltar, é preciso reverter o commit e fazer o deploy da API.

## Painel do console

Fleet-Ops → detalhe do pedido → painel "Distribuição", na fila calculada:

- "N hoje" (`fleet-ops.ui.distribuicao.corridas-hoje`, com plural: "1 corrida hoje" / "N corridas hoje") em cada linha
  que traz `corridas_hoje`;
- a marca "desempate" (`fleet-ops.ui.distribuicao.desempate`) quando `desempate` é true.

Sem as chaves (fila antiga), a linha fica como hoje. Funções puras em `utils/distribuicao.js`, com teste em
`scripts/teste-portal/distribuicao.test.mjs`. O `i18n-check` precisa sair com exit 0.

## App (tela Sobre)

No `entregas-navigator`, em `translations/pt.json` (e `en.json`), seção `SobreScreen`, uma frase nova depois da
`oferta1`:

> "Quem chegaria ao cliente até 3 minutos depois do mais rápido entra no desempate: a oferta vai primeiro para quem fez
> menos corridas hoje." (chave `ofertaDesempate`; a regra conta a partir do mais rápido, não entre pares.)

É só texto. O motoboy só vê a frase depois que o próximo APK for instalado. A regra vale no servidor desde o deploy da API.

## Testes

Em `scripts/teste-php/distribuicao-fila.php`:

- o exemplo da tabela (ordem Beto, Caio, Ana, Davi; `desempate` em Beto e Caio);
- o limite: um candidato a `melhor + 180` entra no grupo, e um a `melhor + 181` fica fora;
- empate em corridas: decide o menor tempo;
- todos com 0 corridas: a ordem de hoje (tempo, o livre, o `public_id`);
- candidato sem `corridas_hoje`: vale 0;
- `corridasHoje`: a falha da closure vira tudo 0, com o log.

Em `distribuicao-rodadas.php` e `distribuicao-ciclo.php`, os casos existentes continuam passando: sem
`$buscarCorridasHoje`, todos ficam com 0, e a ordem é a de hoje.

Contagem real (consulta SQL): conferida na produção pelo painel, porque o banco em memória dos testes não cobre o
`COALESCE` com o fuso. Se der, também num teste com o banco dos stubs.

## Documentação

`api/app/Support/Entregas/Distribuicao/CLAUDE.md`, "A fila" → "Ordem": a regra nova, a constante, a consulta e o log.
"Painel do console": as duas marcas novas.

## Riscos aceitos

- O cliente pode esperar até 3 min a mais.
- Às 00:00 a contagem zera, inclusive no meio do turno da noite.
- Pedido cancelado depois que o motoboy já foi até a loja não conta para ele.
- O pedido de teste (loja Terraço) conta como corrida.
