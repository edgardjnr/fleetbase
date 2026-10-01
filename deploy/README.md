# Deploy de produção — Entregas (Fleetbase)

VPS Oracle **ARM64** com Docker Swarm + Portainer. A entrada é pelo **Cloudflare Tunnel**, sem portas abertas.
As imagens são buildadas na própria VPS a partir deste repositório.

| Domínio | Serviço (stack `entregas`) |
|---|---|
| `entregas.restaurantepro.com.br` | `http://entregas_console:4200` |
| `entregas-api.restaurantepro.com.br` | `http://entregas_application:8000` |
| `entregas-socket.restaurantepro.com.br` | `http://entregas_socket:8000` |

## O que tem aqui

- `docker-stack.yml`: o stack (redis, mysql, socket, application, queue, scheduler, console).
- `stack.env.example`: as variáveis. O `stack.env`, com os segredos, **não vai para o git**.
- `atualizar.sh`: faz o pull, builda as imagens, atualiza os serviços e roda o `deploy.sh` (migrations e seed).

## Primeira instalação

1. **Na VPS**, clone o repositório (não precisa dos submódulos, porque o build usa os pacotes publicados no npm/Composer):
   ```bash
   git clone https://github.com/edgardjnr/fleetbase.git ~/entregas && cd ~/entregas
   bash deploy/atualizar.sh        # só builda; o stack ainda não existe
   ```
   O primeiro build demora bastante, sobretudo o do console (Ember).

2. **No Portainer**, vá em Stacks → Add stack, com o nome **`entregas`**:
   - em Web editor, cole o conteúdo de `deploy/docker-stack.yml`;
   - em Environment variables, use "Load variables from .env file" e carregue o `deploy/stack.env`
     (copie o arquivo do seu PC para lá; ele foi gerado localmente com APP_KEY e senha do MySQL);
   - clique em Deploy. Se aparecer aviso de que não achou a imagem no registry, pode ignorar: as imagens são locais.

3. **Na Cloudflare** (Zero Trust → Networks → Tunnels → `restaurantepro-oracle` → Public hostnames), adicione os 3 hostnames da tabela acima, com tipo HTTP.

4. **Na VPS**, rode o deploy inicial do banco:
   ```bash
   SEM_PULL=1 bash deploy/atualizar.sh api
   ```

5. Abra `https://entregas.restaurantepro.com.br` e conclua o onboarding, criando a organização e o administrador.

## Atualizar depois de um push

```bash
cd ~/entregas && bash deploy/atualizar.sh            # tudo
bash deploy/atualizar.sh api                          # só a API
bash deploy/atualizar.sh console                      # só o console
```

No Portainer, se usar "Update the stack", deixe **Re-pull image desligado**.

## Observações

- **Socket:** a imagem oficial `socketcluster/socketcluster` é só amd64. O `docker/socket/` reconstrói o
  mesmo app (v17.4.0) para rodar em ARM64. Navegadores só conectam a partir de `https://CONSOLE_DOMAIN`;
  conexões sem `Origin` (a própria API, de servidor para servidor) são aceitas.
- **HTTPS atrás do tunnel:** com `TRUSTED_PROXIES=*`, a API respeita o `X-Forwarded-Proto` e gera URLs `https://`.
  Isso é seguro porque a API não tem porta publicada; só o cloudflared a alcança.
- **E-mail:** o padrão é `MAIL_MAILER=log`. Sem SMTP, recuperação de senha e convites não chegam.
  Configure `MAIL_*` no `stack.env` e atualize o stack.
- **Arquivos:** os uploads ficam no volume `entregas_api_storage` e o MySQL no `entregas_mysql_data`.
  Inclua os dois no backup.
- **Limite de upload:** a Cloudflare (plano free) corta requisições acima de 100 MB.
