#!/bin/sh

# Exit the script as soon as a command fails
set -e

# Create mysql databases if none exists
php artisan mysql:createdb

# Run migrations
php artisan migrate --force

# Run migrations for sandbox too
php artisan sandbox:migrate --force

# Entregas RestaurantePro: as migrations do app (api/database/migrations) também no banco sandbox, que o modo de teste
# do console usa; o sandbox:migrate acima só roda as dos pacotes (mesma conexão: SANDBOX_DB_CONNECTION, padrão "sandbox")
php artisan migrate --force --database="${SANDBOX_DB_CONNECTION:-sandbox}" --path=database/migrations

# Seed database
php artisan fleetbase:seed

# Create permissions, policies, and roles
php artisan fleetbase:create-permissions

# Restart queue
php artisan queue:restart

# Sync scheduler
php artisan schedule-monitor:sync

# Clear cache
php artisan cache:clear
php artisan route:clear

# Optimize
php artisan config:cache
php artisan route:cache

# Initialize registry
php artisan registry:init

# Notify open install pages that setup has completed
php artisan fleetbase:notify-installed || true

# Restart octane
# php artisan octane:reload
