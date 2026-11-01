# Changelog

## 1.0.5

- Use the logical Nextcloud file size for streamed multipart uploads, fixing cURL read errors with encrypted files.
- Cover inflated storage stream sizes and verify large-file upload lengths and checksums.

## 1.0.4

- Preserve scheduled forwarder job timestamps when the app bootstraps.

## 1.0.3

- Register the Nextcloud bootstrap class so file events enqueue eligible uploads.
- Assert event-driven queueing in the Nextcloud 35 end-to-end test.

## 1.0.2

- Fix Nextcloud 35 dependency injection (`IDBConnection`, `ITimeFactory`) that made WebDAV uploads fail with HTTP 500.
- Pass an `IUser` when checking whether the trash bin is enabled before trashing duplicates.
- Add a Docker end-to-end test against Nextcloud 35 and a mock Paperless API.

## 1.0.1

- Fix runtime logger injection for Nextcloud 35.
- Autoload upload rejection errors and cover forwarding/trash state transitions.

## 1.0.0

- Initial event-driven Nextcloud-to-Paperless forwarder.
