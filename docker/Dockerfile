FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libsqlite3-dev \
    libicu-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    nodejs \
    npm \
    && npm install --global yarn \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    gd \
    intl \
    pdo_sqlite \
    zip \
    bcmath \
    mbstring

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy dependency definitions
COPY package.json yarn.lock .yarnrc ./
COPY composer.json composer.lock ./

# Install dependencies
RUN yarn install
RUN composer install --no-dev --optimize-autoloader

# Copy application code
COPY . .

# Ensure proper permissions for data directory
RUN chown -R www-data:www-data /var/www/html
