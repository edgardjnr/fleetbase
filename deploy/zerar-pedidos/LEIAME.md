# Zerar os pedidos de teste (2026-10-19)

Decisão do Edgard (2026-10-19): todos os pedidos da plataforma eram de teste e saem. Junto saem o **Financeiro inteiro**
(lançamentos, pagamentos e recebimentos com comprovante, faturas), os **endereços dos clientes**, os **contatos de
clientes** dos pedidos e as **posições de GPS gravadas durante os pedidos**. O que sai e o que fica, tabela por tabela,
está no cabeçalho do `2-apagar.sql`. A base é a limpeza de 2026-10-08 (`deploy/limpeza/`), sem a parte de lojas e
conversas.

**Fica:** lojas e logins do portal, motoboys, conversas, vínculos iFood e da integração RestaurantePro, faixas de km,
usuários da central, ciclo de cobrança das lojas, chave Pix dos motoboys e as configurações do Financeiro (data de
corte, ciclo padrão). O Local de cada loja (coleta) fica.

**Atenção:** é um DELETE de verdade. O console não tem lixeira; Pedidos, mapa, relatório de pagamento, ganhos, extratos,
faturas e o painel do Financeiro zeram. A única volta é o backup do passo 4.

Os comandos rodam na VPS, em `~/entregas`, depois de um `git pull`.

**1. Pedidos abertos lá fora**

- iFood: no Gestor de Pedidos da loja de teste, conclua ou cancele os pedidos de teste que ainda estiverem abertos. A
  limpeza apaga os eventos já recebidos; um evento novo de um pedido antigo cai em "evento sem pedido; não cria".
- RestaurantePro: se algum pedido de teste ainda estiver aberto no painel, cancele lá antes. Depois da limpeza, o aviso
  de um pedido antigo não acha mais o pedido daqui.

**2. Inventário (só leitura)**

```bash
cd ~/entregas && git pull
DB=$(docker ps -q -f name=entregas_database)
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --force fleetbase' < deploy/zerar-pedidos/1-inventario.sql | tee ~/zerar-inventario.txt
```

Confira:

- (2): nenhum pedido precisa ficar (todos saem);
- (4): o Financeiro que sai (tudo);
- (7): os contatos de clientes que saem. Algum precisa ficar? Pare;
- (8): o Local de cada loja está na lista dos que ficam;
- (9): nenhum local que precise ficar. Se algum precisar, anote o `public_id` e pare;
- (10): anote os números (motoboys, fornecedores, vínculos): eles não podem mudar.

Algum `ERROR` no meio? Mande a saída antes de seguir. Tabela que não existe nesta versão dá erro só no inventário; o
`2-apagar.sql` pula essas tabelas.

**3. Parar quem grava no banco** (alguns minutos fora do ar; escolha um horário sem motoboy na rua)

```bash
docker service scale entregas_application=0 entregas_queue=0 entregas_queue-ifood=0 entregas_scheduler=0
docker ps -f name=entregas_application -f name=entregas_queue -f name=entregas_scheduler   # repita até vir vazio
```

**4. Backup**

```bash
DB=$(docker ps -q -f name=entregas_database)
docker exec "$DB" sh -c "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --routines --triggers --set-gtid-purged=OFF --databases fleetbase" > ~/backup-zerar-$(date +%F-%H%M).sql
ls -lh ~/backup-zerar-*.sql && tail -c 100 "$(ls -t ~/backup-zerar-*.sql | head -n1)"   # tem de terminar com "-- Dump completed"
```

**5. Revisão (apaga e desfaz)**

```bash
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t fleetbase' < deploy/zerar-pedidos/2-apagar.sql | tee ~/zerar-revisao.txt
```

O resumo lista cada tabela com as linhas que sairiam e termina com "REVISÃO: nada foi apagado". Confira com o
inventário: `orders` igual ao total de (1), `entregas_lancamentos`/`entregas_pagamentos`/`entregas_faturas` iguais a
(4), `contacts` igual a (7) e `places` igual a (9). `vendors`, `drivers` e `chat_*` não aparecem (não são tocados; o
`drivers` só tem o `current_job_uuid` limpo). "pulado" é tabela ou coluna que não existe nesta versão.

Se der `ERROR`, nada foi apagado (o erro desfaz a transação): mande a saída e volte os serviços (passo 8).

**6. Execução**

```bash
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --init-command="SET @executar = 1" fleetbase' < deploy/zerar-pedidos/2-apagar.sql | tee ~/zerar-execucao.txt
```

Termina com "CONFIRMADO (COMMIT)" e a conferência: `pedidos`, `cargas`, `paradas`, `pedidos_ifood`,
`pedidos_integracao`, `distribuicoes`, `lancamentos`, `pagamentos` e `faturas` em 0; `motoboys`, `fornecedores`,
`lojas_ifood_vinculadas` e `lojas_integracao` iguais ao (10) do inventário.

**7. Comprovantes do Financeiro** (os arquivos no volume `api_storage`; só depois do COMMIT)

```bash
docker service scale entregas_application=1
APP=$(docker ps -q -f name=entregas_application)   # espere o container subir
docker exec "$APP" sh -c 'ls storage/app/entregas/comprovantes 2>/dev/null; rm -rf storage/app/entregas/comprovantes'
```

**8. Voltar os serviços**

```bash
docker service scale entregas_application=1 entregas_queue=1 entregas_queue-ifood=1 entregas_scheduler=1
APP=$(docker ps -q -f name=entregas_application)
docker exec "$APP" php artisan cache:clear                  # tira valores, rotas e ofertas em cache dos pedidos apagados
docker exec $(docker ps -q -f name=entregas_scheduler) php artisan schedule:clear-cache   # travas do agendador paradas no meio
```

**9. Conferir**

- Console (Ctrl+Shift+R): Pedidos vazio, mapa sem alfinetes, Pagamento e cobrança zerado, Financeiro com painel, extratos
  e faturas zerados (Configurações com a data de corte de antes), lojas e motoboys iguais.
- Portal da loja de teste: Pedidos e Financeiro vazios.
- App do motoboy: puxe a lista para baixo; Pedidos e Início (extrato) vazios.
- `docker service logs --since 5m entregas_scheduler 2>&1 | grep '\[entregas\]'`: polling do iFood e conferência do
  Financeiro sem erro.

**Desfazer** (volta o banco inteiro ao backup; o que entrou depois se perde; os comprovantes do passo 7 não voltam)

```bash
docker service scale entregas_application=0 entregas_queue=0 entregas_queue-ifood=0 entregas_scheduler=0
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' < ~/backup-zerar-<data>.sql
docker service scale entregas_application=1 entregas_queue=1 entregas_queue-ifood=1 entregas_scheduler=1
```

**Fora do banco:** as fotos e assinaturas dos comprovantes de entrega continuam no disco ou no bucket, sem registro
apontando para elas.
