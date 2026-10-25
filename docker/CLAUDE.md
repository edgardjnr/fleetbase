# Imagens Docker (ARM64) e socket

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

## Decisões de produção (não desfazer)

- **Versão do PHP da API (2026-10-22):** a imagem base é `dunglas/frankenphp:<versão>-php8.2-bookworm` com a versão **fixa** na tag, e ela congela o PHP. Rebuild não traz correção. Em 22/10 a 1.12.4 (PHP 8.2.32) virou 1.13.2 (PHP 8.2.35) por causa do PHP2Shell (CVE-2026-109618/109619, execução remota sem login; FrankenPHP está entre os afetados). Para conferir o PHP de uma tag sem Docker: o `PHP_VERSION` no config da imagem arm64, pela API do registro (`registry-1.docker.io/v2/dunglas/frankenphp/manifests/<tag>`). O `session.upload_progress.enabled = 0` vai no `conf.d/zz-entregas-seguranca.ini` (a API não usa; reduz a superfície de uma das falhas).

- **ARM64.**
  - A imagem oficial `socketcluster/socketcluster` só existe para amd64. Use `docker/socket/` (SocketCluster 17.4.0, MIT, multi-arch).
  - O `go-crond` do `docker/Dockerfile` é baixado conforme `dpkg --print-architecture`.
  - Toda imagem nova precisa ter versão arm64.
- **Estágios do `docker/Dockerfile`:** só os `-dev` e o `app-release`, que é o que o `atualizar.sh` builda e o stack usa em application, queue, queue-ifood e scheduler. Os estágios com ssm-parent (`app`, `scheduler`, `events`), o xdebug e o memcached saíram em 2026-10-25.
- **Origem no socket.**
  - O `origins` nativo do SocketCluster recusa conexões sem `Origin` e com isso bloqueava a própria API.
  - O `docker/socket/server.js` usa um middleware próprio: navegador só entra a partir de `SOCKET_ALLOWED_ORIGINS`; conexão sem `Origin` (servidor) passa.
  - O app do motoboy (React Native) manda `Origin: https://entregas-socket...`, o próprio host do socket. Por isso o `SOCKET_ALLOWED_ORIGINS` do stack inclui o `SOCKET_DOMAIN` além do console.
