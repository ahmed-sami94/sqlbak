# SQLBak
<img width="2957" height="1676" alt="image" src="https://github.com/user-attachments/assets/edc92fcd-3c46-4a1d-ba94-fbcc9f1aa1d4" />

SQLBak is a self-hosted PHP + MariaDB dashboard for database backup operations with scheduling, storage management, restore tools, monitoring, and reporting.

## What’s new in this release

- Added multi-user management with role and status handling:
  - `admin` (full system access)
  - `operator` (backups, restore, destinations)
  - `viewer` (read-only)
- Added user status support: `active`, `suspended`.
- Added destination support:
  - Local
  - FTP / FTPS
  - SFTP
  - **S3-compatible object storage** (AWS, Azure Blob via S3-compatible endpoints, Huawei, Alibaba, OCI, etc.)
  - **Dropbox**
- Added destination health checks and copy/download/delete probes for all supported destination types.
- Added failure email alerts for backup job and destination-copy failures.
- Added clean install flow and admin bootstrap flow.
- Added migration support under `/migrations` for evolving the schema safely.
- Added role-aware permissions across pages and operations.

## Install flow (required)

1. Install and start MySQL / MariaDB on the server.
2. Open `install.php`.
3. Provide:
   - MySQL admin credentials
   - target application database credentials
   - backup root path
   - first admin user
4. The installer will:
   - create/connect the application database
   - apply `sql/backup_app.sql`
   - apply all scripts in `migrations/*.sql`
   - create the first admin user
   - write `.env`
5. Open `login.php` and sign in with the new admin account.

## New destination configuration

- `storage.php` supports:
  - Local path
  - FTP
  - SFTP
  - S3-compatible storage (access/secret key, endpoint, region, bucket)
  - Dropbox (API token, app key, app secret, folder)
- Destination health tests are available for every configured type.
- Restores and manual backups can target configured destinations directly.

## Mail reporting

- Report email and failure alert settings are controlled from `settings.php`.
- Configure SMTP and recipients to receive:
  - backup run reports
  - failure alerts when copies or restore/download steps fail.

## Security and privacy

- Do not commit `.env` files.
- No customer credentials are included in this repository.
- Database schema in `sql/backup_app.sql` is intentionally data-free.
- Restore logs, chat exports, backup archives, and operational logs are ignored by `.gitignore`.

## Environment configuration

Runtime values are loaded from `.env`:

```bash
SQLBAK_DB_HOST=...
SQLBAK_DB_PORT=3306
SQLBAK_DB_NAME=backup_app
SQLBAK_DB_USER=backup
SQLBAK_DB_PASS=...
SQLBAK_APP_KEY=<random 32-byte key>
SQLBAK_BACKUP_ROOT=/var/backups/sqlbak
```

## Project structure

- `lib/` shared services (auth, backup engine, storage, mail, policy, reporting)
- `cron/` and `cli/` scheduled jobs
- `migrations/` schema migrations
- `sql/` base schema
- `assets/`, `styles/`, `images/` UI resources

## Data model changes in this version

- `users` now includes `role`, `status`, `email`, and timestamps.
- `storage_destinations.type` includes `s3` and `dropbox`.

## Migration note

If you are upgrading from an earlier SQLBak release, run all migration files in order after restoring schema:

```bash
ls -1 migrations/*.sql | sort
```
