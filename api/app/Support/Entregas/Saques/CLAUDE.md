# Saque Pix do motoboy (Asaas da central)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

O motoboy saca o saldo pelo app e o dinheiro cai na chave Pix cadastrada pela central, saindo da conta Asaas **da
central**. Spec: `docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md`; plano do servidor:
`docs/superpowers/plans/2026-10-26-saque-pix-asaas-api.md`. Modelo: o pagamento de freelancers do RestaurantePro
(`restaurantepro-main`, edge `freelancer-payout`).

## Segredos

- **Chave de API do Asaas:** `entregas_asaas_conexoes.chave_cifrada`, AES-256-GCM (`Cofre`). A chave que cifra é o
  Docker secret `entregas_saque_chave` (`/run/secrets/…`, `services.entregas.saque_chave_arquivo`), só na API e no
  agendador. Sem o secret: conectar 503, envio falha e devolve, a rotina não consulta. Secret trocado depois de conectar
  (a chave gravada não decifra): testar responde 422 pedindo para conectar a chave de novo.
- **Token da validação:** só o sha256 (`token_hash`), mostrado uma vez no `POST financeiro/saque-pix/token`.
- Nunca em log ou resposta: a chave, o token e a chave Pix inteira (`Saque::chaveEscondida`). A chave tem
  `#[\SensitiveParameter]` (`AsaasCliente::__construct`, `Cofre::cifrar`, `ConexaoAsaas::conectar`): some do stack trace.
- **Só https:** o `AsaasCliente` não chama base sem `https://` (`ASAAS_API_URL` errada: status 0 e warning
  "ASAAS_API_URL precisa ser https") e não segue redirecionamento (`allow_redirects` false: o `access_token` iria junto).

## Camadas

1. Chave Pix e valor sempre do banco; saldo recalculado com a linha do motoboy travada (`lockForUpdate`). O pedido relê a
   conexão travada na mesma transação (central desligada ou desconectada no meio: recusa); desconectar trava a mesma linha
   antes de conferir saque em andamento. Dois "conectar" ao mesmo tempo: o segundo vira troca (a wallet tem de bater).
2. **Validação de saque do Asaas obrigatória** (`ValidacaoDeSaque`, `POST v1/entregas/asaas/validar-saque`): só o saque
   nosso em andamento com o mesmo `transfer.id`, valor e chave recebe APPROVED; o teste de R$ 1,00 é recusado e confirma
   (`validacao_confirmada_em`). Sem ela, o saque não liga. **Teste:** um por vez (o anterior sem resposta da validação
   segura por 2 min: "Espere o resultado do teste anterior."), no máximo 5 por dia de Brasília (`testes_no_dia`,
   `testes_dia`: "Limite de testes de hoje atingido."), com quem pediu (`teste_pedido_por`) e a chave Pix escondida no
   log. Teste que o Asaas recusa ou não responde volta a validação e o teste ao que eram: não desliga o saque de uma
   central já confirmada (se a validação confirmou durante a chamada, responde sem erro).
3. Wallet fixada ao conectar e conferida antes de cada transferência.
4. Troca da chave Pix (`ChavePix::salvar` devolve true): `pix_alterada_em/_por`, 24 h sem saque, push `PixDeSaqueAlterado`.
5. Limites da central (mínimo **≥ R$ 2,00**, para nenhum saque se confundir com o teste de R$ 1,00; máximo/dia; saques/dia;
   taxa; valores de 0 a 9.999.999,99), um saque por vez, uuid do app como idempotência.
6. Bloqueio por motoboy (`saque_bloqueado`).

## Fluxo

- `Saques::pedir` (transação): saque `enviando` + `Pagamentos::registrar` (pix, sistema, "Saque Pix pelo app") + taxa
  (`ajuste` negativo "Taxa do saque Pix", ligado por `taxa_lancamento_id`, sem `pagamento_uuid`).
- `Saques::enviar` (depois do commit, na própria requisição): Cofre → `GET /wallets` → `POST /transfers`
  (`externalReference` = `saque:<uuid>`). Antes do POST, qualquer problema falha e devolve. No POST: id → processando;
  4xx → falha e devolve; sem resposta/5xx/200 sem id → fica `enviando` (a rotina procura).
- `entregas:saques-conferir` (todo minuto, `AcompanhamentoDeSaques`): `GET /transfers/{id}`; sem id há 5 min, procura
  pelo `externalReference` (até 500 recentes): achou adota (só se ainda em andamento), não achou falha. Erro de rede nunca falha.
- **Falha** (`Saques::falhar`): `Pagamentos::estornar` (motivo "Pagamento estornado", que o app reconhece) + estorno da
  taxa ("Taxa do saque devolvida") + push `SaqueNaoConcluido`. **Conclusão**: comprovante do Asaas + push `SaqueConcluido`.
- O pagamento de saque não se estorna à mão (`FinanceiroController@estornarPagamento`: 422).

## Interruptores

- `ENTREGAS_SAQUE` (`Saque::ligada`): desligada, nada de saque (a carteira não traz o bloco; a validação recusa tudo,
  menos o teste).
- Por central: `saque_ligado` (só com a validação confirmada). Token novo desliga e pede novo teste.

## Rotas

| Quem | Método e caminho | Controller |
|---|---|---|
| App (token do motoboy, `throttle:entregas-motoboy-saque`) | POST `v1/entregas/motoboy/saques` `{uuid, valor}` · GET `saques/{uuid}` | `SaquesController` |
| Asaas (`throttle:entregas-asaas-validacao`) | POST `v1/entregas/asaas/validar-saque` | `ValidacaoAsaasController` |
| Console (`AdminDaCentral`: admin do sistema ou papel Administrator) | GET/PUT `int/v1/entregas/financeiro/saque-pix`, POST/DELETE `…/saque-pix/conexao` (senha), POST `…/saque-pix/token`, POST `…/saque-pix/teste`, GET `…/financeiro/saques`, PUT `…/financeiro/motoboys/{id}/saque` | `SaquePixController` |

A carteira do app (`GET v1/entregas/motoboy/carteira`) traz `saque` {disponivel, minimo, maximo_hoje, taxa,
em_andamento, indisponivel_motivo} só quando a central liberou. O extrato e o recibo marcam o pagamento de saque com
`saque` {situacao, comprovante_url}. O painel do Financeiro avisa `saque_falhou` (24 h) e `saque_parado` (1 h).

## Testes

`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<teste>.php`: `saque-esquema`,
`saque-cofre`, `saque-asaas`, `saque-conexao`, `saque-avisos`, `saque-pix-alterada`, `saque-pedido`, `saque-envio`,
`saque-validacao`, `saque-acompanhamento`, `saque-financeiro`, `saque-controllers`, `saque-implantacao` (stubs em
`stubs-saque.php`: Http falso com a fila `asaas(status, corpo)`, Hash "hash:<senha>", CompanyUser, secret em arquivo
temporário).

## Armadilhas

- O formato da chave Pix de telefone no Asaas (com ou sem 55) não está documentado: o cadastro guarda DDD + número e é
  enviado assim; a validação aceita o `+55` na comparação. Confirmar no ensaio do sandbox.
- `authorized: false` na resposta do `POST /transfers` = o Asaas espera autorização por token na conta: o saque fica
  processando e aparece como "saque parado" no painel. Confirmar no ensaio se a validação por webhook dispensa o token.
- A validação de saque ligada na conta da central vale para **todo** saque por API daquela conta: a conta precisa ser
  só para isso.
- O dia dos limites é o do servidor (Brasília) = o da empresa; uma central em outro fuso contaria o dia pelo de Brasília.

## Ao atualizar o Laravel, o core-api ou o fleetops-api, confira

- `Fleetbase\Models\CompanyUser::hasRole` e o papel "Administrator" (AdminDaCentral).
- `users.password` acessível no model do usuário (senha do conectar/desconectar) e o `Hash::check`.
- `Driver::notify` e o `CanalFcmEntregas` (pushes).
- `Illuminate\Http\Client` (`Http::withHeaders()->withOptions(['allow_redirects' => false])->acceptJson()->timeout()`), usado pelo `AsaasCliente`.
