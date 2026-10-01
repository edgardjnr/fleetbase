#!/bin/sh
# Writes /usr/share/nginx/html/fleetbase.config.json from environment variables at
# container start (run by the nginx image's /docker-entrypoint.sh).
#
# Only the keys allowed by console/app/utils/runtime-config.js are emitted, and only
# when set. If none are set the file is left untouched, so bind-mounting a
# fleetbase.config.json (as docker-compose.yml does) keeps working.
set -eu

CONFIG_FILE=/usr/share/nginx/html/fleetbase.config.json
KEYS="API_HOST API_NAMESPACE SOCKETCLUSTER_PATH SOCKETCLUSTER_HOST SOCKETCLUSTER_SECURE SOCKETCLUSTER_PORT OSRM_HOST EXTENSIONS"

json=""
for key in $KEYS; do
    eval "value=\${$key:-}"
    [ -z "$value" ] && continue
    # escape backslashes and double quotes for JSON
    value=$(printf '%s' "$value" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')
    json="${json:+$json,
}  \"$key\": \"$value\""
done

if [ -z "$json" ]; then
    echo "fleetbase-runtime-config: no runtime variables set, keeping existing $CONFIG_FILE"
    exit 0
fi

printf '{\n%s\n}\n' "$json" > "$CONFIG_FILE"
echo "fleetbase-runtime-config: wrote $CONFIG_FILE"
