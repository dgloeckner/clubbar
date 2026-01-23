# Ruderbar Backend - Zero extension building
FROM serversideup/php:8.3-fpm-apache-bookworm-full

# That's it - all extensions included!
WORKDIR /var/www/html

EXPOSE 80