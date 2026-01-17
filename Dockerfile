FROM php:8.4-fpm

# Install system dependencies
RUN apt-get update

RUN apt-get install -y git
RUN apt-get install -y curl
RUN apt-get install -y libpng-dev
RUN apt-get install -y libonig-dev
RUN apt-get install -y libxml2-dev
RUN apt-get install -y libzip-dev
RUN apt-get install -y zip
RUN apt-get install -y unzip
RUN apt-get install -y postgresql-client
RUN apt-get install -y libpq-dev

RUN docker-php-ext-install pdo
RUN docker-php-ext-install pdo_pgsql
RUN docker-php-ext-install mbstring
RUN docker-php-ext-install exif
RUN docker-php-ext-install pcntl
RUN docker-php-ext-install bcmath
RUN docker-php-ext-install gd
RUN docker-php-ext-install zip

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer 2.9.3
COPY --from=composer:2.9.3 /usr/bin/composer /usr/bin/composer

# Install Xdebug
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Set working directory
WORKDIR /var/www/html

# Copy PHP configuration
COPY php.ini /usr/local/etc/php/conf.d/custom.ini
COPY xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

# Copy application files
COPY . .

EXPOSE 9000

CMD ["php-fpm"]
