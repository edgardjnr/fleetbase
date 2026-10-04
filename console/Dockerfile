# Build context: the REPOSITORY ROOT (not ./console), e.g.
#   docker build -f console/Dockerfile .
# The console is linked (pnpm workspace) to the local Fleetbase packages in ../packages/*
# (engines, ember-ui, ember-core, fleetops-data), so customizations there - like the pt-BR
# translations - are part of the build. console/Dockerfile.dockerignore limits the context.

# ---- Build Stage ----
FROM node:22.22.0-alpine3.22 AS builder

WORKDIR /app/console

# Create the pnpm directory and set the PNPM_HOME environment variable
RUN mkdir -p ~/.pnpm
ENV PNPM_HOME=/root/.pnpm

# Set environment
ARG ENVIRONMENT=production

# Attribution
ARG DISABLE_FLEETBASE_ATTRIBUTION=true
ENV DISABLE_FLEETBASE_ATTRIBUTION=$DISABLE_FLEETBASE_ATTRIBUTION

# Production builds disable /fleetbase.config.json by default (config/environment.js), so the
# API/socket hosts would have to be baked in. Keep it on: the hosts come from env vars at start
# (nginx-entrypoint/40-fleetbase-runtime-config.sh).
ARG DISABLE_RUNTIME_CONFIG=false
ENV DISABLE_RUNTIME_CONFIG=$DISABLE_RUNTIME_CONFIG

# Add the pnpm global bin to the PATH
ENV PATH=/root/.pnpm/bin:$PATH

# Install global dependencies
RUN npm install -g ember-cli pnpm

# Install git
RUN apk update && apk add git openssh-client

# Trust GitHub's RSA host key
RUN mkdir -p -m 0600 ~/.ssh && ssh-keyscan github.com >> ~/.ssh/known_hosts

# Local packages the console workspace links to (see console/pnpm-workspace.yaml)
COPY packages/ /app/packages/

# Console sources
COPY console/ /app/console/

# Install app + workspace package dependencies
RUN pnpm install --frozen-lockfile

# Build the application
RUN pnpm build --environment $ENVIRONMENT

# ---- Serve Stage ----
FROM nginx:alpine

# Copy the built app to our served directory
COPY --from=builder /app/console/dist /usr/share/nginx/html

# Expose the port nginx is bound to
EXPOSE 4200

# Use custom nginx.conf
COPY console/nginx.conf /etc/nginx/conf.d/default.conf

# Generate fleetbase.config.json from env vars (API_HOST, SOCKETCLUSTER_*, ...) on start
COPY console/nginx-entrypoint/40-fleetbase-runtime-config.sh /docker-entrypoint.d/40-fleetbase-runtime-config.sh
RUN sed -i 's/\r$//' /docker-entrypoint.d/40-fleetbase-runtime-config.sh \
  && chmod +x /docker-entrypoint.d/40-fleetbase-runtime-config.sh

# Start Nginx server
CMD ["nginx", "-g", "daemon off;"]
