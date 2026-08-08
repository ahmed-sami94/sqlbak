USE backup_app;

SET @sqlbak_users_had_role = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role'
);

ALTER TABLE users
    MODIFY COLUMN `password` VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) NULL AFTER `username`,
    ADD COLUMN IF NOT EXISTS `role` ENUM('admin','operator','viewer') NOT NULL DEFAULT 'viewer' AFTER `password`,
    ADD COLUMN IF NOT EXISTS `status` ENUM('active','suspended') NOT NULL DEFAULT 'active' AFTER `role`,
    ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD UNIQUE KEY IF NOT EXISTS users_email_uq (`email`);

ALTER TABLE users
    MODIFY COLUMN `email` VARCHAR(255) NULL;

ALTER TABLE `storage_destinations`
    MODIFY COLUMN `type` ENUM('local','ftp','sftp','dropbox','s3') NOT NULL DEFAULT 'local';

ALTER TABLE `sqlbak_mail_settings`
    ADD COLUMN IF NOT EXISTS `failure_alert_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `enabled`,
    ADD COLUMN IF NOT EXISTS `failure_recipients_json` JSON NULL AFTER `default_report_recipients_json`,
    ADD COLUMN IF NOT EXISTS `failure_subject_prefix` VARCHAR(120) NOT NULL DEFAULT 'SQLBak Alert' AFTER `failure_recipients_json`;

UPDATE `users`
SET `role` = 'admin', `status` = 'active'
WHERE @sqlbak_users_had_role = 0;

UPDATE `users` SET `status` = 'active' WHERE `status` IS NULL OR `status` = '';
UPDATE `sqlbak_mail_settings` SET `failure_recipients_json` = COALESCE(`failure_recipients_json`, `default_report_recipients_json`) WHERE `id` = 1;

