USE backup_app;

ALTER TABLE sqlbak_mail_settings
    ADD COLUMN IF NOT EXISTS imap_host VARCHAR(255) NULL AFTER from_name,
    ADD COLUMN IF NOT EXISTS imap_port INT UNSIGNED NOT NULL DEFAULT 993 AFTER imap_host,
    ADD COLUMN IF NOT EXISTS pop3_port INT UNSIGNED NOT NULL DEFAULT 995 AFTER imap_port,
    ADD COLUMN IF NOT EXISTS default_report_recipients_json JSON NULL AFTER pop3_port;
