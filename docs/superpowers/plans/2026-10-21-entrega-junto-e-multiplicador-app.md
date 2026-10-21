# Entrega junto e multiplicador: app do motoboy — plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para executar este plano tarefa por tarefa. Os passos usam caixas (`- [ ]`) para acompanhar.

**Objetivo:** o motoboy vê, junto do valor, os selos "2ª entrega" (pagou pela segunda tabela) e "Chuva ×1,2" (multiplicador dele). Os selos aparecem no card de aceitar, nos detalhes do pedido e no Início (extrato).

**Arquitetura:** o valor já vem certo da API (plano do servidor). O app só lê dois campos novos e opcionais: `junto` (a posição, ou null) e `multiplicador` (`{nome, fator}` do motoboy, ou null). Eles vêm na rota do valor (`GET v1/entregas/motoboy/pedidos/{id}/valor`) e nos `detalhes` de cada corrida do extrato (`GET v1/entregas/motoboy/extrato`). Uma função pura (`selosDoValor`) transforma esses campos em selos, e os componentes que já mostram o valor desenham os selos com o `Selo` existente.

**Repo:** `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator` (ramo `main`). Este plano **não** é do repo Delivery.

**Stack:** React Native (Expo/Hermes), Tamagui, testes com `node --experimental-strip-types --test`.

**Pré-requisito:** nenhum para programar. Com a API antiga, os campos não vêm e os selos não aparecem. Para ver os selos, a API do plano do servidor precisa estar em produção.

---

## Antes de começar

- Leia o `CLAUDE.md` do navigator. Ele traz a regra dos utils sem import de valor: o Node exige `.ts` no import e o tsc do app recusa, por isso as funções puras recebem os formatadores por parâmetro ou ficam no mesmo arquivo.
- Crie um ramo `entrega-junto` a partir da `main`. **Cada push na `main` vira versão obrigatória** (trava "Atualize o app"), então o merge na `main` só acontece quando o Edgard pedir.
- Testes: `node --experimental-strip-types --test scripts/testes/<nome>.teste.ts`. Lint: `npx eslint <arquivos>`.
- Traduções: `translations/pt.json` e `translations/en.json`, interpolação `{{var}}`.

## Mapa dos arquivos

| Arquivo | Papel |
|---|---|
| `src/utils/ganhos.ts` | tipos novos, `formatarFator`, `selosDoValor` |
| `src/utils/extrato.ts` | `junto` e `multiplicador` no tipo `DetalhesDoLancamento` |
| `scripts/testes/ganhos.teste.ts` | testes |
| `src/components/entregas/CartaoDeOferta.tsx`, `CartaoDePedidoAberto.tsx` | `selos` no `DadosDoCartao` e o desenho |
| `src/components/AdhocOrderCard.tsx` | monta os selos do card |
| `src/screens/OrderScreen.tsx` | selos nos cartões e na faixa "Você recebe" |
| `src/components/ValorDaEntrega.tsx` | selos na variante `detalhado` |
| `src/screens/MeusGanhosScreen.tsx` | selos na linha da corrida (Início) |
| `translations/pt.json`, `translations/en.json` | textos |

---

### Tarefa 1: Função pura dos selos

**Arquivos:**
- Modificar: `src/utils/ganhos.ts`
- Modificar: `src/utils/extrato.ts`
- Modificar: `scripts/testes/ganhos.teste.ts`

- [ ] **Passo 1: Teste**

No `scripts/testes/ganhos.teste.ts`, acrescente `formatarFator` e `selosDoValor` ao import de `../../src/utils/ganhos.ts` e:

```ts
test('fator do multiplicador: vírgula e até duas casas', () => {
    assert.equal(formatarFator(1.2), '×1,2');
    assert.equal(formatarFator(1.25), '×1,25');
    assert.equal(formatarFator(2), '×2');
    assert.equal(formatarFator(1.234), '×1,23');
});

test('selos do valor: entrega junto (posição) e o multiplicador do motoboy', () => {
    assert.deepEqual(selosDoValor({ junto: 2, multiplicador: { nome: 'Chuva', fator: 1.2 } }), [
        { chave: 'ValorDaEntrega.seloJunto', valores: { posicao: '2' }, variante: 'neutro' },
        { chave: 'ValorDaEntrega.seloMultiplicador', valores: { nome: 'Chuva', fator: '×1,2' }, variante: 'ok' },
    ]);
    assert.deepEqual(selosDoValor({ junto: 3, multiplicador: null }), [{ chave: 'ValorDaEntrega.seloJunto', valores: { posicao: '3' }, variante: 'neutro' }]);
    assert.deepEqual(selosDoValor({ junto: null, multiplicador: null }), [], 'tabela normal, sem período');
    assert.deepEqual(selosDoValor({ junto: 1 }), [], 'posição 1 não é entrega junto');
    assert.deepEqual(selosDoValor({ multiplicador: { nome: '', fator: 1.5 } }), [{ chave: 'ValorDaEntrega.seloMultiplicador', valores: { nome: '', fator: '×1,5' }, variante: 'ok' }]);
    assert.deepEqual(selosDoValor(null), [], 'API antiga: sem os campos');
    assert.deepEqual(selosDoValor(undefined), []);
});
```

- [ ] **Passo 2: Rode e veja falhar**

`node --experimental-strip-types --test scripts/testes/ganhos.teste.ts`
Esperado: falha (exports inexistentes).

- [ ] **Passo 3: Implemente**

Em `src/utils/ganhos.ts`, troque o tipo `ValorDoPedido` (linha ~39) por:

```ts
/** Multiplicador do período (chuva…) que vale para o motoboy: o nome e o fator dele (nunca o da loja). */
export type MultiplicadorDoMotoboy = { nome: string; fator: number };

export type ValorDoPedido = {
    pedido: string;
    km: number | null;
    aproximado: boolean;
    faixa: Faixa | null;
    valor: number | null;
    // entrega junto: a posição (2, 3…) quando paga pela segunda tabela; null ou ausente (API antiga) = tabela normal
    junto?: number | null;
    multiplicador?: MultiplicadorDoMotoboy | null;
};
```

E no fim do arquivo:

```ts
/** "×1,2": o fator com vírgula e até duas casas (sem Intl: o Hermes do app não garante o pt-BR). */
export function formatarFator(fator: number): string {
    return '×' + String(Math.round(fator * 100) / 100).replace('.', ',');
}

/** Um selo pronto para traduzir: a chave, os valores da interpolação e a variante do componente Selo. */
export type SeloDoValor = { chave: string; valores: Record<string, string>; variante: 'neutro' | 'ok' };

/**
 * Selos ao lado do valor (card de aceitar, detalhes, Início): "2ª entrega" quando o pedido pagou pela segunda tabela
 * (pego enquanto ele levava outro) e "Chuva ×1,2" no período de multiplicador. Serve para a rota do valor e para os
 * detalhes da corrida do extrato (mesmos campos `junto` e `multiplicador`). Sem os campos (API antiga): nenhum selo.
 */
export function selosDoValor(dados: { junto?: number | null; multiplicador?: MultiplicadorDoMotoboy | null } | null | undefined): SeloDoValor[] {
    const selos: SeloDoValor[] = [];
    const posicao = Number(dados?.junto);
    if (Number.isFinite(posicao) && posicao >= 2) {
        selos.push({ chave: 'ValorDaEntrega.seloJunto', valores: { posicao: String(posicao) }, variante: 'neutro' });
    }
    const multiplicador = dados?.multiplicador;
    if (multiplicador && Number.isFinite(Number(multiplicador.fator))) {
        selos.push({ chave: 'ValorDaEntrega.seloMultiplicador', valores: { nome: multiplicador.nome ?? '', fator: formatarFator(Number(multiplicador.fator)) }, variante: 'ok' });
    }

    return selos;
}
```

Em `src/utils/extrato.ts`, no tipo `DetalhesDoLancamento`, depois de `cancelado_origem?: …;`:

```ts
    // entrega junto (posição, quando pagou pela segunda tabela) e o multiplicador dele ({nome, fator}); ausentes na API antiga
    junto?: number | null;
    multiplicador?: { nome: string; fator: number } | null;
```

- [ ] **Passo 4: Rode e veja passar**

```bash
node --experimental-strip-types --test scripts/testes/ganhos.teste.ts
node --experimental-strip-types --test scripts/testes/extrato.teste.ts
```
Esperado: tudo passa.

- [ ] **Passo 5: Commit**

```bash
git add src/utils/ganhos.ts src/utils/extrato.ts scripts/testes/ganhos.teste.ts
git commit -m "Entrega junto e multiplicador: selos do valor (função pura) e campos novos dos tipos"
```

---

### Tarefa 2: Textos

**Arquivos:** `translations/pt.json`, `translations/en.json`

- [ ] **Passo 1:** no bloco `"ValorDaEntrega"` do `pt.json`, depois de `"semValor": "ainda não cadastrado"` (lembre da vírgula):

```json
        "seloJunto": "{{posicao}}ª entrega",
        "seloMultiplicador": "{{nome}} {{fator}}"
```

No `en.json`, no mesmo bloco:

```json
        "seloJunto": "Delivery #{{posicao}} together",
        "seloMultiplicador": "{{nome}} {{fator}}"
```

- [ ] **Passo 2:** confira que os dois JSON continuam válidos: `node -e "JSON.parse(require('fs').readFileSync('translations/pt.json','utf8'));JSON.parse(require('fs').readFileSync('translations/en.json','utf8'));console.log('ok')"`.

- [ ] **Passo 3: Commit**

```bash
git add translations/pt.json translations/en.json
git commit -m "Textos dos selos de entrega junto e multiplicador"
```

---

### Tarefa 3: Card de aceitar e detalhes do pedido

**Arquivos:**
- Modificar: `src/components/entregas/CartaoDeOferta.tsx`
- Modificar: `src/components/entregas/CartaoDePedidoAberto.tsx`
- Modificar: `src/components/AdhocOrderCard.tsx`
- Modificar: `src/screens/OrderScreen.tsx`
- Modificar: `src/components/ValorDaEntrega.tsx`

- [ ] **Passo 1: `DadosDoCartao` ganha `selos`**

Em `CartaoDeOferta.tsx`, no tipo `DadosDoCartao`, depois de `valor: string | null; …`:

```tsx
    selos: { texto: string; variante: 'neutro' | 'ok' }[]; // "2ª entrega", "Chuva ×1,2" (já traduzidos); vazio sem eles
```

No `CartaoDeOferta`, logo depois do bloco do valor (o `{dados.valor ? (<Text …>{dados.valor}</Text>) : dados.carregandoValor ? (…) : null}` das linhas ~121–129), acrescente:

```tsx
                    {dados.selos.length > 0 && (
                        <XStack gap={6} justifyContent='center' flexWrap='wrap'>
                            {dados.selos.map((selo) => (
                                <Selo key={selo.texto} variante={selo.variante}>
                                    {selo.texto}
                                </Selo>
                            ))}
                        </XStack>
                    )}
```

No `CartaoDePedidoAberto`, dentro do `YStack` da esquerda (linhas ~48–59), depois do bloco do valor:

```tsx
                            {dados.selos.length > 0 && (
                                <XStack gap={6} flexWrap='wrap'>
                                    {dados.selos.map((selo) => (
                                        <Selo key={selo.texto} variante={selo.variante}>
                                            {selo.texto}
                                        </Selo>
                                    ))}
                                </XStack>
                            )}
```

Confira se `Selo` e `XStack` já estão importados nos dois arquivos. Se não estiverem, importe `Selo` de `./Selo` e `XStack` de `tamagui`, como os outros componentes da pasta fazem.

- [ ] **Passo 2: `AdhocOrderCard` monta os selos**

Acrescente `selosDoValor` ao import de `../utils/ganhos` (o mesmo arquivo de onde vêm `formatarReais` e `formatarKm`). No `useMemo` do `dados` (linhas ~115–142), no objeto devolvido, depois de `valor: …,`:

```tsx
            selos: selosDoValor(valor).map((selo) => ({ texto: t(selo.chave, selo.valores), variante: selo.variante })),
```

O `valor` e o `t` já estão nas dependências do `useMemo`.

- [ ] **Passo 3: `OrderScreen`**

Acrescente `selosDoValor` ao import de `../utils/ganhos`. Depois do `faixaDaEntrega` (linhas ~949–954):

```tsx
    const selosDaEntrega = useMemo(() => selosDoValor(valorDaEntrega).map((selo) => ({ texto: t(selo.chave, selo.valores), variante: selo.variante })), [valorDaEntrega, t]);
```

No `dadosDoCartao` (linhas ~968–972), depois de `valor: valorEmReais,`: `selos: selosDaEntrega,`.

Na faixa recolhida "Você recebe" (linhas ~1189–1212), dentro do `XStack alignItems='baseline' gap='$2'`, depois do `<Text>` do `valorEmReais`:

```tsx
                                            {selosDaEntrega.map((selo) => (
                                                <Selo key={selo.texto} variante={selo.variante}>
                                                    {selo.texto}
                                                </Selo>
                                            ))}
```

(Importe `Selo` de `../components/entregas/Selo`, se a tela ainda não importar.)

- [ ] **Passo 4: `ValorDaEntrega.tsx` (variante `detalhado`)**

O componente recebe `pedido` e usa o `useValorDaEntrega`. Na variante `detalhado` (linhas ~75–95), dentro do `YStack flexShrink={1}` da esquerda, depois do `<Text>` do `dinheiro`:

```tsx
                        {selos.length > 0 && (
                            <XStack gap={6} flexWrap='wrap' mt='$1'>
                                {selos.map((selo) => (
                                    <Selo key={selo.texto} variante={selo.variante}>
                                        {selo.texto}
                                    </Selo>
                                ))}
                            </XStack>
                        )}
```

com, no corpo do componente (perto de onde o `dinheiro` é calculado):

```tsx
    const selos = selosDoValor(valor).map((selo) => ({ texto: t(selo.chave, selo.valores), variante: selo.variante }));
```

(Use o nome da variável que o componente já dá ao retorno do `useValorDaEntrega`; importe `selosDoValor` de `../utils/ganhos` e `Selo` de `./entregas/Selo`.)

- [ ] **Passo 5: Todo `DadosDoCartao` montado no app tem `selos`**

`grep -rn "DadosDoCartao" src` lista quem monta o tipo. Cada objeto precisa do campo `selos` (vazio `[]` onde não há valor, ex.: um cartão de pedido encerrado). Rode o tsc do projeto (`npx tsc --noEmit`, se o projeto usar; confira no `package.json`) para achar o que faltou.

- [ ] **Passo 6: Lint e commit**

```bash
npx eslint src/components/entregas/CartaoDeOferta.tsx src/components/entregas/CartaoDePedidoAberto.tsx src/components/AdhocOrderCard.tsx src/screens/OrderScreen.tsx src/components/ValorDaEntrega.tsx
git add src/components src/screens/OrderScreen.tsx
git commit -m "Card de aceitar e detalhes: selos de entrega junto e multiplicador ao lado do valor"
```

---

### Tarefa 4: Início (extrato)

**Arquivos:**
- Modificar: `src/screens/MeusGanhosScreen.tsx`

- [ ] **Passo 1:** acrescente `selosDoValor` ao import de `../utils/ganhos` (o arquivo já importa `chaveDoCanceladoPago` de lá). No `renderizarLancamento` (linhas ~684–740), dentro do `XStack alignItems='center' gap={6} flexWrap='wrap'`, depois do selo do cancelado pago (linha ~715):

```tsx
                        {item.tipo === 'corrida' &&
                            selosDoValor(detalhes).map((selo) => (
                                <Selo key={selo.chave} variante={selo.variante}>
                                    {t(selo.chave, selo.valores)}
                                </Selo>
                            ))}
```

- [ ] **Passo 2:** `npx eslint src/screens/MeusGanhosScreen.tsx` e rode os testes de `scripts/testes/` (`for f in scripts/testes/*.teste.ts; do node --experimental-strip-types --test "$f" || echo "FALHOU $f"; done`).

- [ ] **Passo 3: Commit**

```bash
git add src/screens/MeusGanhosScreen.tsx
git commit -m "Início: selos de entrega junto e multiplicador nas corridas do extrato"
```

---

### Tarefa 5: Documentação e entrega

- [ ] **Passo 1:** no `CLAUDE.md` do navigator, na seção copiada do servidor (a do card de aceitar, com `GET v1/entregas/motoboy/pedidos/{id}/valor`), acrescente a mesma frase do `api/app/Http/Controllers/Entregas/CLAUDE.md` do Delivery: "A resposta traz `junto` (a posição 2, 3… quando pagou pela segunda tabela, senão null) e `multiplicador` (`{nome, fator}` do motoboy ou null), e o valor já é a prévia para este motoboy antes da atribuição." E acrescente que os selos saem do `selosDoValor` (`src/utils/ganhos.ts`), também para os `detalhes` das corridas do extrato.

- [ ] **Passo 2: Conferência no celular** (Edgard, com adb ou o APK do ramo): com um período de ×1,01 criado no console e a API nova em produção, o card de um pedido aberto mostra "Chuva ×1,01". Aceitar um segundo pedido com o primeiro ainda aberto mostra "2ª entrega" (só se houver segunda tabela). Não use o Terraço (loja real) sem combinar.

- [ ] **Passo 3: Merge na `main` só com o ok do Edgard.** Cada push na `main` vira versão obrigatória. A ordem segura é: API em produção (plano do servidor) → console (plano do console) → merge do app.

- [ ] **Passo 4: Commit da documentação**

```bash
git add CLAUDE.md
git commit -m "docs: selos de entrega junto e multiplicador no app"
```
