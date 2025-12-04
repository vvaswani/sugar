#!/bin/sh
set -e


# uncomment only when php-fpm and nginx in the same container
# nginx

# jwt setup
# ensure JWT config dir exists
mkdir -p /var/www/html/config/jwt

# write private key
if [ -n "$JWT_PRIVATE_KEY" ]; then
  echo "$JWT_PRIVATE_KEY" > /var/www/html/config/jwt/private.pem
  chmod 600 /var/www/html/config/jwt/private.pem
else
  echo "JWT_PRIVATE_KEY not set"
  exit 1
fi

# write public key
if [ -n "$JWT_PUBLIC_KEY" ]; then
  echo "$JWT_PUBLIC_KEY" > /var/www/html/config/jwt/public.pem
  chmod 600 /var/www/html/config/jwt/public.pem
else
  echo "JWT_PUBLIC_KEY not set"
  exit 1
fi

chown -R www-data:www-data /var/www/html/config/jwt

# php-fpm config
# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
	set -- php-fpm "$@"
fi

if [ "$1" = 'php-fpm' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	# Install dependencies
	composer install --prefer-dist --no-progress --no-interaction

	# Wait for db to be ready
  until php wait-for-db.php; do
      echo 'Waiting for DB...'
      sleep 2
  done

	# Run migrations
	bin/console doctrine:migrations:migrate --no-interaction
fi

exec docker-php-entrypoint "$@"
