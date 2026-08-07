#!/usr/bin/env bash

# Contao Multilingual Pagetree
# Package: vtinnovations/contao-multilingual-pagetree
# Copyright: V&T Innovations Team
# Licence: proprietary
# Website: https://www.v-t.one


set -euo pipefail

if [[ $# -ne 3 ]]; then
    echo "Usage: $0 <application-directory> <package-directory> <Contao minor constraint>" >&2
    exit 2
fi

application_directory=$1
package_directory=$2
contao_constraint=$3

case "$contao_constraint" in
    5.[0-9].\*) ;;
    *) echo "Refusing non-minor Contao constraint: $contao_constraint" >&2; exit 2 ;;
esac

if [[ -e "$application_directory" ]]; then
    echo "Application directory must not already exist: $application_directory" >&2
    exit 2
fi

composer create-project "contao/managed-edition:$contao_constraint" "$application_directory" \
    --prefer-dist --no-interaction --no-progress --no-install

repository_json=$(php -r 'echo json_encode(["type" => "path", "url" => $argv[1], "options" => ["symlink" => false]], JSON_THROW_ON_ERROR);' "$package_directory")
composer --working-dir="$application_directory" config repositories.multilingual-pagetree "$repository_json"
composer --working-dir="$application_directory" config audit.block-insecure false
composer --working-dir="$application_directory" require "vtinnovations/contao-multilingual-pagetree:@dev" --no-update

update_arguments=(--with-all-dependencies --prefer-dist --no-interaction --no-progress)
if [[ "${DEPENDENCY_STRATEGY:-stable}" == "lowest" ]]; then
    update_arguments+=(--prefer-lowest --prefer-stable)
fi
COMPOSER_ROOT_VERSION=dev-main composer --working-dir="$application_directory" update "${update_arguments[@]}"

resolved=$(composer --working-dir="$application_directory" show contao/core-bundle --format=json | php -r '
    $data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    echo $data["versions"][0] ?? "";
')

expected_prefix=${contao_constraint%.*}
resolved_numeric=${resolved#\* }
resolved_numeric=${resolved_numeric#v}
if [[ "$resolved_numeric" != "$expected_prefix".* ]]; then
    echo "Managed application resolved Contao $resolved, expected $contao_constraint" >&2
    exit 1
fi

echo "Disposable managed application installed at $application_directory with Contao $resolved."
