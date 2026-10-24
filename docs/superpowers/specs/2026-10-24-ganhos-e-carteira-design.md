# Início: "Ganhos · período" e "Minha carteira"

Data: 2026-10-24. Pedido do Edgard. Código: `entregas-navigator` (app) e `api/app` (rota nova da carteira).

## O problema

O número grande do Início ("A receber · <período>", `aReceberNoPeriodo`) é a soma dos lançamentos do período, pagamentos
incluídos. Um pagamento feito hoje por corridas da semana passada entra no período de hoje: com R$ 200 da semana passada,
R$ 70 de corridas hoje e um pagamento de R$ 200 hoje, o filtro Hoje mostra "Recebido a mais: R$ 130". Para o motoboy isso
não faz sentido.

## Decisões do Edgard (2026-10-24)

- O cabeçalho mostra **só o que ele fez no período** (opção "Só ganhos"; recusou o "falta receber das corridas do período"
  e o "saldo fixo + ganhos").
- O "Não pagar" e o estorno de corrida **descontam** dos ganhos, no dia em que foram lançados.
- Saem do cabeçalho: "Nenhum pagamento registrado ainda" / "Último pagamento", a linha "<período>: ganhos · recebido", o
  "Recebido a mais" e a chave Pix.
- Ao lado do valor, o botão **"Minha carteira"**, que abre uma folha com o que tem a receber, o que já recebeu e os
  últimos pagamentos (opção "Com pagamentos": precisa de rota nova na API).
- A lista detalhada do Início **não muda**, pagamentos incluídos.
- Visual: **sketch 008 B "Cartão da carteira"** (`entregas-navigator/.planning/sketches/008-ganhos-e-carteira/`).

## Cabeçalho do Início (`MeusGanhosScreen`)

```
GANHOS · HOJE
R$ 60,00                 [carteira]
                       Minha carteira
```

- Rótulo `Ganhos · <período>` (o mesmo `rotuloDoPeriodo` de hoje); acompanha o filtro (Hoje, 7 dias, Este mês, Mês
  passado, De/Até). Abre em Hoje, como hoje.
- Valor: `ganhosDoPeriodo(lancamentos)` em `src/utils/extrato.ts`, que substitui `aReceberNoPeriodo` e `totaisDoPeriodo`
  (saem, com os textos `aReceberNoPeriodo`, `recebidoAMais`, `recebidoAMaisDica`, `semPagamento`, `ultimoPagamento` e a
  linha de ganhos/recebido, se nada mais os usar).
- A barra compacta (cabeçalho recolhido) mostra o mesmo valor, sem o botão.
- Botão "Minha carteira" (sketch 008 B), à direita do valor: um quadrado de 56 px com cantos de 18 px, fundo `$textPrimary`
  (escuro), ícone de carteira (`faWallet`) em `$primary` (laranja), e o texto "Minha carteira" (12 px, negrito, `$sobrePrimario`)
  embaixo. Abre a folha da carteira.
- O valor diminui um pouco (de 56 para ~46 px, com `adjustsFontSizeToFit`) para caber "R$ 1.270,00" ao lado do botão.
- Carregando: "—", como hoje.

### `ganhosDoPeriodo`

Soma, em centavos, todos os lançamentos do período **menos** os pagamentos (`tipo === 'pagamento'`) e os estornos de
pagamento (o que `oQueOEstornoDesfaz` já reconhece como `'pagamento'`). Entram: corrida, diária, bônus, ajuste (positivo e
negativo, com ou sem pedido), saldo inicial e estorno de crédito.

- Exemplo do problema: Hoje = R$ 70,00; 7 dias = R$ 270,00, com ou sem o pagamento de R$ 200.
- Corrida de R$ 10 com "Não pagar" no mesmo dia: os dois se anulam.
- Pode dar negativo (estorno hoje de uma corrida do mês passado): mostra "−R$ 10,00", sem vermelho.
- Período sem lançamentos: R$ 0,00.

## Folha "Minha carteira" (`CarteiraSheet`, nova)

Folha que sobe de baixo (`Modal` do React Native, fundo escurecido, ✕ no topo; fecha também pelo voltar do Android e pelo
toque fora). Não depende do filtro do Início: mostra a conta inteira.

```
Minha carteira                       ✕
┌ cartão escuro ───────────────────┐
│ [carteira]              ENTREGAS │
│ A RECEBER                        │
│ R$ 70,00                         │
│ Tudo o que a central ainda vai   │
│ te pagar.                        │
│ ──────────────────────────────── │
│ RECEBIDO EM OUTUBRO    Chave Pix │
│ R$ 850,00          (11) 9876-... │
└──────────────────────────────────┘
ÚLTIMOS PAGAMENTOS
24/10  Pix       R$ 200,00  >
17/10  Pix       R$ 350,00  >
```

- **Cartão da carteira** (sketch 008 B): fundo `$textPrimary` (escuro), texto `$background`, cantos de 20 px, dois círculos
  laranja (`$primary`) no canto de cima à direita, o ícone de carteira em laranja e "ENTREGAS" à direita. Dentro do cartão: A
  receber (grande), a dica, e no rodapé (separado por um fio) o Recebido no mês à esquerda e a chave Pix à direita.
- **A receber:** `a_receber` (o saldo da conta). Negativo vira **"Adiantamento"** com o valor positivo, sem vermelho.
- **Recebido em <mês>:** `recebido_no_mes`, com o nome do mês de `mes` (`AAAA-MM`) em pt-BR, minúsculo.
- **Últimos pagamentos:** até 5, mais novo primeiro: data (`DD/MM`), forma (`rotuloDaForma`) e valor. O estornado sai
  riscado com o selo "Estornado". Toque: fecha a folha e abre o Recibo (`navigate('Recibo', { id })`). Sem pagamentos:
  "Nenhum pagamento ainda".
- **Chave Pix:** `pix.chave` (só leitura), no rodapé do cartão; sem chave, some.
- Carrega ao abrir (uma leitura por abertura). Carregando: indicador no lugar dos valores. Erro (rede, 404 da API antiga):
  "Não foi possível carregar a carteira" e o botão "Tentar de novo".

## API: `GET v1/entregas/motoboy/carteira`

- `MotoboyController::carteira`, no grupo das rotas `v1/entregas/motoboy/*` (token de motoboy pelo `MotoboyDaSessao`,
  `throttle:entregas-motoboy`), ao lado de `extrato`.
- Lógica em `App\Support\Entregas\Financeiro\Carteira::doMotoboy(companyUuid, motoboyUuid)`, `DB::table`, sem Models.
- Resposta:

```json
{
  "a_receber": 70.0,
  "mes": "2026-10",
  "recebido_no_mes": 850.0,
  "pagamentos": [
    { "id": "<uuid>", "valor": 200.0, "data": "2026-10-24", "forma": "pix", "estornado": false }
  ],
  "pix": { "chave": "11999994321", "tipo": "telefone" }
}
```

- `a_receber`: a soma de todos os lançamentos da conta (a mesma do `saldo` do `ExtratoDaConta`).
- `mes`: o mês atual no fuso da empresa (`Financeiro::hoje`, sem UTC).
- `recebido_no_mes`: soma dos pagamentos da conta com `data` no mês atual e `estornado_em` nulo.
- `pagamentos`: os 5 mais recentes da conta (`data` desc, `id` desc), **estornados incluídos** com `estornado: true`.
- `pix`: `ChavePix::doMotoboy` (`{chave, tipo}`, o mesmo formato do `conta.pix` do extrato) ou `null`.
- Valores com `round((float) …, 2)`. Motoboy sem conta no Financeiro: zeros, lista vazia.

## Implantação

1. `bash deploy/atualizar.sh api` (rota nova).
2. Push na `main` do `entregas-navigator` (APK obrigatório pela trava "Atualize o app").

Com o APK novo e a API antiga, só a carteira mostra o erro; o cabeçalho funciona. A rota `extrato` não muda (APKs antigos
seguem iguais).

## Testes

- App (`scripts/testes/extrato.teste.ts`): `ganhosDoPeriodo` com o exemplo do problema, "Não pagar" no mesmo dia, estorno
  de pagamento (não mexe nos ganhos), estorno de corrida, período vazio e resultado negativo; rótulo do mês da carteira e
  o "Adiantamento" para saldo negativo (funções puras).
- Servidor (`scripts/teste-php`): carteira com saldo positivo, saldo negativo, pagamento estornado fora do
  `recebido_no_mes` e presente na lista, pagamento do mês anterior fora do total, limite de 5 e conta vazia.
- `node scripts/i18n-check.cjs ...` com exit 0 no Delivery, se mexer em texto do console (não deve).

## Documentação

Atualizar "Início = extrato do Financeiro" em `api/app/Http/Controllers/Entregas/CLAUDE.md` e a cópia no `CLAUDE.md` do
`entregas-navigator`, e a rota nova em `api/app/Support/Entregas/Financeiro/CLAUDE.md`.
