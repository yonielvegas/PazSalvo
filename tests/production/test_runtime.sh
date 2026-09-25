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

preflight_php_fpm() { [[ "${fail_preflight:-false}" != true ]]; }
php_fpm_active() { return 0; }
reload_count=0
reload_php_fpm() {
  reload_count=$((reload_count + 1))
  if [[ "${fail_reload:-false}" == true && "$reload_count" -eq 1 ]]; then return 1; fi
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
test "$reload_count" -eq 0
fail_preflight=false

fail_reload=true
if activate_and_validate "$test_base" "$target" "$previous" "$(<"$target/RELEASE_SHA")"; then
  echo 'Failed reload unexpectedly activated release' >&2
  exit 1
fi
test "$(readlink -f -- "$test_base/current")" = "$previous"
test "$reload_count" -eq 2

fail_reload=false
reload_count=0
if activate_and_validate "$test_base" "$target" "$previous" "$(<"$target/RELEASE_SHA")"; then
  echo 'Failed smoke unexpectedly activated release' >&2
  exit 1
fi
test "$(readlink -f -- "$test_base/current")" = "$previous"
test "$reload_count" -eq 2

validate_served_release() { return 0; }
reload_count=0
activate_and_validate "$test_base" "$target" "$previous" "$(<"$target/RELEASE_SHA")"
test "$(readlink -f -- "$test_base/current")" = "$target"
test "$reload_count" -eq 1
echo 'Runtime transition tests passed'
