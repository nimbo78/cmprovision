#!/bin/bash
# Disposable Debian builder. No release token or writable checkout is mounted here.
set -euo pipefail
export LC_ALL=C.UTF-8 PYTHONDONTWRITEBYTECODE=1
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_BIN_COMPAT=symlink
: "${RELEASE_VERSION:?}" "${SOURCE_SHA:?}"
[[ "$RELEASE_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ && "$SOURCE_SHA" =~ ^[0-9a-f]{40}$ ]]
test -z "${GH_TOKEN:-}${GITHUB_TOKEN:-}"
mkdir -p /work/tests /work/production /output/audit
CMP_RELEASE_PACKAGE_CHECK=/ci/check-package.php \
    python3 -m unittest discover -s /control-tests -p test_release_package.py -v \
    2>&1 | tee /output/audit/package-payload-tests.log
# Source-contract tests expect checkout placeholders, before export-subst. Keep
# their writable test copy separate from the versioned production archive.
tar -C /source --exclude='./.git' -cf - . | tar -xf - -C /work/tests
tar -xf /input/source.tar -C /work/production
cd /work/tests
composer install --no-interaction --prefer-dist --no-progress
cp debian/env.example .env
php artisan key:generate --force
php artisan test 2>&1 | tee /output/audit/php-tests.log
# Older supported tags predate power controls. New sources must test the executor too.
if [ -d tests/power ]; then
    python3 -m unittest discover -s tests/power -p 'test_*.py' -v \
        2>&1 | tee /output/audit/station-power-tests.log
fi
if [ -d tests/updates ]; then
    python3 -B -m unittest discover -s tests/updates -v 2>&1 | tee /output/audit/station-updates-tests.log
fi

# The pinned upstream + the tagged portable patch are tested before packaging.
sh /work/production/packaging/fpms/prepare.sh /work/fpms-tests
cd /work/fpms-tests
power_tests=()
if [ -f tests/test_cmprovision_power.py ]; then power_tests+=(tests/test_cmprovision_power.py); fi
python3 -m pytest tests/test_cmprovision.py tests/test_cmprovision_fonts.py \
    tests/test_cmprovision_v2.py tests/test_portable_display.py "${power_tests[@]}" -q \
    2>&1 | tee /output/audit/fpms-tests.log

# build-deb extracts the immutable archive again; it never sees test vendor/.env.
bash /work/production/tools/build-deb.sh /input/source.tar /output/assets \
    2>&1 | tee /output/audit/package-build.log
sh /work/production/packaging/fpms/build.sh lite /output/assets \
    2>&1 | tee /output/audit/fpms-build.log
for name in cmprovision4 fpms-lite; do
    package=/output/assets/${name}_${RELEASE_VERSION}_all.deb
    test "$(dpkg-deb -f "$package" Package)" = "$name"
    test "$(dpkg-deb -f "$package" Version)" = "$RELEASE_VERSION"
    test "$(dpkg-deb -f "$package" Architecture)" = all
done
dpkg-deb -x "/output/assets/cmprovision4_${RELEASE_VERSION}_all.deb" /work/payload
php /ci/check-package.php /work/payload/var/lib/cmprovision "$RELEASE_VERSION" "$SOURCE_SHA"
CMP_IMAGE_PACKAGE="/output/assets/cmprovision4_${RELEASE_VERSION}_all.deb" \
    python3 -m unittest discover -s /control-tests -p test_package_layout.py -v \
    2>&1 | tee /output/audit/package-layout-tests.log
printf 'Source %s\nVersion %s\n' "$SOURCE_SHA" "$RELEASE_VERSION" > /output/audit/package-source.txt
