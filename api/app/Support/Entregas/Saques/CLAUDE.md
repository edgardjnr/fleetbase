# Saque Pix do motoboy (Asaas da central)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

O motoboy saca o saldo pelo app e o dinheiro cai na chave Pix cadastrada pela central, saindo da conta Asaas **da
central**. Spec: `docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md`; plano do servidor:
`docs/superpowers/plans/2026-10-26-saque-pix-asaas-api.md`. Modelo: o pagamento de freelancers do RestaurantePro
(`restaurantepro-main`, edge `freelancer-payout`).

## Segredos

- **Chave de API do Asaas:** `entregas_asaas_conexoes.chave_cifrada`, AES-256-GCM (`Cofre`). A chave que cifra é o
  Docker secret `entregas_saque_chave` (`/run/secrets/…`, `services.entregas.saque_chave_arquivo`), só na API e no
  agendador. O `company_uuid` entra como dado autenticado do GCM (`Cofre::cifrar($texto, $contexto)` /
  `decifrar($cifrado, $contexto)`, AAD `entregas-asaas:<company_uuid>`): o cifrado de uma central copiado para a linha de
  outra não decifra. Sem o secret: conectar 503, envio falha e devolve, a rotina não consulta. Secret trocado depois de
  conectar (a chave gravada não decifra): testar responde 422 pedindo para conectar a chave de novo.
- **Token da validação:** só o sha256 (`token_hash`), mostrado uma vez no `POST financeiro/saque-pix/token`.
- Nunca em log ou resposta: a chave, o token e a chave Pix inteira (`Saque::chaveEscondida`). A chave tem
  `#[\SensitiveParameter]` (`AsaasCliente::__construct`, `Cofre::cifrar`, `ConexaoAsaas::conectar`): some do stack trace.
- **Só https:** o `AsaasCliente` não chama base sem `https://` (`ASAAS_API_URL` errada: status 0 e warning
  "ASAAS_API_URL precisa ser https") e não segue redirecionamento (`allow_redirects` false: o `access_token` iria junto).
- **Senha do admin** (`senhaConfere`, "Senha incorreta." 422) em conectar, desconectar, gerar o token e testar a
  validação.

## Camadas

1. Chave Pix e valor sempre do banco; saldo recalculado com a linha do motoboy travada (`lockForUpdate`). O pedido relê a
   conexão travada na mesma transação (central desligada ou desconectada no meio: recusa); desconectar trava a mesma linha
   antes de conferir saque em andamento. Dois "conectar" ao mesmo tempo: o segundo vira troca (a wallet tem de bater).
2. **Validação de saque do Asaas obrigatória** (`ValidacaoDeSaque`, `POST v1/entregas/asaas/validar-saque`). **A
   validação nunca grava o `transfer_id`**: quem grava é o envio, com o id da resposta do NOSSO POST (uma transferência
   criada por quem tem a chave de API não "toma" um saque). Aprova:
   - **por id:** o saque em andamento da central com `transfer_id` = `transfer.id`, valor igual e, se o pedido trouxer, a
     mesma chave Pix (`transfer.bankAccount.pixAddressKey` ou `transfer.pixAddressKey`, não vazia) e o mesmo
     `externalReference`;
   - **sem id** (o Asaas chamou antes da resposta do POST), só saques `enviando` sem id criados há até 2 min: (a) o pedido
     traz `transfer.externalReference` = `saque:<uuid>` de um deles, valor igual (e chave igual, se vier); ou (b) sem
     `externalReference`, exatamente **um** candidato com o mesmo valor **e** o pedido traz a chave Pix, igual à dele (o
     dinheiro iria para o próprio motoboy). Dois candidatos do mesmo valor ou sem chave: recusa.
   - Telefone comparado inteiro: as duas chaves viram 55 + DDD + número (10 ou 11 dígitos ganham "55"), `===`.
   - Ao aprovar, grava `entregas_saques.aprovado_em`.

   O teste de R$ 1,00 é recusado e confirma (`validacao_confirmada_em`). Sem ela, o saque não liga. **Teste:** um por vez
   (o anterior sem resposta da validação segura por 2 min: "Espere o resultado do teste anterior."), no máximo 5 por dia
   de Brasília (`testes_no_dia`, `testes_dia`: "Limite de testes de hoje atingido."), com quem pediu
   (`teste_pedido_por`) e a chave Pix escondida no log. Teste que o Asaas recusa ou não responde volta a validação e o
   teste ao que eram: não desliga o saque de uma central já confirmada (se a validação confirmou durante a chamada,
   responde sem erro).
3. Wallet fixada ao conectar e conferida antes de cada transferência.
4. Troca da chave Pix (`ChavePix::salvar` devolve true): `pix_alterada_em/_por`, 24 h sem saque, push `PixDeSaqueAlterado`.
5. Limites da central (mínimo **≥ R$ 2,00**, para nenhum saque se confundir com o teste de R$ 1,00; máximo/dia; saques/dia;
   taxa; valores de 0 a 9.999.999,99), um saque por vez, uuid do app como idempotência.
6. Bloqueio por motoboy (`saque_bloqueado`).
7. **Conclusão conferida** (`Saques::confere`, dentro do `aplicarStatus`): só conclui ou falha se `value` (quando vem) e
   `externalReference` (quando vem) batem com o saque; senão `Log::critical('[entregas] saque: transferência não confere
   com o saque')` e nada muda (o saque fica parado e aparece no painel).
8. Pagamento à mão (`FinanceiroController@registrarPagamento`) a motoboy com saque em andamento: 422 "Há um saque Pix em
   andamento para este motoboy; espere ele terminar.".

## Fluxo

- `Saques::pedir` (transação): saque `enviando` + `Pagamentos::registrar` (pix, sistema, "Saque Pix pelo app") + taxa
  (`ajuste` negativo "Taxa do saque Pix", ligado por `taxa_lancamento_id`, sem `pagamento_uuid`).
- `Saques::enviar` (depois do commit, na própria requisição): Cofre → `GET /wallets` → `POST /transfers`
  (`externalReference` = `saque:<uuid>`). Antes do POST, qualquer problema falha e devolve. No POST:
  - id → `Saques::gravarTransferencia` (o id do POST é o que vale; outro id já gravado: `Log::critical` "id da
    transferência diferente do gravado" e fica o do POST; id já usado por outro saque, `QueryException`: `Log::critical`
    e o saque fica `enviando` para a rotina, sem 500) → processando e aplica o status;
  - recusa clara (`RECUSAS_DO_ASAAS` = 400/401/403/404/422 **e** `errors` no corpo) → falha e devolve;
  - qualquer outra coisa (sem resposta, 5xx, 200 sem id, 409, 429, 4xx sem `errors`) → fica `enviando` (a rotina procura).
- `entregas:saques-conferir` (todo minuto, `AcompanhamentoDeSaques`), cada saque no seu try/catch (warning "acompanhamento
  do saque falhou" com o saque e a classe do erro; os outros seguem):
  - com id: `GET /transfers/{id}` e aplica o status. **404 há mais de 30 min** (`Saque::MINUTOS_ORFAO`): procura pelo
    `externalReference`; achou e confere, adota (troca o `transfer_id`, `Log::critical`) e aplica; não achou, falha
    "Transferência não encontrada no Asaas.". 404 recente ou outro erro: espera.
  - sem id há 5 min: procura pelo `externalReference` (até 500 recentes); achou e confere, adota (só se ainda em
    andamento); não achou, falha "Saque interrompido antes de chegar ao Asaas.". Erro de rede nunca falha.
  - adoção de saque que já terminou (falho e devolvido): `Log::critical` "transferência no Asaas para saque que não está
    em andamento" e nada muda: confira à mão (o dinheiro pode ter saído duas vezes).
- **Falha** (`Saques::falhar`): `Pagamentos::estornar` (motivo "Pagamento estornado", que o app reconhece) + estorno da
  taxa ("Taxa do saque devolvida") + push `SaqueNaoConcluido`. Falha que vem do Asaas (recusa no envio, FAILED/CANCELLED
  com `failReason`): o texto do Asaas vai para `motivo_asaas` e `motivo_falha` = "Não foi possível concluir o saque
  agora." (`Saque::MOTIVO_FALHA_DO_ASAAS`; é o que o push e o app mostram). Motivos nossos só em `motivo_falha`.
- **Conclusão** (`Saques::concluir`): comprovante só se `transactionReceiptUrl` casar `#^https://([a-z0-9-]+\.)*asaas\.com/#i`
  (`Saque::comprovante`; senão null) + push `SaqueConcluido`. Sem `aprovado_em`: `Log::critical` "concluído sem a
  aprovação da validação" (conclui mesmo assim: o dinheiro saiu).
- O pagamento de saque não se estorna à mão (`FinanceiroController@estornarPagamento`: 422).

## Logs críticos (`Log::critical`, todos com `[entregas] saque:`)

Qualquer um deles pede olhar à mão no Asaas: "id da transferência diferente do gravado", "id da transferência já usado
por outro saque", "transferência não confere com o saque", "concluído sem a aprovação da validação", "transferência do
saque trocada pela achada no Asaas", "transferência no Asaas para saque que não está em andamento".

## Interruptores

- `ENTREGAS_SAQUE` (`Saque::ligada`): desligada, nada de saque (a carteira não traz o bloco; a validação recusa tudo,
  menos o teste).
- Por central: `saque_ligado` (só com a validação confirmada). Token novo desliga e pede novo teste.

## Rotas

| Quem | Método e caminho | Controller |
|---|---|---|
| App (token do motoboy, `throttle:entregas-motoboy-saque`) | POST `v1/entregas/motoboy/saques` `{uuid, valor}` · GET `saques/{uuid}` | `SaquesController` |
| Asaas (`throttle:entregas-asaas-validacao`: 120/min por hash do token e 1000/min por IP) | POST `v1/entregas/asaas/validar-saque` | `ValidacaoAsaasController` |
| Console (`AdminDaCentral`: admin do sistema ou papel Administrator) | GET/PUT `int/v1/entregas/financeiro/saque-pix`, POST/DELETE `…/saque-pix/conexao` `{senha}` (throttle 10/min), POST `…/saque-pix/token` `{senha}` (throttle 10/min), POST `…/saque-pix/teste` `{tipo, chave, senha}` (throttle 10/min), GET `…/financeiro/saques`, PUT `…/financeiro/motoboys/{id}/saque` | `SaquePixController` |

A carteira do app (`GET v1/entregas/motoboy/carteira`) traz `saque` {disponivel, minimo, maximo_hoje, taxa,
em_andamento, indisponivel_motivo} só quando a central liberou. O extrato e o recibo marcam o pagamento de saque com
`saque` {situacao, comprovante_url}. O painel do Financeiro avisa `saque_falhou` (24 h; `motivo` = texto do Asaas quando
houver) e `saque_parado` (1 h). `Saques::publico($saque)` (app) traz `motivo` = `motivo_falha`;
`Saques::publico($saque, true)` (aba Saques do console) traz `motivo` = texto do Asaas quando houver e `motivo_asaas`.

## Testes

`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<teste>.php`: `saque-esquema`,
`saque-cofre`, `saque-asaas`, `saque-conexao`, `saque-avisos`, `saque-pix-alterada`, `saque-pedido`, `saque-envio`,
`saque-validacao` (inclui os PoCs 1 e 2 da revisão de segurança), `saque-acompanhamento` (inclui o PoC 3),
`saque-financeiro`, `saque-console`, `saque-controllers`, `saque-implantacao` (stubs em `stubs-saque.php`: Http falso
com a fila `asaas(status, corpo)`, Hash "hash:<senha>", CompanyUser, secret em arquivo temporário; o `Log` falso de
`stubs-ifood.php` tem `critical`).

## Armadilhas

- A validação de saque ligada na conta da central vale para **todo** saque por API daquela conta: a conta precisa ser
  só para isso.
- O dia dos limites é o do servidor (Brasília) = o da empresa; uma central em outro fuso contaria o dia pelo de Brasília.
- Sem `externalReference` no pedido da validação e com dois saques `enviando` do mesmo valor ao mesmo tempo, a validação
  recusa os dois (por segurança). Se o sandbox mostrar que o pedido não traz o `externalReference` e o Asaas chama antes
  da resposta do POST, isso vira recusa real em horário de pico: rever.
- Um saque que a conclusão não confere (valor ou `externalReference`) fica `processando` para sempre, com log crítico a
  cada minuto, até alguém resolver à mão. É de propósito.
- O MySQL conta só linhas que mudaram no `update`: `gravarTransferencia` relê o saque quando volta 0 antes de concluir que
  ele não está em andamento.

## A confirmar no sandbox (antes do primeiro saque real)

- **Payload da validação:** traz `transfer.externalReference`? Traz a chave Pix (`transfer.bankAccount.pixAddressKey` ou
  `transfer.pixAddressKey`) e em que formato de telefone (com ou sem 55)? O cadastro guarda DDD + número e é enviado
  assim no `pixAddressKey`.
- **Validação antes ou depois da resposta do POST:** o Asaas chama a validação dentro do `POST /transfers` (antes de
  responder) ou depois? Define qual caminho (por id ou sem id) é o normal.
- **Endpoint fora do ar:** se a nossa validação não responder (timeout, 5xx, 429 do limite), o Asaas recusa a
  transferência, aprova, ou tenta de novo? E repete a chamada para o mesmo `transfer.id`?
- **Ordem do `GET /transfers`:** mais recentes primeiro? O filtro `externalReference` funciona? (A rotina lê até 500.)
- **Desligar a validação sem percebermos:** se a central tirar a validação no Asaas, as transferências saem sem nos
  perguntar; o único sinal é o log crítico "concluído sem a aprovação da validação". Ver se há como consultar pela API se
  a validação está ativa.
- **`authorized: false`** na resposta do `POST /transfers` = o Asaas espera autorização por token na conta: o saque fica
  processando e aparece como "saque parado" no painel. Confirmar se a validação por webhook dispensa o token.
- **Devolução depois de DONE:** um Pix devolvido pelo banco de destino depois de `DONE` (status novo? evento?) não é
  tratado: o saque fica concluído e o saldo não volta. Ver como o Asaas sinaliza e se precisa de rotina.
- Os textos de `errors`/`failReason` e os status reais das recusas (para conferir `RECUSAS_DO_ASAAS`).

## Ao atualizar o Laravel, o core-api ou o fleetops-api, confira

- `Fleetbase\Models\CompanyUser::hasRole` e o papel "Administrator" (AdminDaCentral).
- `users.password` acessível no model do usuário (senha do conectar/desconectar/token/teste) e o `Hash::check`.
- `Driver::notify` e o `CanalFcmEntregas` (pushes).
- `Illuminate\Http\Client` (`Http::withHeaders()->withOptions(['allow_redirects' => false])->acceptJson()->timeout()`), usado pelo `AsaasCliente`.
- `RateLimiter::for` devolvendo uma lista de `Limit` (validação do Asaas) e o `TrustProxies` (o IP do limite).
