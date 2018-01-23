FROM php:7.0-apache

RUN apt-get update && apt-get -y install git zip unzip zlib1g-dev
RUN docker-php-ext-install zip
RUN docker-php-ext-install pcntl
RUN (cd ~/ && (curl -s https://getcomposer.org/installer | php)) \
    && mv ~/composer.phar /usr/bin/composer
