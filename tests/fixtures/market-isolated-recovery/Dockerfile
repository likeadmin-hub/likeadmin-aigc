FROM php@sha256:8bfff0e5249f2073504c335b9f7ae54bbe84c3bc9338bfc8d2a04e77d112360b

LABEL local.c03.purpose="owned-isolated-recovery-tool"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev ffmpeg \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 pdo_mysql pcntl gd zip \
    && rm -rf /var/lib/apt/lists/* \
    && php -r 'foreach (["curl","fileinfo","mbstring","posix","pdo_mysql","pcntl","gd","zip"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR,"Missing extension: ".$e."\n"); exit(1); } } if (!function_exists("proc_open") || !is_executable("/usr/bin/ffprobe")) { exit(1); }'

WORKDIR /source
