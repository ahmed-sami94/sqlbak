USE backup_app;
START TRANSACTION;

ALTER TABLE `databases`
    ADD COLUMN `password_encrypted` MEDIUMTEXT NULL AFTER `password`,
    ADD COLUMN `enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `password_encrypted`,
    ADD COLUMN `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `databases` MODIFY `password` VARCHAR(255) NULL;

ALTER TABLE `backups`
    ADD COLUMN `status` ENUM('queued','running','success','partial','failed') NOT NULL DEFAULT 'success' AFTER `type`,
    ADD COLUMN `size_bytes` BIGINT UNSIGNED NULL AFTER `note`,
    ADD COLUMN `checksum_sha256` CHAR(64) NULL AFTER `size_bytes`,
    ADD COLUMN `started_at` DATETIME NULL AFTER `created_at`,
    ADD COLUMN `completed_at` DATETIME NULL AFTER `started_at`,
    ADD COLUMN `error_message` TEXT NULL AFTER `completed_at`;

CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
    ('default_interval_minutes', '60'),
    ('default_retention_count', '24')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

CREATE TABLE IF NOT EXISTS `backup_policies` (
    `database_id` INT(11) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `interval_minutes` INT UNSIGNED NULL,
    `retention_count` INT UNSIGNED NULL,
    `next_run_at` DATETIME NULL,
    `last_success_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`database_id`),
    CONSTRAINT `backup_policies_database_fk` FOREIGN KEY (`database_id`) REFERENCES `databases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `storage_destinations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('local','ftp','sftp') NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `host` VARCHAR(255) NULL,
    `port` INT UNSIGNED NULL,
    `username` VARCHAR(255) NULL,
    `base_path` VARCHAR(1024) NOT NULL,
    `options_json` JSON NULL,
    `secret_encrypted` MEDIUMTEXT NULL,
    `last_test_status` ENUM('success','failed') NULL,
    `last_test_message` VARCHAR(500) NULL,
    `last_tested_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `storage_destinations_name_uq` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `database_storage_destinations` (
    `database_id` INT(11) NOT NULL,
    `destination_id` INT(11) NOT NULL,
    PRIMARY KEY (`database_id`, `destination_id`),
    CONSTRAINT `database_storage_database_fk` FOREIGN KEY (`database_id`) REFERENCES `databases` (`id`) ON DELETE CASCADE,
    CONSTRAINT `database_storage_destination_fk` FOREIGN KEY (`destination_id`) REFERENCES `storage_destinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `backup_copies` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `backup_id` INT(11) NOT NULL,
    `destination_id` INT(11) NOT NULL,
    `relative_path` VARCHAR(1024) NULL,
    `status` ENUM('queued','running','success','failed','deleted') NOT NULL DEFAULT 'queued',
    `size_bytes` BIGINT UNSIGNED NULL,
    `checksum_sha256` CHAR(64) NULL,
    `error_message` TEXT NULL,
    `completed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `backup_copies_backup_destination_uq` (`backup_id`, `destination_id`),
    KEY `backup_copies_destination_status_ix` (`destination_id`, `status`),
    CONSTRAINT `backup_copies_backup_fk` FOREIGN KEY (`backup_id`) REFERENCES `backups` (`id`) ON DELETE CASCADE,
    CONSTRAINT `backup_copies_destination_fk` FOREIGN KEY (`destination_id`) REFERENCES `storage_destinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NULL,
    `event_name` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` VARCHAR(64) NULL,
    `details_json` JSON NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `audit_log_created_at_ix` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE `backups`
SET `type` = 'automatic', `status` = 'success', `completed_at` = COALESCE(`completed_at`, `created_at`)
WHERE `note` = 'auto backup';

INSERT INTO `backup_policies` (`database_id`, `enabled`, `next_run_at`)
SELECT `id`, 1, DATE_ADD(DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), INTERVAL 1 HOUR)
FROM `databases`
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

INSERT INTO `storage_destinations` (`name`, `type`, `enabled`, `base_path`, `options_json`)
VALUES ('التخزين المحلي الرئيسي', 'local', 1, '/var/backups/sqlbak', JSON_OBJECT('passive', true, 'tls', false))
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

INSERT IGNORE INTO `database_storage_destinations` (`database_id`, `destination_id`)
SELECT d.id, s.id
FROM `databases` d
JOIN `storage_destinations` s ON s.name = 'التخزين المحلي الرئيسي';

COMMIT;
