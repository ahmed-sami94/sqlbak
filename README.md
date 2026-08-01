# SQLBak

SQLBak is a self-hosted PHP and MariaDB application for scheduling, running, storing, and restoring database backups from one focused operations console.

## Features

- Create and manage backup source databases.
- Run manual or scheduled backups with retention policies.
- Track backup versions, sizes, statuses, and destination health.
- Store backups locally or send them to configured remote storage.
- Restore complete databases or uploaded SQL backup files.
- Configure encrypted storage credentials and email reports through environment-backed settings.
- Review audit events, operational logs, and daily backup summaries.
- Use CSRF protection, session-based authentication, role checks, and prepared SQL statements.

## Configuration

Runtime configuration is supplied through environment variables; no customer credentials or production connection details belong in the repository. Start with the deployment files under `deploy/` and provide a unique `SQLBAK_APP_KEY` before enabling encrypted storage settings.

The SQL schema in `sql/backup_app.sql` is intentionally data-free. Create the first administrator and configure destinations through the deployment process for each environment.

## Project layout

- `lib/` — shared database, authentication, backup, storage, policy, and reporting services.
- `cli/` and `cron/` — worker commands for scheduled operations.
- `migrations/` — schema and feature migrations.
- `deploy/` — deployment examples.
- `styles/`, `assets/`, and `images/` — the web console UI.

## Security and privacy

Do not commit `.env` files, SQL dumps, backup archives, logs, restore histories, or exported conversations. The repository ignores these artifacts and contains no customer seed data.

## License

Add the project license before public distribution.
