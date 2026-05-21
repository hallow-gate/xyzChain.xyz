# This tells Docker to download PHP 7.4 with Apache
FROM php:7.4-apache

# Copy your blockchain PHP files into the web folder
COPY . /var/www/html/

# (Optional but good for security) Enable Apache rewrite rules
RUN a2enmod rewrite
