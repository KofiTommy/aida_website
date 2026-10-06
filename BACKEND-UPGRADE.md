# AIDA backend upgrade

## Deploy to Hostinger

1. Take a Hostinger database AND website-files backup before deployment.
2. Upload the changed PHP, CSS, JavaScript, SQL files, plus all new files and `.htaccess` files. Keep the live `app/config.local.php`, `storage/setup.lock`, uploads and database. Do NOT overwrite the live configuration with your local configuration.
3. Visit the admin login. The first database connection runs an additive, versioned upgrade. It creates the Applications table and security/media/Trash tables without deleting existing records. Old sessions must sign in again.
4. If the database account cannot create tables, import `database/applications.sql` and `database/backend-upgrade.sql` into the site's database through phpMyAdmin. Then run `INSERT INTO site_settings (setting_key,setting_value) VALUES ('backend_schema_version','2') ON DUPLICATE KEY UPDATE setting_value='2';`. Never rerun setup for this upgrade.
5. Confirm `storage` is writable by PHP but not browsable over HTTP. Check `/storage/php-errors.log`, `/app/config.local.php`, and a direct `/uploads/...` URL return 403. Your existing media links now use `file.php?id=...`, not direct upload paths. Old externally shared direct upload links intentionally stop working; replace them with the stable file.php link.
6. Use HTTPS for the admin area. Enable two-factor authentication under My Security for each staff account and save the single-use recovery codes offline. Use separate accounts: one administrator, editors for publishers, contributors for drafts.

## What changed

- Login throttling: five failed attempts per account or 25 per IP within 15 minutes. Account keys are hashed. Failed-attempt data expires after one day.
- Authenticator-based two-factor authentication, encrypted setup keys, replay prevention and hashed single-use recovery codes. MFA is optional until enrolled; it is not silently enabled on existing accounts. An application key of at least 32 characters is required. Keep that key unchanged and stored safely.
- Sessions: 30-minute inactivity timeout, 12-hour absolute lifetime, regenerated session IDs, no-store admin pages, secure/HttpOnly/SameSite cookies on HTTPS. Disabling an account, password resets or session revocation invalidate existing sessions on their next request. Roles/active status are rechecked on every request.
- Shared upload validation: allowlisted MIME types and matching extensions, random filenames, size limits, image dimension checks, bounded Office ZIP checks. PHP/HTML/SVG/ZIP and macro-enabled Office formats are not accepted. These checks do not guarantee files are malware-free.
- Both upload routes accept the same supported formats: JPG/JPEG, PNG, WebP, PDF, DOCX, XLSX, PPTX, MP4, WebM. Set `upload_max_mb` in the live config to the business limit, then align PHP `upload_max_filesize` and `post_max_size` in hosting settings. The displayed limit is the smaller applicable limit. Keep larger videos on a video host.
- New uploads go to `storage/media`. Web-server deny rules block direct access. The download handler authorises current published attachments for visitors, and private/history files for permitted staff. This default uses denied storage inside the site directory; moving all storage outside the web root is a hosting hardening option, not claimed as implemented. The application requires Apache/LiteSpeed-compatible .htaccess enforcement. Do not deploy it behind a server that ignores these rules without equivalent deny rules.
- Media library search, previews, drag-and-drop, progress, existing-file selectors, publication authors/topic/date, multiple supporting documents and replacement-file history with stable download URLs.
- Deletion moves content/files to Trash. Restore brings content back as a draft. A file referenced by ANY content, including Trash items, cannot be trashed. Restore the referencing content first if you need to detach it. No automatic permanent deletion is configured.
- Staff activity log, private PHP error log and friendly error references. Successful actions are audited; login failures are recorded separately for throttling. No credentials, MFA secrets or submitted document contents are written to the activity log.

## Malware scanning (requires host support)

The code supports a local ClamAV-compatible scanner. Scanning is NOT active unless the server has one and you configure it. In `app/config.local.php`, add `'malware_scanner' => '/absolute/path/to/clamscan'`. Its executable must support `--no-summary FILE`; exit code 0 means clean, 1 means infected, anything else fails closed. The host must allow PHP `proc_open` and maintain current malware signatures. If unavailable on shared hosting, ask the hosting provider about scanning or use a managed scanning service after considering document privacy. Files are never automatically sent to a third-party service.

## Backups

- Under Backups, an administrator can create and download a signed ZIP containing the database and every media-file version. Includes private submissions and password hashes: keep downloads encrypted and access-restricted. It excludes `config.local.php`; save that configuration/application key separately and privately.
- Backups are stored in `storage/backups` (blocked over HTTP). Only an authenticated administrator can download them. File content is hashed; a signed manifest allows corruption/tampering checks. The archive itself is NOT encrypted. Restrict filesystem access and keep off-server copies.
- PHP ZIP is required. Application backups have a 1 GB total media limit and may be constrained by hosting time/memory limits. For larger libraries use hosting-managed backups. Do not rely on a single local backup location.
- To schedule daily backups in Hostinger, configure a cron job using the host's PHP CLI binary and actual account path, for example:

  `php /absolute/path/to/public_html/scripts/backup.php`

  Schedule it outside busy periods and check its exit status/output. This code does not create the Hostinger cron job on your behalf. Retention is manual: keep an off-server copy before removing old archives through the hosting file manager. Monitor disk usage.

## Recovery rehearsal (safe, separate target)

1. Create an EMPTY recovery database, grant your database user access, and create an EMPTY directory for recovered files. The restore utility deliberately refuses the configured live database and nonempty targets.
2. With the ORIGINAL application key/config available, run:

   `php scripts/restore.php --archive=/absolute/path/aida-backup.zip --database=EMPTY_RECOVERY_DB --files=/absolute/path/EMPTY_RECOVERY_FOLDER --confirm`

3. The tool verifies the archive signature and every file checksum, restores tables/data and writes recovered `uploads/` and `storage/media/` files under the separate target folder. On failure, partial tables/files may remain in that isolated target: inspect it; don't retry against a nonempty directory/database. Live data is not overwritten.
4. In a staging copy of the website, use the recovery database and recovered media tree with the original key. Verify login, MFA, submissions, content, file versions, downloads and Trash before any planned production switchover.
5. Production restoration/swapping requires a separate controlled deployment with a fresh live backup; the website has no browser-based destructive restore button.

## If someone loses MFA access

Use a saved single-use recovery code. A website administrator cannot view another person's secret. If all codes are lost, the hosting owner can carefully remove ONLY that account's MFA record fields through phpMyAdmin after verifying identity and taking a backup. Increment that account's `session_version` to revoke old sessions. Do not share accounts or disable MFA globally.

## Developer verification

Run `php tests/backend.php` locally with a MySQL account allowed to create/drop databases and PHP cURL/ZIP/OpenSSL enabled. It creates randomly named, isolated `aida_test_...` databases and a loopback-only PHP server, signs in real test accounts through the regular login page, exercises the upload/access/MFA/Trash flows, rehearses backup restoration, and cleans up only its own test records/files/databases. It does not alter production records. Tests depend on the supplied `Logo.png` and `AIDA Profile.pdf` fixture files. Production must deny HTTP access to `tests/` and `scripts/`.
