# Test bench image: Debian + nginx + php-fpm, laid out like the cmprovision4 .deb
# (app in /var/lib/cmprovision, nginx site from debian/cmprovision).
# Build args pick the Debian release and matching PHP version:
#   bullseye + 7.4  (what the production provisioner runs)
#   trixie   + 8.4  (current Raspberry Pi OS)
ARG DEBIAN=bullseye
FROM debian:${DEBIAN}-slim
ARG DEBIAN
ARG PHPV=7.4
ARG UID=1000
ARG GID=1000
ENV DEBIAN_FRONTEND=noninteractive PHPV=${PHPV}

# bullseye is end-of-life: its packages only live on archive.debian.org now.
RUN if [ "$DEBIAN" = bullseye ]; then \
        printf 'deb http://archive.debian.org/debian bullseye main\ndeb http://archive.debian.org/debian-security bullseye-security main\n' > /etc/apt/sources.list \
        && rm -rf /etc/apt/sources.list.d/* \
        && echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99archive; \
    fi

# Same PHP extensions as debian/control (no php-curl on purpose: the package does not depend on it).
# Build tools are here so the same container can build the .deb.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx sqlite3 composer git unzip ca-certificates curl rsync xz-utils bzip2 util-linux \
        php${PHPV}-cli php${PHPV}-fpm php${PHPV}-sqlite3 php${PHPV}-bcmath php${PHPV}-mbstring \
        php${PHPV}-xml php${PHPV}-zip php${PHPV}-snmp \
        dpkg-dev debhelper build-essential fakeroot \
    && rm -rf /var/lib/apt/lists/*

# App files are bind-mounted from the host and owned by this uid, so php-fpm and composer run as it.
RUN groupadd -g ${GID} app && useradd -m -u ${UID} -g ${GID} -s /bin/bash app

# Upload limits as debian/postinst sets them in php.ini.
RUN printf 'upload_max_filesize = 8G\npost_max_size = 8G\nmax_input_time = 86400\n' \
        > /etc/php/${PHPV}/fpm/conf.d/99-cmprovision.ini \
    && sed -i "s/^user = www-data/user = app/; s/^group = www-data/group = app/" /etc/php/${PHPV}/fpm/pool.d/www.conf \
    && mkdir -p /run/php

# nginx site: the packaged one with the socket path fixed up like postinst does.
COPY debian/cmprovision /etc/nginx/sites-available/cmprovision
RUN sed -i "s#/run/php/php-fpm.sock#/run/php/php${PHPV}-fpm.sock#" /etc/nginx/sites-available/cmprovision \
    && ln -sf /etc/nginx/sites-available/cmprovision /etc/nginx/sites-enabled/cmprovision \
    && rm -f /etc/nginx/sites-enabled/default

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/lib/cmprovision
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
