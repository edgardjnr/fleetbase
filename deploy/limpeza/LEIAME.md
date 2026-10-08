# Limpeza dos testes (antes da homologação do iFood)

Decisão do Edgard (2026-10-08): o sistema ainda não teve operação real, então saem **todos os pedidos**. Saem também as
conversas de teste, as lojas do teste de isolamento e os endereços sem uso. O que sai e o que fica está no cabeçalho do
`2-apagar.sql`.

**Fica:** o Terraço Pizza Bar (`vendor_8jifurzqqg`) com o Local e o vínculo iFood, as outras lojas, os motoboys, os
usuários da central e as faixas de km.

**Atenção:** é um DELETE de verdade. O console não mostra lixeira, e o relatório de pagamento, os ganhos dos motoboys e o
extrato das lojas zeram. A única volta é o backup do passo 4.

Os comandos rodam na VPS, em `~/entregas`, com o código deste ramo (`git pull` depois do merge na `main`).

**1. iFood**

No Gestor de Pedidos da loja de teste, conclua ou cancele os pedidos de teste que ainda estiverem abertos. A limpeza
apaga os eventos já recebidos. Um evento novo de um pedido antigo cai em "evento sem pedido; não cria", e isso não faz
mal.

**2. Inventário (só leitura)**

```bash
cd ~/entregas
DB=$(docker ps -q -f name=entregas_database)
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --force fleetbase' < deploy/limpeza/1-inventario.sql | tee ~/limpeza-inventario.txt
```

Confira:

- (2): nenhum pedido precisa ficar (todos saem);
- (6) e (6b): só as lojas e os logins do teste de isolamento;
- (6c): **0 nas três colunas**. Deu mais que 0? Pare;
- (7): só conversas de teste;
- (8): o Local de cada loja está na lista dos que ficam;
- (9): nenhum local que precise ficar. Se algum precisar, anote o `public_id` e pare;
- (10): o Terraço Pizza Bar com `vinculada`.

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
docker exec "$DB" sh -c "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --routines --triggers --set-gtid-purged=OFF --databases fleetbase" > ~/backup-limpeza-$(date +%F-%H%M).sql
ls -lh ~/backup-limpeza-*.sql && tail -c 100 "$(ls -t ~/backup-limpeza-*.sql | head -n1)"   # tem de terminar com "-- Dump completed"
```

**5. Revisão (apaga e desfaz)**

```bash
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t fleetbase' < deploy/limpeza/2-apagar.sql | tee ~/limpeza-revisao.txt
```

O resumo lista cada tabela com as linhas que sairiam e termina com "REVISÃO: nada foi apagado". Confira se os números
batem com o inventário: `orders` igual ao total de (1), `vendors` igual a (6), `chat_channels` igual a (7) e `places`
igual a (9), ou um pouco mais (o local de algum contato das lojas do isolamento, que sai junto). "pulado" é tabela ou coluna que não existe nesta versão.

Se der `ERROR`, nada foi apagado (o erro desfaz a transação): mande a saída e volte os serviços (passo 7).

**6. Execução**

```bash
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --init-command="SET @executar = 1" fleetbase' < deploy/limpeza/2-apagar.sql | tee ~/limpeza-execucao.txt
```

Termina com "CONFIRMADO (COMMIT)" e a conferência: `pedidos`, `cargas`, `paradas`, `pedidos_ifood` e `distribuicoes` em
0, os motoboys iguais aos de antes e `lojas_ifood_vinculadas` em 1.

**7. Voltar os serviços**

```bash
docker service scale entregas_application=1 entregas_queue=1 entregas_queue-ifood=1 entregas_scheduler=1
APP=$(docker ps -q -f name=entregas_application)   # espere o container subir
docker exec "$APP" php artisan cache:clear                  # tira os valores e rotas em cache dos pedidos apagados
docker exec $(docker ps -q -f name=entregas_scheduler) php artisan schedule:clear-cache   # travas do agendador paradas no meio
```

**8. Conferir**

- Console (Ctrl+Shift+R): Pedidos vazio, mapa sem alfinetes, Pagamento e cobrança zerado, Lojas sem as do teste de
  isolamento, Terraço Pizza Bar com "Vinculada".
- App do motoboy: puxe a lista para baixo; Pedidos e Meus ganhos vazios.
- `docker service logs --since 5m entregas_scheduler 2>&1 | grep '\[entregas\] ifood'`: o polling segue sem erro.

**Desfazer** (volta o banco inteiro ao backup; o que entrou depois se perde)

```bash
docker service scale entregas_application=0 entregas_queue=0 entregas_queue-ifood=0 entregas_scheduler=0
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' < ~/backup-limpeza-<data>.sql
docker service scale entregas_application=1 entregas_queue=1 entregas_queue-ifood=1 entregas_scheduler=1
```

**Fora do banco:** os arquivos dos pedidos apagados (fotos e assinaturas dos comprovantes, anexos do chat) continuam no
disco ou no bucket, sem registro apontando para eles.
