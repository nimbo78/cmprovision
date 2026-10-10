#!/bin/sh
# Builds public/tools/ledpattern for the utility OS (32-bit ARM, static, stripped) and checks it.
# Run from the repository root on Debian/Ubuntu with the cross compiler:
#   apt-get install gcc-arm-linux-gnueabihf
#   sh tools/ledpattern/build.sh
# The binary is committed: the package is built on the provisioner, without a cross compiler.
set -e
cd "$(dirname "$0")/../.."
mkdir -p public/tools
arm-linux-gnueabihf-gcc -std=gnu99 -O2 -Wall -Wextra -static -s \
    -o public/tools/ledpattern tools/ledpattern/ledpattern.c -lm
file public/tools/ledpattern
ls -l public/tools/ledpattern
