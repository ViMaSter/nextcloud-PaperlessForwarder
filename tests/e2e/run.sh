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
	if [ -n "${pdf:-}" ]; then
		rm -f "$pdf"
	fi
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

wait_for_trash() {
	local filename="$1" output code
	for _ in $(seq 1 30); do
		output=$(occ paperlessforwarder:process 2>&1) || fail "paperlessforwarder:process failed: $output"
		code=$(curl -s -o /dev/null -w '%{http_code}' "${auth[@]}" -X PROPFIND -H 'Depth: 0' "$dav/Paperless/$filename")
		[ "$code" = 404 ] && return
		sleep 5
	done
	occ paperlessforwarder:status
	fail "duplicate source $filename was not trashed"
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

status=$(occ paperlessforwarder:status)
printf '%s\n' "$status" | grep -Fq '/Paperless/e2e.pdf' || {
	printf '%s\n' "$status"
	fail "Nextcloud file event did not queue the eligible file"
}

output=$(docker exec -u www-data "$nextcloud" php -f /var/www/html/cron.php 2>&1) || fail "cron.php failed: $output"

wait_for_trash e2e.pdf

uploads=$(docker exec "$paperless" wget -qO- http://127.0.0.1:8000/uploads)
[ "$(printf '%s' "$uploads" | grep -o '"status"' | wc -l | tr -d ' ')" = 2 ] || fail "expected two uploads, got $uploads"
printf '%s' "$uploads" | grep -q '"SUCCESS"' || fail "first upload was not processed: $uploads"
printf '%s' "$uploads" | grep -q '"FAILURE"' || fail "second upload was not a duplicate: $uploads"

trash=$(curl -s "${auth[@]}" -X PROPFIND -H 'Depth: 1' "$base/remote.php/dav/trashbin/admin/trash/")
printf '%s' "$trash" | grep -q 'e2e.pdf' || fail "trashed source is not in the Nextcloud trash bin"
code=$(curl -s -o /dev/null -w '%{http_code}' "${auth[@]}" -X PROPFIND -H 'Depth: 0' "$dav/outside.pdf")
[ "$code" = 207 ] || fail "file outside the forwarded folder was touched ($code)"

pdf="$(mktemp)"
printf '%%PDF-1.4\n%% large e2e\n' > "$pdf"
head -c 2097152 /dev/zero >> "$pdf"
printf '\n%%%%EOF\n' >> "$pdf"
code=$(curl -s -o /dev/null -w '%{http_code}' "${auth[@]}" -X PUT -H "X-OC-Mtime: $old_mtime" --data-binary "@$pdf" "$dav/Paperless/large.pdf")
[ "$code" = 201 ] || fail "large upload returned $code"

wait_for_trash large.pdf
docker exec -i "$paperless" python -c '
import hashlib, json, sys, urllib.request
content = sys.stdin.buffer.read()
with urllib.request.urlopen("http://127.0.0.1:8000/uploads") as response:
    uploads = json.load(response)
assert len(uploads) == 4, uploads
assert [upload["status"] for upload in uploads[-2:]] == ["SUCCESS", "FAILURE"], uploads
for upload in uploads[-2:]:
    assert upload["content_length"] == upload["body_size"], upload
    assert upload["size"] == len(content), upload
    assert upload["sha256"] == hashlib.sha256(content).hexdigest(), upload
' < "$pdf"
rm -f "$pdf"

trash=$(curl -s "${auth[@]}" -X PROPFIND -H 'Depth: 1' "$base/remote.php/dav/trashbin/admin/trash/")
printf '%s' "$trash" | grep -q 'large.pdf' || fail "large source is not in the Nextcloud trash bin"

process_job_id=$(occ background-job:list | awk -F'|' '/ProcessJob/ { gsub(/[[:space:]]/, "", $2); id = $2 } END { print id }')
sweep_job_id=$(occ background-job:list | awk -F'|' '/SweepJob/ { gsub(/[[:space:]]/, "", $2); id = $2 } END { print id }')
[ -n "$process_job_id" ] || fail "process background job is not registered"
[ -n "$sweep_job_id" ] || fail "sweep background job is not registered"
occ background-job:execute --force-execute "$process_job_id" >/dev/null
occ background-job:execute --force-execute "$sweep_job_id" >/dev/null
occ paperlessforwarder:status >/dev/null
jobs=$(occ background-job:list)
process_last_run=$(printf '%s\n' "$jobs" | awk -F'|' '/ProcessJob/ { gsub(/^[[:space:]]+|[[:space:]]+$/, "", $4); last_run = $4 } END { print last_run }')
sweep_last_run=$(printf '%s\n' "$jobs" | awk -F'|' '/SweepJob/ { gsub(/^[[:space:]]+|[[:space:]]+$/, "", $4); last_run = $4 } END { print last_run }')
[ "$process_last_run" != "1970-01-01T00:00:00+00:00" ] || fail "app bootstrap reset the process job run time"
[ "$sweep_last_run" != "1970-01-01T00:00:00+00:00" ] || fail "app bootstrap reset the sweep job run time"

errors=$(docker exec "$nextcloud" sh -c 'grep -E "\"level\":[34]" /var/www/html/data/nextcloud.log || true')
[ -z "$errors" ] || fail "Nextcloud logged errors: $errors"

echo "e2e passed"
