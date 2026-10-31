#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
image="${NEXTCLOUD_IMAGE:-nextcloud:35.0.2-apache}"
name="pfwd-e2e-$$"
network="$name"
nextcloud="$name-nextcloud"
paperless="$name-paperless"
port="${E2E_PORT:-18080}"
base="http://127.0.0.1:$port"
dav="$base/remote.php/dav/files/admin"
auth=(-u admin:admin-e2e-password)

cleanup() {
	status=$?
	if [ "$status" -ne 0 ]; then
		docker logs "$nextcloud" 2>&1 | tail -50 || true
		docker exec "$nextcloud" sh -c 'tail -20 /var/www/html/data/nextcloud.log' 2>/dev/null || true
	fi
	docker rm -f "$nextcloud" "$paperless" >/dev/null 2>&1 || true
	docker network rm "$network" >/dev/null 2>&1 || true
	exit "$status"
}
trap cleanup EXIT

occ() {
	docker exec -u www-data "$nextcloud" php occ "$@"
}

fail() {
	echo "FAIL: $*" >&2
	exit 1
}

docker network create "$network" >/dev/null
docker run -d --name "$paperless" --network "$network" --network-alias paperless \
	-v "$root/tests/e2e/mock_paperless.py:/mock.py:ro" python:3.13-alpine python /mock.py >/dev/null
docker run -d --name "$nextcloud" --network "$network" -p "127.0.0.1:$port:80" \
	-e SQLITE_DATABASE=nextcloud \
	-e NEXTCLOUD_ADMIN_USER=admin \
	-e NEXTCLOUD_ADMIN_PASSWORD=admin-e2e-password \
	-e NEXTCLOUD_TRUSTED_DOMAINS=127.0.0.1 \
	-e PAPERLESS_FORWARDER_MODE=live \
	-e PAPERLESS_FORWARDER_USER=admin \
	-e PAPERLESS_FORWARDER_PATH=/Paperless \
	-e PAPERLESS_URL=http://paperless:8000 \
	-e PAPERLESS_API_TOKEN=e2e-token \
	-v "$root/appinfo:/opt/paperlessforwarder/appinfo:ro" \
	-v "$root/lib:/opt/paperlessforwarder/lib:ro" \
	"$image" >/dev/null

for _ in $(seq 1 120); do
	if occ status --output=json 2>/dev/null | grep -q '"installed":true'; then
		break
	fi
	sleep 3
done
occ status --output=json | grep -q '"installed":true' || fail "Nextcloud did not install"
for _ in $(seq 1 60); do
	curl -fs -o /dev/null "$base/status.php" && break
	sleep 2
done
curl -fs -o /dev/null "$base/status.php" || fail "Nextcloud is not serving HTTP"

docker exec "$nextcloud" sh -c 'mkdir -p /var/www/html/custom_apps/paperlessforwarder && cp -r /opt/paperlessforwarder/. /var/www/html/custom_apps/paperlessforwarder/ && chown -R www-data:www-data /var/www/html/custom_apps/paperlessforwarder'
occ config:system:set allow_local_remote_servers --value true --type boolean >/dev/null
occ app:enable paperlessforwarder >/dev/null
occ app:list --output=json | grep -q '"paperlessforwarder"' || fail "app is not enabled"

pdf="$(mktemp)"
printf '%%PDF-1.4\n%% e2e %s\n%%%%EOF\n' "$(date +%s%N)" > "$pdf"
old_mtime=$(( $(date +%s) - 600 ))

code=$(curl -s -o /dev/null -w '%{http_code}' "${auth[@]}" -X MKCOL "$dav/Paperless")
[ "$code" = 201 ] || fail "MKCOL returned $code"
code=$(curl -s -o /dev/null -w '%{http_code}' "${auth[@]}" -X PUT -H "X-OC-Mtime: $old_mtime" --data-binary "@$pdf" "$dav/Paperless/e2e.pdf")
[ "$code" = 201 ] || fail "upload into the forwarded folder returned $code"
code=$(curl -s -o /dev/null -w '%{http_code}' "${auth[@]}" -X PUT --data-binary "@$pdf" "$dav/outside.pdf")
[ "$code" = 201 ] || fail "upload outside the forwarded folder returned $code"
rm -f "$pdf"

output=$(docker exec -u www-data "$nextcloud" php -f /var/www/html/cron.php 2>&1) || fail "cron.php failed: $output"

for _ in $(seq 1 30); do
	output=$(occ paperlessforwarder:process 2>&1) || fail "paperlessforwarder:process failed: $output"
	code=$(curl -s -o /dev/null -w '%{http_code}' "${auth[@]}" -X PROPFIND -H 'Depth: 0' "$dav/Paperless/e2e.pdf")
	[ "$code" = 404 ] && break
	sleep 5
done
[ "$code" = 404 ] || { occ paperlessforwarder:status || true; fail "duplicate source was not trashed"; }

uploads=$(docker exec "$paperless" wget -qO- http://127.0.0.1:8000/uploads)
[ "$(printf '%s' "$uploads" | grep -o '"status"' | wc -l | tr -d ' ')" = 2 ] || fail "expected two uploads, got $uploads"
printf '%s' "$uploads" | grep -q '"SUCCESS"' || fail "first upload was not processed: $uploads"
printf '%s' "$uploads" | grep -q '"FAILURE"' || fail "second upload was not a duplicate: $uploads"

trash=$(curl -s "${auth[@]}" -X PROPFIND -H 'Depth: 1' "$base/remote.php/dav/trashbin/admin/trash/")
printf '%s' "$trash" | grep -q 'e2e.pdf' || fail "trashed source is not in the Nextcloud trash bin"
code=$(curl -s -o /dev/null -w '%{http_code}' "${auth[@]}" -X PROPFIND -H 'Depth: 0' "$dav/outside.pdf")
[ "$code" = 207 ] || fail "file outside the forwarded folder was touched ($code)"

errors=$(docker exec "$nextcloud" sh -c 'grep -E "\"level\":[34]" /var/www/html/data/nextcloud.log || true')
[ -z "$errors" ] || fail "Nextcloud logged errors: $errors"

echo "e2e passed"
