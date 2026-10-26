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
   criada por quem tem a chave de API não "toma" um saque). Em todo caminho, `transfer.operationType`, **se vier**, tem
   de ser `PIX` (senão "Transferência não é Pix."; se não vier, segue). Aprova:
   - **por id:** o saque em andamento da central com `transfer_id` = `transfer.id`, valor igual e, se o pedido trouxer, a
     mesma chave Pix (`transfer.bankAccount.pixAddressKey` ou `transfer.pixAddressKey`, não vazia; **mascarada**, com
     `*`, conta como não veio) e o mesmo `externalReference`;
   - **sem id** (o Asaas chamou antes da resposta do POST), só saques `enviando` sem id criados há até 2 min. O candidato
     é escolhido (a) pelo `transfer.externalReference` = `saque:<uuid>` dele ou (b) sem `externalReference`, por ser o
     **único** com o mesmo valor. **O `externalReference` só escolhe** (quem tem a chave de API pode copiá-lo): a
     aprovação exige sempre valor igual **e** a chave Pix no pedido, não mascarada e igual à do saque (o dinheiro iria
     para o próprio motoboy). Sem chave ("Transferência sem chave Pix para conferir."), chave mascarada ("Chave Pix
     mascarada; não dá para conferir."), dois candidatos do mesmo valor: recusa.
   - Chave comparada inteira, normalizada pelo tipo (`ValidacaoDeSaque::mesmaChave`, também na rotina): telefone, as duas
     como 55 + DDD + número (10 ou 11 dígitos ganham "55"); CPF/CNPJ, só os dígitos; e-mail, só aparado e em minúsculas
     (`joao.silva@` ≠ `joaosilva@`); aleatória, minúsculas sem espaços.
   - **Uma aprovação por saque:** grava `aprovado_em` e `aprovado_transfer_id` juntos, num `update … where id = ? and
     (aprovado_transfer_id is null or aprovado_transfer_id = <id>)` (o Asaas repetindo a pergunta da mesma transferência
     aprova de novo). Já aprovado para outra transferência: recusa "Saque já aprovado para outra transferência." e
     `Log::critical`.

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
7. **Conclusão conferida** (`Saques::confere`, dentro do `aplicarStatus`): só conclui ou falha se, cada um quando vem,
   `value`, `externalReference`, a chave Pix (não mascarada, com a mesma comparação da validação) e o `operationType`
   (`PIX`) batem com o saque; senão `Log::critical('[entregas] saque: transferência não confere com o saque')` e nada
   muda (o saque fica parado e aparece no painel).
8. Pagamento à mão (`FinanceiroController@registrarPagamento`) a motoboy com saque em andamento: 422 "Há um saque Pix em
   andamento para este motoboy; espere ele terminar." (`SAQUE_EM_ANDAMENTO`). A conferência e o `Pagamentos::registrar`
   rodam numa transação externa com a linha do motoboy travada (`Saques::linhaDoMotoboy(..., true)`), como no
   `Saques::pedir`: um pedido de saque ao mesmo tempo espera o pagamento, e vice-versa.

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
  - com id: `GET /transfers/{id}` e aplica o status; a resposta OK limpa `nao_encontrado_desde` (e `alerta_critico_em`).
    **Órfão (404):** o primeiro 404 grava `nao_encontrado_desde` (warning "transferência não encontrada no Asaas"). Com o
    saque criado há mais de 30 min (`Saque::MINUTOS_ORFAO`), procura pelo `externalReference`; achou e confere, adota
    (troca o `transfer_id`, `Log::critical`) e aplica. Não achou: **nunca falha sozinho** (o dinheiro pode ter saído):
    fica em andamento ("saque parado" no painel) e, com 404 seguidos há mais de 6 h (`Saque::HORAS_ORFAO`),
    `Log::critical` "transferência não encontrada no Asaas há horas; conferir à mão" **uma vez** (marca
    `alerta_critico_em`). Outro erro: espera (sem mexer na marca do 404).
  - sem id há 5 min: procura pelo `externalReference` (até 500 recentes); achou e confere, adota (só se ainda em
    andamento); não achou, falha "Saque interrompido antes de chegar ao Asaas.". Erro de rede nunca falha.
  - **mais de uma transferência com o `externalReference` do saque** (`transferenciasRecentes` guarda uma lista por
    referência): só adota a de id = `aprovado_transfer_id`; sem ela (ou com uma só que não é a aprovada, havendo
    aprovação), `Log::critical` e não adota nem falha (fica parado, aparece no painel).
  - adoção de saque que já terminou (falho e devolvido): `Log::critical` "transferência no Asaas para saque que não está
    em andamento" e nada muda: confira à mão (o dinheiro pode ter saído duas vezes).
- **Falha** (`Saques::falhar`): `Pagamentos::estornar` (motivo "Pagamento estornado", que o app reconhece) + estorno da
  taxa ("Taxa do saque devolvida") + push `SaqueNaoConcluido`. Falha que vem do Asaas (recusa no envio, FAILED/CANCELLED
  com `failReason`): o texto do Asaas vai para `motivo_asaas` e `motivo_falha` = "Não foi possível concluir o saque
  agora." (`Saque::MOTIVO_FALHA_DO_ASAAS`; é o que o push e o app mostram). Motivos nossos só em `motivo_falha`.
- **Conclusão** (`Saques::concluir`): comprovante só se `transactionReceiptUrl` casar `#^https://([a-z0-9-]+\.)*asaas\.com/#i`
  (`Saque::comprovante`; senão null) + push `SaqueConcluido`. Sem `aprovado_em`: `Log::critical` "concluído sem a
  aprovação da validação"; com `aprovado_transfer_id` diferente do `transfer_id`: `Log::critical` "concluído com a
  aprovação de outra transferência" (conclui mesmo assim: o dinheiro saiu).
- O pagamento de saque não se estorna à mão (`FinanceiroController@estornarPagamento`: 422).

## Logs críticos (`Log::critical`, todos com `[entregas] saque:`)

Qualquer um deles pede olhar à mão no Asaas: "id da transferência diferente do gravado", "id da transferência já usado
por outro saque", "transferência não confere com o saque", "concluído sem a aprovação da validação", "concluído com a
aprovação de outra transferência", "validação de outra transferência para saque já aprovado", "transferências no Asaas
com o externalReference do saque e nenhuma é a aprovada", "transferência do saque trocada pela achada no Asaas",
"transferência no Asaas para saque que não está em andamento", "transferência não encontrada no Asaas há horas;
conferir à mão".

## Resolver à mão um saque parado

Não há ação no console nem comando próprio (o ideal futuro é uma ação "Resolver saque" no console, só para o admin, com
senha e log). Hoje: o admin confere no painel do Asaas da central (Transferências, busca por `saque:<uuid>` ou pelo
`transfer_id`) e quem tem acesso à VPS resolve **um saque por vez**, com o uuid em mãos e cuidado:

- **A transferência existe no Asaas (com outro id):** grave o id certo e deixe a rotina conferir e aplicar o status
  (`Saques::confere` continua valendo):
  `UPDATE entregas_saques SET transfer_id = '<id do Asaas>', nao_encontrado_desde = NULL, alerta_critico_em = NULL,
  updated_at = NOW() WHERE uuid = '<uuid>' AND situacao IN ('enviando', 'processando');` (1 linha).
- **Nada saiu no Asaas:** falhe e devolva pelo código, nunca só por SQL (o estorno do pagamento e da taxa vai junto, na
  mesma transação, e o motoboy recebe o push): no container da API, `php artisan tinker` e
  `App\Support\Entregas\Saques\Saques::falhar(DB::table('entregas_saques')->where('uuid', '<uuid>')->first(),
  'Transferência não encontrada no Asaas.');` (devolve `true` se falhou agora; `false` se já não estava em andamento).
- **Saiu e está DONE, mas não confere** (valor, chave, `operationType`): é caso de investigação (chave de API vazada?),
  não de SQL. Desligue o saque da central e troque a chave do Asaas antes de mexer no saque.

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

## Console (plano 2)

- Telas: ver "Saque Pix pelo app" em `packages/fleetops/CLAUDE.md`.
- `SaqueNoConsole` (só com `ENTREGAS_SAQUE` ligada e a conta conectada; sem a tabela, nada): `doMotoboy` → bloco `saque`
  `{bloqueado, liberado_em, alterada_por}` do extrato do console (`FinanceiroController@extratoDoMotoboy`; `alterada_por`
  é o nome de quem trocou a chave, só durante a carência); `bloqueados` → `saque_bloqueado` da lista
  `GET financeiro/motoboys`.
- `ConexaoAsaas::publico` traz `ip_de_saida` (`services.entregas.ip_de_saida` = env `ENTREGAS_IP_DE_SAIDA`, no
  `stack.env`): o IP fixo da VPS que o card mostra para a lista de IPs do Asaas. Vazio = o card pede o IP a quem
  administra.
- O card manda a senha do admin em conectar, desconectar, gerar o token e testar (passos 1 e 2; cada campo é limpo
  depois do pedido) e mostra o "Senha incorreta." e o 429 como vêm. Na tela Saques Pix, `motivo_asaas` preenchido liga
  o selo "Asaas" junto do motivo.
- Teste: `saque-console.php`.

## Testes

`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<teste>.php`: `saque-esquema`,
`saque-cofre`, `saque-asaas`, `saque-conexao`, `saque-avisos`, `saque-pix-alterada`, `saque-pedido`, `saque-envio`,
`saque-validacao` (inclui os PoCs 1 e 2 da revisão de segurança e os A, D e E da segunda revisão),
`saque-acompanhamento` (inclui o PoC 3 e os B, C e F da segunda revisão),
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
- Um saque que a conclusão não confere (valor, `externalReference`, chave ou `operationType`), ou com mais de uma
  transferência com o `externalReference` e nenhuma aprovada, fica em andamento para sempre, com log crítico a cada
  minuto, até alguém resolver à mão ("Resolver à mão um saque parado"). O órfão (404) também fica, mas com um log
  crítico só. É de propósito: nunca devolver o valor de um saque cujo dinheiro pode ter saído.
- Sem o `transfer_id` e sem a chave Pix inteira no pedido da validação, a validação recusa (mesmo com o
  `externalReference` certo). Se o sandbox mostrar que o Asaas chama antes da resposta do POST **e** manda a chave
  mascarada ou não manda, todo saque nesse caminho é recusado: rever.
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
- **Ordem do `GET /transfers`:** mais recentes primeiro? O filtro `externalReference` funciona? (A rotina lê até 500 e
  guarda todas as de cada `externalReference`; a ordem não decide mais qual vale, mas um saque antigo pode cair fora da
  janela.)
- **Chave mascarada no payload:** a validação e o `GET /transfers/{id}` trazem a chave Pix inteira ou com `*` (ex.
  `(11) *****-4321`)? Mascarada, o caminho por id ignora e o caminho sem id recusa.
- **`operationType` no payload:** a validação e o `GET /transfers/{id}` trazem `operationType` (`PIX`)? Sem ele, a
  conferência segue sem esse campo.
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
