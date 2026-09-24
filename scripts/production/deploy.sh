#!/usr/bin/env bash
set -Eeuo pipefail

release="${1:-}"
expected_sha="${2:-}"
base=/var/www/paz-salvo-private
releases="$base/releases"
shared="$base/shared"
current="$base/current"
php=(/usr/bin/php8.4 -d memory_limit=256M)

[[ "$release" =~ ^[0-9]{8}-[0-9]{6}-[a-f0-9]{7}$ ]] || { echo 'Invalid release ID' >&2; exit 1; }
[[ "$expected_sha" =~ ^[a-f0-9]{40}$ ]] || { echo 'Invalid commit SHA' >&2; exit 1; }
target="$releases/$release"
test -d "$base" && test ! -L "$base"
test -d "$releases" && test ! -L "$releases"
test -d "$shared" && test ! -L "$shared"
test -d "$target" && test ! -L "$target"
test "$(realpath -e -- "$target")" = "$target"
test -f "$shared/.env" && test ! -L "$shared/.env" || { echo "Missing production .env at $shared/.env" >&2; exit 1; }
test -r "$shared/.env" || { echo 'Production .env is not readable by pazsalvo-deploy' >&2; exit 1; }
test -d "$shared/storage" && test ! -L "$shared/storage" || { echo 'Missing shared/storage' >&2; exit 1; }
umask 0007
mkdir -p -- "$shared/storage/app/private" "$shared/storage/app/public" \
  "$shared/storage/framework/cache/data" "$shared/storage/framework/sessions" \
  "$shared/storage/framework/views" "$shared/storage/logs"
test "$(<"$target/RELEASE_SHA")" = "$expected_sha"
test -f "$target/artisan" && test -f "$target/vendor/autoload.php"
test -f "$target/public/index.php" && test -f "$target/public/build/manifest.json"
test ! -e "$target/.env" && test ! -L "$target/.env" || { echo 'Artifact unexpectedly contains .env' >&2; exit 1; }
test ! -L "$target/storage" || { echo 'Artifact storage is a symlink' >&2; exit 1; }
if [[ -e "$target/storage" ]]; then
  test -d "$target/storage" && test "$(realpath -e -- "$target/storage")" = "$target/storage"
  rm -rf -- "$target/storage"
fi
test -d "$target/bootstrap" && test ! -L "$target/bootstrap"
mkdir -p -- "$target/bootstrap/cache"
test ! -L "$target/bootstrap/cache"
chgrp -Rh www-data -- "$target"
chmod 2770 -- "$target/bootstrap/cache"
ln -s -- "$shared/.env" "$target/.env"
ln -s -- "$shared/storage" "$target/storage"
cd "$target"
"${php[@]}" artisan config:clear
"${php[@]}" artisan route:clear
"${php[@]}" artisan view:clear
"${php[@]}" artisan event:clear
"${php[@]}" artisan config:cache
"${php[@]}" artisan route:cache
"${php[@]}" artisan view:cache
"${php[@]}" artisan event:cache
chmod -R g+rwX -- "$target/bootstrap/cache"

previous=''
if [[ -L "$current" ]]; then
  previous="$(readlink -f -- "$current")"
  previous_id="${previous##*/}"
  [[ "$previous_id" =~ ^[0-9]{8}-[0-9]{6}-[a-f0-9]{7}$ ]] &&
    [[ "$previous" == "$releases/$previous_id" ]] &&
    test -d "$previous" && test -f "$previous/.release-ready" || {
      echo 'current points outside prepared releases' >&2
      exit 1
    }
elif [[ -e "$current" ]]; then
  echo 'current exists and is not a symlink' >&2
  exit 1
fi

temp="$base/.current-$release"
test ! -e "$temp" && test ! -L "$temp"
trap 'if [[ -L "$temp" ]]; then unlink -- "$temp"; fi' EXIT
ln -s -- "releases/$release" "$temp"
mv -Tf -- "$temp" "$current"
echo "Activated release $release for $expected_sha"

if [[ -n "${PRODUCTION_HEALTH_URL:-}" ]]; then
  healthy=false
  for attempt in 1 2 3 4 5; do
    code="$(curl --silent --output /dev/null --write-out '%{http_code}' --connect-timeout 3 --max-time 10 "$PRODUCTION_HEALTH_URL")" || code=000
    if [[ "$code" == 200 ]]; then healthy=true; break; fi
    sleep 3
  done
  if [[ "$healthy" != true ]]; then
    echo 'Health check failed; restoring previous release.' >&2
    if [[ -n "$previous" ]]; then
      ln -s -- "releases/${previous##*/}" "$temp"
      mv -Tf -- "$temp" "$current"
      echo "Restored release ${previous##*/}" >&2
    else
      unlink -- "$current"
      echo 'First deployment failed health check; current removed.' >&2
    fi
    exit 1
  fi
else
  echo 'PRODUCTION_HEALTH_URL is unset; HTTP health check skipped.'
fi
touch -- "$target/.release-ready"

# Keep active, immediate predecessor, and newest remaining valid release.
declare -A keep=()
keep["$release"]=1
if [[ -n "$previous" ]]; then keep["${previous##*/}"]=1; fi
mapfile -t valid < <(find "$releases" -mindepth 2 -maxdepth 2 -name .release-ready -type f -printf '%h\n' | sed "s|^$releases/||" | sort -r)
for candidate in "${valid[@]}"; do
  [[ "$candidate" =~ ^[0-9]{8}-[0-9]{6}-[a-f0-9]{7}$ ]] || continue
  if [[ -z "${keep[$candidate]:-}" && "${#keep[@]}" -lt 3 ]]; then keep["$candidate"]=1; fi
done
for candidate in "${valid[@]}"; do
  [[ "$candidate" =~ ^[0-9]{8}-[0-9]{6}-[a-f0-9]{7}$ ]] || continue
  [[ -n "${keep[$candidate]:-}" ]] && continue
  obsolete="$releases/$candidate"
  test -d "$obsolete" && test ! -L "$obsolete" && test "$(realpath -e -- "$obsolete")" = "$obsolete"
  [[ "$obsolete" != "$(readlink -f -- "$current")" ]] || exit 1
  rm -rf -- "$obsolete"
  echo "Removed old release $candidate"
done
echo "Active release: $release"
