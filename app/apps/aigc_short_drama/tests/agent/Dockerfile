FROM php:8.0-cli
RUN docker-php-ext-install pdo_mysql bcmath
WORKDIR /app
ENV SHORT_DRAMA_AGENT_TEST=isolated-mysql
ENTRYPOINT ["php"]
