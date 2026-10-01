#!/usr/bin/env bash
# Builda as imagens na VPS e atualiza o stack do Swarm.
#
# Uso (na VPS, dentro do clone do repo):
#   bash deploy/atualizar.sh                  # pull + build de tudo + update + deploy.sh
#   bash deploy/atualizar.sh api              # só a API (application/queue/scheduler)
#   bash deploy/atualizar.sh console socket   # só os componentes indicados
#   SEM_PULL=1 bash deploy/atualizar.sh       # não faz git pull
#
# Na primeira vez, rode antes de criar o stack no Portainer (só builda), e depois
# rode de novo para executar o deploy.sh (migrations + seed).
set -euo pipefail

cd "$(dirname "$0")/.."
STACK="${STACK:-entregas}"
ALVOS=("$@")
[ ${#ALVOS[@]} -eq 0 ] && ALVOS=(api console socket)

tem() { local x; for x in "${ALVOS[@]}"; do [ "$x" = "$1" ] && return 0; done; return 1; }

if [ -z "${SEM_PULL:-}" ]; then
  echo "==> git pull"
  git pull --ff-only
fi

if tem api; then
  echo "==> build entregas-api"
  docker build -t entregas-api:latest -f docker/Dockerfile --target app-release .
fi
if tem console; then
  echo "==> build entregas-console"
  docker build -t entregas-console:latest --build-arg ENVIRONMENT=production -f console/Dockerfile .
fi
if tem socket; then
  echo "==> build entregas-socket"
  docker build -t entregas-socket:latest docker/socket
fi

if ! docker service inspect "${STACK}_application" >/dev/null 2>&1; then
  echo
  echo "Stack '${STACK}' ainda não existe. Crie no Portainer com deploy/docker-stack.yml"
  echo "e deploy/stack.env, depois rode: SEM_PULL=1 bash deploy/atualizar.sh"
  exit 0
fi

atualiza() { echo "==> service update ${STACK}_$1"; docker service update --force --quiet "${STACK}_$1" >/dev/null; }
tem socket  && atualiza socket
tem console && atualiza console
if tem api; then
  atualiza application
  atualiza queue
  atualiza scheduler

  echo "==> aguardando ${STACK}_application"
  CID=""
  for _ in $(seq 1 60); do
    CID="$(docker ps -q -f "name=${STACK}_application" -f status=running | head -n1)"
    [ -n "$CID" ] && break
    sleep 3
  done
  if [ -z "$CID" ]; then
    echo "application não subiu; veja: docker service logs ${STACK}_application" >&2
    exit 1
  fi

  echo "==> aguardando MySQL ficar healthy"
  for _ in $(seq 1 60); do
    [ -n "$(docker ps -q -f "name=${STACK}_database" -f health=healthy)" ] && break
    sleep 3
  done

  echo "==> deploy.sh (migrations, seed, permissões, cache)"
  docker exec "$CID" ./deploy.sh
fi

echo "==> pronto"
docker service ls --filter "name=${STACK}_"
