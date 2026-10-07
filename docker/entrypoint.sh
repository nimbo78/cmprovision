#!/bin/sh
# First run mirrors debian/postinst: .env from debian/env.example, app key, database, seeders.
# Then php-fpm, the queue worker (ComputeSHA256 jobs) and nginx.
set -e
APP=/var/lib/cmprovision
cd "$APP"

as_app() { setpriv --reuid=app --regid=app --init-groups env HOME=/home/app COMPOSER_HOME=/home/app/.composer "$@"; }

if [ ! -f composer.json ]; then
    echo "No application in $APP - run docker/bench.sh sync first" >&2
    exit 1
fi

if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
    echo "Installing composer dependencies (with dev packages, for the test-suite)"
    as_app composer install --no-interaction --prefer-dist --no-progress
fi

if [ ! -f .env ]; then
    cp debian/env.example .env
    sed -i "s#^DB_DATABASE=.*#DB_DATABASE=$APP/database/database.sqlite#" .env
    chown app:app .env
    as_app php artisan key:generate --force
fi

mkdir -p public/uploads storage/app/firmware
chown app:app public/uploads storage/app/firmware

if [ ! -f database/database.sqlite ]; then
    as_app touch database/database.sqlite
    as_app php artisan migrate --seed --force
else
    as_app php artisan migrate --force
fi
as_app php artisan config:clear
as_app php artisan view:clear

# Web user for the bench (auth:create-user is interactive).
as_app php artisan tinker --execute='if (!App\Models\User::where("email", "bench@example.com")->exists()) { App\Models\User::create(["name" => "Bench", "email" => "bench@example.com", "password" => bcrypt("bench1234")]); echo "bench user created\n"; }'

"php-fpm${PHPV}" -F &
as_app php artisan queue:work --sleep=3 --tries=1 &
exec nginx -g 'daemon off;'
