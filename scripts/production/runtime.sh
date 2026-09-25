#!/usr/bin/env bash

runtime_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

preflight_php_fpm() {
  sudo -n -l /usr/bin/systemctl reload php8.4-fpm >/dev/null || {
    echo 'pazsalvo-deploy cannot reload php8.4-fpm without a password' >&2
    return 1
  }
}

reload_php_fpm() {
  sudo -n /usr/bin/systemctl reload php8.4-fpm
}

php_fpm_active() {
  /usr/bin/systemctl is-active --quiet php8.4-fpm
}

switch_current() {
  local base="$1" release_id="$2" temp="$1/.current-${2}-$$"
  test ! -e "$temp" && test ! -L "$temp" || return 1
  ln -s -- "releases/$release_id" "$temp" || return 1
  if ! mv -Tf -- "$temp" "$base/current"; then
    unlink -- "$temp"
    return 1
  fi
}

validate_served_release() {
  local target="$1" sha="$2" mode="${3:-strict}" attempt
  for attempt in 1 2 3 4 5; do
    if python3 "$runtime_dir/validate_release.py" smoke "$target" "$sha" "$mode"; then
      return 0
    fi
    if [[ "$attempt" -lt 5 ]]; then sleep 3; fi
  done
  return 1
}

activate_and_validate() {
  local base="$1" target="$2" previous="$3" sha="$4" ready_marker="${5:-}" previous_sha=''
  preflight_php_fpm || return 1
  switch_current "$base" "${target##*/}" || return 1
  if reload_php_fpm && php_fpm_active &&
    validate_served_release "$target" "$sha" &&
    { [[ -z "$ready_marker" ]] || touch -- "$ready_marker"; }; then
    echo "Validated active release ${target##*/} ($sha)"
    return 0
  fi

  echo "Release ${target##*/} failed runtime validation; restoring previous current." >&2
  if [[ -n "$previous" ]]; then
    if ! switch_current "$base" "${previous##*/}"; then
      echo 'CRITICAL: Could not restore previous current.' >&2
      return 1
    fi
    previous_sha="$(<"$previous/RELEASE_SHA")"
  elif ! unlink -- "$base/current"; then
    echo 'CRITICAL: Could not remove current after failed first deployment.' >&2
    return 1
  fi

  if ! reload_php_fpm || ! php_fpm_active; then
    echo 'CRITICAL: PHP-FPM reload after restoring current failed.' >&2
    return 1
  fi
  if [[ -n "$previous" ]]; then
    if ! validate_served_release "$previous" "$previous_sha"; then
      if [[ "$(readlink -f -- "$base/current")" != "$previous" ]] ||
        ! validate_served_release "$previous" "$previous_sha" legacy-rollback; then
        echo 'CRITICAL: Previous release did not pass validation after restoration.' >&2
        return 1
      fi
    fi
  fi
  echo 'Previous runtime restored; operation failed.' >&2
  return 1
}
