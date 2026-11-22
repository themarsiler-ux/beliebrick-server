FROM php:8.3-apache
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf && sed -i 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf
COPY --chown=www-data:www-data room.php /var/www/html/room.php
RUN chown www-data:www-data /var/www/html
EXPOSE 10000
CMD ["apache2-foreground"]
