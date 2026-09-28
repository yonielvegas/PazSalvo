#!/usr/bin/env bash
set -Eeuo pipefail

source "$(dirname -- "${BASH_SOURCE[0]}")/../../scripts/production/runtime.sh"
test_base="$(mktemp -d)"
trap 'rm -rf -- "$test_base"' EXIT
mkdir -p "$test_base/releases/20260925-140125-aaaaaaa" "$test_base/releases/20260925-140126-bbbbbbb"
previous="$test_base/releases/20260925-140125-aaaaaaa"
target="$test_base/releases/20260925-140126-bbbbbbb"
printf '%040d\n' 1 > "$previous/RELEASE_SHA"
printf '%040d\n' 2 > "$target/RELEASE_SHA"
ln -s 'releases/20260925-140125-aaaaaaa' "$test_base/current"

# Both versions are active, but Apache serves this application through 8.3.
PHP_FPM_SERVICE=php8.3-fpm
service_calls=()
function /usr/bin/systemctl {
  service_calls+=("$*")
  [[ "$*" == 'is-active --quiet php8.3-fpm' || "$*" == 'is-active --quiet php8.4-fpm' ]]
}
sudo() {
  service_calls+=("$*")
  [[ "$*" == '-n -l /usr/bin/systemctl restart php8.3-fpm' ||
     "$*" == '-n /usr/bin/systemctl restart php8.3-fpm' ||
     "$*" == '-n -l /usr/bin/systemctl restart php8.4-fpm' ||
     "$*" == '-n /usr/bin/systemctl restart php8.4-fpm' ]]
}
preflight_php_fpm
renew_php_fpm
php_fpm_active
test "${service_calls[*]}" = 'is-active --quiet php8.3-fpm -n -l /usr/bin/systemctl restart php8.3-fpm -n /usr/bin/systemctl restart php8.3-fpm is-active --quiet php8.3-fpm'
# An explicitly configured different pool is honored instead of hardcoded 8.3.
PHP_FPM_SERVICE=php8.4-fpm
service_calls=()
preflight_php_fpm
renew_php_fpm
test "${service_calls[*]}" = 'is-active --quiet php8.4-fpm -n -l /usr/bin/systemctl restart php8.4-fpm -n /usr/bin/systemctl restart php8.4-fpm'
for invalid_service in '' 'php8.3-fpm;other' 'apache2'; do
  PHP_FPM_SERVICE="$invalid_service"
  if preflight_php_fpm; then
    echo 'Invalid or missing PHP-FPM service accepted' >&2
    exit 1
  fi
done
PHP_FPM_SERVICE=php8.3-fpm
unset -f sudo /usr/bin/systemctl

# Use the same validator script even when validating the restored predecessor.
validator_calls=()
python3() { validator_calls+=("$*"); }
validate_served_release "$target" "$(<"$target/RELEASE_SHA")"
validate_served_release "$previous" "$(<"$previous/RELEASE_SHA")"
validate_served_release "$previous" "$(<"$previous/RELEASE_SHA")" legacy-rollback
test "${#validator_calls[@]}" -eq 3
test "${validator_calls[0]}" = "$runtime_dir/validate_release.py smoke $target $(<"$target/RELEASE_SHA") strict"
test "${validator_calls[1]}" = "$runtime_dir/validate_release.py smoke $previous $(<"$previous/RELEASE_SHA") strict"
test "${validator_calls[2]}" = "$runtime_dir/validate_release.py smoke $previous $(<"$previous/RELEASE_SHA") legacy-rollback"
unset -f python3

preflight_php_fpm() { [[ "${fail_preflight:-false}" != true ]]; }
php_fpm_active() { return 0; }
renew_count=0
renew_php_fpm() {
  renew_count=$((renew_count + 1))
  if [[ "${fail_renew:-false}" == true && "$renew_count" -eq 1 ]]; then return 1; fi
}
validate_served_release() {
  [[ "$1" == "$previous" ]]
}

fail_preflight=true
if activate_and_validate "$test_base" "$target" "$previous" "$(<"$target/RELEASE_SHA")"; then
  echo 'Failed preflight unexpectedly activated release' >&2
  exit 1
fi
test "$(readlink -f -- "$test_base/current")" = "$previous"
test "$renew_count" -eq 0
fail_preflight=false

fail_renew=true
if activate_and_validate "$test_base" "$target" "$previous" "$(<"$target/RELEASE_SHA")"; then
  echo 'Failed restart unexpectedly activated release' >&2
  exit 1
fi
test "$(readlink -f -- "$test_base/current")" = "$previous"
test "$renew_count" -eq 2

fail_renew=false
renew_count=0
if activate_and_validate "$test_base" "$target" "$previous" "$(<"$target/RELEASE_SHA")"; then
  echo 'Failed smoke unexpectedly activated release' >&2
  exit 1
fi
test "$(readlink -f -- "$test_base/current")" = "$previous"
test "$renew_count" -eq 2

validation_calls=()
validate_served_release() {
  test "$(readlink -f -- "$test_base/current")" = "$1" || return 1
  validation_calls+=("${1##*/}:${3:-strict}")
  [[ "$1" == "$previous" && "${3:-strict}" == legacy-rollback ]]
}
renew_count=0
if activate_and_validate "$test_base" "$target" "$previous" "$(<"$target/RELEASE_SHA")"; then
  echo 'Failed deploy unexpectedly activated release' >&2
  exit 1
fi
test "$(readlink -f -- "$test_base/current")" = "$previous"
test "$renew_count" -eq 2
test "${validation_calls[*]}" = "${target##*/}:strict ${previous##*/}:strict ${previous##*/}:legacy-rollback"

validate_served_release() { return 0; }
renew_count=0
activate_and_validate "$test_base" "$target" "$previous" "$(<"$target/RELEASE_SHA")"
test "$(readlink -f -- "$test_base/current")" = "$target"
test "$renew_count" -eq 1

# Model a worker retaining the physical release path until its FPM is restarted.
# Switching current alone must never satisfy the SHA check.
served_sha="$(<"$target/RELEASE_SHA")"
renew_php_fpm() {
  renew_count=$((renew_count + 1))
  if [[ "${retain_stale_worker:-false}" != true ]]; then
    served_sha="$(<"$test_base/current/RELEASE_SHA")"
  fi
}
validation_calls=()
fail_candidate=false
validate_served_release() {
  test "$(readlink -f -- "$test_base/current")" = "$1" || return 1
  validation_calls+=("${1##*/}:${served_sha}")
  [[ "$served_sha" == "$2" ]] || return 1
  [[ "$fail_candidate" != true || "$1" != "$target" ]]
}
# Start at A with workers serving A; activate B and require B's SHA.
switch_current "$test_base" "${previous##*/}"
renew_php_fpm
activate_and_validate "$test_base" "$target" "$previous" "$(<"$target/RELEASE_SHA")"
test "$served_sha" = "$(<"$target/RELEASE_SHA")"
# Restore A, then fail B after it has been loaded. Rollback must refresh to A.
switch_current "$test_base" "${previous##*/}"
renew_php_fpm
fail_candidate=true
validation_calls=()
if activate_and_validate "$test_base" "$target" "$previous" "$(<"$target/RELEASE_SHA")"; then
  echo 'Failed candidate unexpectedly accepted' >&2
  exit 1
fi
test "$(readlink -f -- "$test_base/current")" = "$previous"
test "$served_sha" = "$(<"$previous/RELEASE_SHA")"
test "${validation_calls[*]}" = "${target##*/}:$(<"$target/RELEASE_SHA") ${previous##*/}:$(<"$previous/RELEASE_SHA")"
# A successful command that restarts another service leaves the worker on A.
fail_candidate=false
retain_stale_worker=true
validation_calls=()
if activate_and_validate "$test_base" "$target" "$previous" "$(<"$target/RELEASE_SHA")"; then
  echo 'Stale served SHA unexpectedly accepted' >&2
  exit 1
fi
test "${validation_calls[0]}" = "${target##*/}:$(<"$previous/RELEASE_SHA")"
test "$(readlink -f -- "$test_base/current")" = "$previous"
echo 'Runtime transition tests passed'
