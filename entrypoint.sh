apk add --update --no-cache $PHPIZE_DEPS linux-headers
pecl install xdebug

docker-php-ext-enable xdebug

curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
composer install

tail -f /dev/null
