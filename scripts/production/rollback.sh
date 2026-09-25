#!/usr/bin/env bash
set -Eeuo pipefail
umask 0027

requested_release="${1:-}"
base=/var/www/paz-salvo-private
releases="$base/releases"
shared="$base/shared"
current="$base/current"
source "$(dirname -- "${BASH_SOURCE[0]}")/runtime.sh"

test -d "$releases" && test ! -L "$releases"
test -d "$shared" && test ! -L "$shared"
test ! -e "$current" || test -L "$current" || { echo 'current is not a symlink' >&2; exit 1; }
echo 'Available prepared releases:'
find "$releases" -mindepth 2 -maxdepth 2 -name .release-ready -type f -printf '%h\n' | sed "s|^$releases/||" | sort -r
if [[ -z "$requested_release" ]]; then
  echo 'Enter one of the listed release IDs to perform rollback.'
  exit 0
fi

[[ "$requested_release" =~ ^[0-9]{8}-[0-9]{6}-[a-f0-9]{7}$ ]] || { echo 'Invalid release ID' >&2; exit 1; }
target="$releases/$requested_release"
test -d "$target" && test ! -L "$target" && test "$(realpath -e -- "$target")" = "$target"
test -f "$target/.release-ready"
python3 "$runtime_dir/validate_release.py" preflight "$target" "$shared"
sha="$(<"$target/RELEASE_SHA")"
[[ "$sha" =~ ^[a-f0-9]{40}$ ]] || { echo 'Invalid release SHA' >&2; exit 1; }

previous=''
if [[ -L "$current" ]]; then
  previous="$(readlink -f -- "$current")"
  previous_id="${previous##*/}"
  [[ "$previous_id" =~ ^[0-9]{8}-[0-9]{6}-[a-f0-9]{7}$ ]] &&
    [[ "$previous" == "$releases/$previous_id" ]] &&
    test -f "$previous/.release-ready" || { echo 'current points outside prepared releases' >&2; exit 1; }
fi
[[ "$previous" != "$target" ]] || { echo 'Requested release is already active.'; exit 0; }
activate_and_validate "$base" "$target" "$previous" "$sha"
