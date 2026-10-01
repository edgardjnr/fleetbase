# SocketCluster v17.4.0 rebuilt from the official app template (npm `socketcluster@17.4.0`, MIT).
#
# The upstream image socketcluster/socketcluster:v17.4.0 is published for linux/amd64 only,
# so it cannot run natively on ARM64 hosts. This image runs the same server.js on a
# multi-arch Node base and starts it with plain `node` instead of nodemon.
FROM node:18-bookworm-slim

LABEL description="SocketCluster 17.4.0 (multi-arch) for Fleetbase"

WORKDIR /usr/src/app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force
COPY . .

ENV ENV=prod
EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
  CMD node -e "require('http').get('http://127.0.0.1:8000/health-check',r=>process.exit(r.statusCode===200?0:1)).on('error',()=>process.exit(1))"

CMD ["node", "server.js"]
