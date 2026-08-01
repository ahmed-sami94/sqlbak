USE backup_app;

CREATE TABLE IF NOT EXISTS backup_policy_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    database_id INT(11) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    schedule_type ENUM('interval','daily','weekly','monthly') NOT NULL DEFAULT 'interval',
    interval_minutes INT UNSIGNED NULL,
    run_time TIME NULL,
    weekday TINYINT UNSIGNED NULL,
    day_of_month TINYINT UNSIGNED NULL,
    retention_count INT UNSIGNED NOT NULL DEFAULT 24,
    next_run_at DATETIME NULL,
    last_run_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_status ENUM('never','queued','running','success','partial','failed') NOT NULL DEFAULT 'never',
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY backup_policy_rules_due_ix (enabled, next_run_at),
    KEY backup_policy_rules_database_ix (database_id),
    CONSTRAINT backup_policy_rules_database_fk FOREIGN KEY (database_id) REFERENCES `databases` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO backup_policy_rules (name, database_id, enabled, schedule_type, interval_minutes, retention_count, next_run_at, last_success_at)
SELECT CONCAT('السياسة الافتراضية - ', d.name), p.database_id, p.enabled, 'interval',
       COALESCE(p.interval_minutes, 60), COALESCE(p.retention_count, 24), p.next_run_at, p.last_success_at
FROM backup_policies p
JOIN `databases` d ON d.id = p.database_id
WHERE NOT EXISTS (SELECT 1 FROM backup_policy_rules r WHERE r.database_id = p.database_id);

ALTER TABLE backups
    MODIFY COLUMN type ENUM('manual','automatic','policy_test','pre_restore') NOT NULL DEFAULT 'manual',
    ADD COLUMN IF NOT EXISTS policy_rule_id INT UNSIGNED NULL AFTER database_id,
    ADD COLUMN IF NOT EXISTS trace_id CHAR(32) NULL AFTER policy_rule_id,
    ADD COLUMN IF NOT EXISTS scheduled_for DATETIME NULL AFTER trace_id,
    ADD KEY IF NOT EXISTS backups_policy_rule_ix (policy_rule_id),
    ADD KEY IF NOT EXISTS backups_trace_ix (trace_id);

ALTER TABLE backup_copies
    ADD COLUMN IF NOT EXISTS trace_id CHAR(32) NULL AFTER destination_id,
    ADD COLUMN IF NOT EXISTS started_at DATETIME NULL AFTER status,
    ADD COLUMN IF NOT EXISTS duration_ms INT UNSIGNED NULL AFTER completed_at,
    ADD COLUMN IF NOT EXISTS error_code VARCHAR(64) NULL AFTER error_message,
    ADD KEY IF NOT EXISTS backup_copies_trace_ix (trace_id);

ALTER TABLE storage_destinations
    ADD COLUMN IF NOT EXISTS display_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER name,
    ADD COLUMN IF NOT EXISTS health_status ENUM('unknown','success','failed','disabled') NOT NULL DEFAULT 'unknown' AFTER enabled,
    ADD COLUMN IF NOT EXISTS last_error_code VARCHAR(64) NULL AFTER last_test_message,
    ADD COLUMN IF NOT EXISTS last_latency_ms INT UNSIGNED NULL AFTER last_tested_at,
    ADD COLUMN IF NOT EXISTS last_success_at DATETIME NULL AFTER last_latency_ms,
    ADD COLUMN IF NOT EXISTS consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_success_at;

CREATE TABLE IF NOT EXISTS operation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trace_id CHAR(32) NOT NULL,
    backup_id INT(11) NULL,
    copy_id BIGINT UNSIGNED NULL,
    destination_id INT(11) NULL,
    policy_rule_id INT UNSIGNED NULL,
    event_type VARCHAR(64) NOT NULL,
    phase VARCHAR(64) NOT NULL,
    level ENUM('info','warning','error') NOT NULL DEFAULT 'info',
    error_code VARCHAR(64) NULL,
    message VARCHAR(1000) NOT NULL,
    context_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY operation_events_trace_ix (trace_id),
    KEY operation_events_backup_ix (backup_id),
    KEY operation_events_destination_ix (destination_id, created_at),
    KEY operation_events_created_ix (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS destination_health_checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    destination_id INT(11) NOT NULL,
    trace_id CHAR(32) NOT NULL,
    status ENUM('success','failed') NOT NULL,
    error_code VARCHAR(64) NULL,
    message VARCHAR(1000) NOT NULL,
    latency_ms INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY destination_health_checks_destination_ix (destination_id, created_at),
    CONSTRAINT destination_health_checks_destination_fk FOREIGN KEY (destination_id) REFERENCES storage_destinations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sqlbak_mail_settings (
    id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    smtp_host VARCHAR(255) NULL,
    smtp_port INT UNSIGNED NOT NULL DEFAULT 587,
    encryption ENUM('none','starttls','smtps') NOT NULL DEFAULT 'starttls',
    auth_enabled TINYINT(1) NOT NULL DEFAULT 1,
    smtp_username VARCHAR(255) NULL,
    smtp_password_encrypted MEDIUMTEXT NULL,
    from_email VARCHAR(255) NULL,
    from_name VARCHAR(255) NULL,
    timeout_seconds INT UNSIGNED NOT NULL DEFAULT 20,
    last_test_status ENUM('success','failed') NULL,
    last_test_message VARCHAR(1000) NULL,
    last_tested_at DATETIME NULL,
    last_latency_ms INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO sqlbak_mail_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS report_schedules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    frequency ENUM('daily','weekly','monthly') NOT NULL,
    run_time TIME NOT NULL DEFAULT '08:00:00',
    weekday TINYINT UNSIGNED NULL,
    day_of_month TINYINT UNSIGNED NULL,
    custom_recipients_json JSON NULL,
    user_ids_json JSON NULL,
    next_run_at DATETIME NULL,
    last_sent_at DATETIME NULL,
    last_status ENUM('never','queued','running','success','failed') NOT NULL DEFAULT 'never',
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY report_schedules_due_ix (enabled, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id INT UNSIGNED NULL,
    trace_id CHAR(32) NOT NULL,
    period_start DATETIME NOT NULL,
    period_end DATETIME NOT NULL,
    recipients_json JSON NOT NULL,
    status ENUM('queued','running','success','failed') NOT NULL DEFAULT 'queued',
    error_code VARCHAR(64) NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    sent_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY report_deliveries_schedule_ix (schedule_id, created_at),
    KEY report_deliveries_trace_ix (trace_id),
    CONSTRAINT report_deliveries_schedule_fk FOREIGN KEY (schedule_id) REFERENCES report_schedules (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('dashboard_refresh_seconds', '30'),
    ('health_stale_minutes', '10')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

UPDATE storage_destinations SET display_order = id WHERE display_order = 0;

ALTER TABLE backup_copies DROP FOREIGN KEY backup_copies_destination_fk;
ALTER TABLE backup_copies ADD CONSTRAINT backup_copies_destination_restrict_fk FOREIGN KEY (destination_id) REFERENCES storage_destinations (id) ON DELETE RESTRICT;
