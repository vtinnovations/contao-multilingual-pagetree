#!/usr/bin/env bash

# Contao Multilingual Pagetree
# Package: vtinnovations/contao-multilingual-pagetree
# Copyright: V&T Innovations Team
# Licence: proprietary
# Website: https://www.v-t.one


set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <application-directory>" >&2
    exit 2
fi

application_directory=$1
console="$application_directory/vendor/bin/contao-console"

if [[ ! -x "$console" ]]; then
    echo "Contao console is missing: $console" >&2
    exit 1
fi

echo "Database: $(mysql --protocol=TCP -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "${DB_USER:-root}" -p"${DB_PASSWORD:-root}" -Nse 'SELECT VERSION()')"
"$console" about --env=test
"$console" list --env=test
"$console" contao-multilingual-pagetree:integrity:scan --help --env=test
"$console" contao-multilingual-pagetree:integrity:repair --help --env=test
"$console" debug:container --env=test 'Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScanner'
"$console" debug:container --env=test 'Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry'
"$console" debug:container --env=test 'Vtinnovations\ContaoMultilingualPagetree\Routing\MultilingualPagetreeRouteProviderDecorator'
"$console" debug:router --env=test
"$console" lint:twig "$application_directory/vendor/vtinnovations/contao-multilingual-pagetree/contao/templates" --env=test
"$console" contao:migrate --no-interaction --env=test

# Representative pre-point-5/9 language data: the columns exist after schema
# creation, but their empty values still need the conservative migrations.
mysql --protocol=TCP -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "${DB_USER:-root}" -p"${DB_PASSWORD:-root}" contao <<'SQL'
INSERT INTO tl_inline_language
    (pid, sorting, tstamp, language, label, fallback, pageAvailabilityMode, contentTranslationMode, published)
VALUES
    (9001, 128, 0, 'de', 'Legacy German', '', '', '', '1');
SQL

"$console" contao:migrate --no-interaction --env=test

normalised=$(mysql --protocol=TCP -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "${DB_USER:-root}" -p"${DB_PASSWORD:-root}" -Nse \
    "SELECT CONCAT(pageAvailabilityMode, ':', contentTranslationMode) FROM contao.tl_inline_language WHERE pid=9001 AND language='de'")
if [[ "$normalised" != "fallback:connected" ]]; then
    echo "Legacy migration fixture was not normalised safely: $normalised" >&2
    exit 1
fi

"$console" contao:migrate --no-interaction --env=test
"$console" cache:warmup --env=test

echo "Kernel boot, container, routes, commands, legacy-value migration and idempotency checks passed."
