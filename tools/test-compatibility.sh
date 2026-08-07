#!/usr/bin/env bash

# Contao Multilingual Pagetree
# Package: vtinnovations/contao-multilingual-pagetree
# Copyright: V&T Innovations Team
# Licence: proprietary
# Website: https://www.v-t.one


set -euo pipefail

contao_constraint="5.7.*"
requested_php=""
dependency_strategy="stable"
testsuite="all"
keep_workdir=0

usage() {
    echo "Usage: $0 [--php VERSION] [--contao 5.x.*] [--prefer-lowest] [--testsuite unit|integration|all] [--keep-workdir]"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --php) requested_php=${2:?Missing PHP version}; shift 2 ;;
        --contao) contao_constraint=${2:?Missing Contao constraint}; shift 2 ;;
        --prefer-lowest) dependency_strategy="lowest"; shift ;;
        --testsuite) testsuite=${2:?Missing test suite}; shift 2 ;;
        --keep-workdir) keep_workdir=1; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; exit 2 ;;
    esac
done

case "$contao_constraint" in 5.[0-9].\*) ;; *) echo "Use an explicit Contao minor constraint such as 5.7.*." >&2; exit 2 ;; esac
case "$testsuite" in unit|integration|all) ;; *) echo "Unknown test suite: $testsuite" >&2; exit 2 ;; esac

command -v php >/dev/null || { echo "PHP is required." >&2; exit 1; }
command -v composer >/dev/null || { echo "Composer is required." >&2; exit 1; }

actual_php=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
if [[ -n "$requested_php" && "$requested_php" != "$actual_php" ]]; then
    echo "This runner does not install or switch PHP. Requested $requested_php, current PHP is $actual_php." >&2
    exit 2
fi

workdir=$(mktemp -d "${TMPDIR:-/tmp}/contao-multilingual-pagetree-compat.XXXXXX")
cleanup() {
    if [[ $keep_workdir -eq 1 ]]; then
        echo "Compatibility work directory retained: $workdir"
    else
        rm -rf "$workdir"
    fi
}
trap cleanup EXIT

package_directory="$workdir/package"
mkdir -p "$package_directory"
rsync -a --exclude=.git --exclude=vendor --exclude=.phpunit.cache ./ "$package_directory/"

composer --working-dir="$package_directory" config audit.block-insecure false
composer --working-dir="$package_directory" require "contao/core-bundle:$contao_constraint" --no-update

update_arguments=(--with-all-dependencies --prefer-dist --no-interaction --no-progress)
if [[ "$dependency_strategy" == "lowest" ]]; then
    update_arguments+=(--prefer-lowest --prefer-stable)
fi

COMPOSER_ROOT_VERSION=dev-main composer --working-dir="$package_directory" update "${update_arguments[@]}"
CONTAO_CONSTRAINT="$contao_constraint" DEPENDENCY_STRATEGY="$dependency_strategy" php "$package_directory/tools/verify-installed-stack.php"
composer --working-dir="$package_directory" "test:$testsuite"

echo "Compatibility run passed (PHP $actual_php, Contao $contao_constraint, $dependency_strategy, suite $testsuite)."
