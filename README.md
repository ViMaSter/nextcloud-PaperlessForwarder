# Nextcloud Paperless Forwarder

A headless Nextcloud 35 app that forwards settled files from one configured user folder to Paperless-ngx.

## Behavior

- Nextcloud file events enqueue eligible files; a periodic sweep also discovers the existing backlog and missed events.
- The worker streams each file to `POST /api/documents/post_document/`, polls the Paperless task, then uploads it a second time.
- A confirmed duplicate is moved to the Nextcloud trash. Two upload-time HTTP 400 rejections also move non-PDF files to trash; rejected PDFs are parked for investigation.
- Processing failures, transient errors, unverifiable duplicates, and unsafe trash conditions keep the original file and are reported by `paperlessforwarder:status`.
- A second created document opens a circuit breaker and pauses forwarding.

Files must be owned by the configured user and under the configured path, be unchanged for at least two minutes, non-empty, and at most 256 MiB. Hidden and temporary files are skipped. Before trashing, the app verifies the original etag and size and requires `files_trashbin` to be enabled.

## Configuration

Set these variables on the Nextcloud app and cron services (the API token is only needed by cron):

| Variable | Purpose |
|---|---|
| `PAPERLESS_FORWARDER_MODE` | `off` (default) or `live`; forwarding also remains off without a token |
| `PAPERLESS_FORWARDER_USER` | Nextcloud user id to watch |
| `PAPERLESS_FORWARDER_PATH` | User-relative path, e.g. `/Paperless/_forwarder-test` |
| `PAPERLESS_URL` | Paperless base URL |
| `PAPERLESS_API_TOKEN` | Paperless API token; supply to cron only |

Paperless must have `PAPERLESS_CONSUMER_DELETE_DUPLICATES=true`. Keep the initial live scope narrow until the production browser canary passes.

## OCC commands

- `occ paperlessforwarder:status`
- `occ paperlessforwarder:sweep --list` (read-only eligible-file listing)
- `occ paperlessforwarder:sweep`
- `occ paperlessforwarder:process`
- `occ paperlessforwarder:retry`
- `occ paperlessforwarder:resume` (reset the circuit breaker after investigation)

## Development

Run `composer lint`, `composer test`, `npm run test:packaging`, then `npm run package -- v1.0.0`. The app release image contains the unpacked app at `/paperlessforwarder`.
