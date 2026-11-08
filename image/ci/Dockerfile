# Disposable native ARM64 pi-gen builder. No release credentials enter this image.
FROM debian:trixie-slim
ENV DEBIAN_FRONTEND=noninteractive LC_ALL=C.UTF-8 PYTHONDONTWRITEBYTECODE=1
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates bash coreutils quilt parted qemu-user-binfmt debootstrap zerofree zip \
    dosfstools e2fsprogs libcap2-bin grep rsync xz-utils curl xxd file git kmod bc gpg pigz \
    arch-test util-linux mount python3 openssh-client openssl sqlite3 \
    php8.4-cli php8.4-sqlite3 php8.4-bcmath php8.4-mbstring php8.4-xml php8.4-zip \
    php8.4-snmp php8.4-curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /work
