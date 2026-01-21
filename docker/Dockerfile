FROM php:8.2-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Update Apache DocumentRoot to point to Grocy's public folder
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Fix HTTPS detection behind reverse proxy
RUN echo "SetEnvIf X-Forwarded-Proto https HTTPS=on" >> /etc/apache2/conf-available/reverse-proxy.conf \
    && a2enconf reverse-proxy

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
    libonig-dev \
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

# Configure PHP memory limit
RUN echo "memory_limit = 2048M" > /usr/local/etc/php/conf.d/memory-limit.ini

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

# Copy entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Ensure proper permissions for data directory
RUN chown -R www-data:www-data /var/www/html

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
