-- Additive upgrade: existing records are preserved.
CREATE TABLE IF NOT EXISTS user_security (
 user_id INT UNSIGNED PRIMARY KEY, session_version INT UNSIGNED NOT NULL DEFAULT 1,
 totp_secret TEXT NULL, recovery_codes TEXT NULL, last_counter BIGINT NOT NULL DEFAULT -1,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, account_key CHAR(64) NOT NULL,
 ip_address VARCHAR(45) NOT NULL, attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY account_attempts (account_key,attempted_at), KEY ip_attempts (ip_address,attempted_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS content_state (
 content_id INT UNSIGNED PRIMARY KEY, deleted_at DATETIME NULL, previous_status VARCHAR(20) NULL,
 FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS content_metadata (
 content_id INT UNSIGNED PRIMARY KEY, authors VARCHAR(255) NOT NULL DEFAULT '',
 category VARCHAR(120) NOT NULL DEFAULT '', document_date DATE NULL,
 FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS content_documents (
 content_id INT UNSIGNED NOT NULL, media_id INT UNSIGNED NOT NULL, PRIMARY KEY (content_id,media_id),
 FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE,
 FOREIGN KEY (media_id) REFERENCES media(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS media_state (
 media_id INT UNSIGNED PRIMARY KEY, deleted_at DATETIME NULL,
 FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS media_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, media_id INT UNSIGNED NOT NULL,
 original_name VARCHAR(255) NOT NULL, file_path VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL,
 file_size INT UNSIGNED NOT NULL, sha256 CHAR(64) NULL, uploaded_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY media_history (media_id,id),
 FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE,
 FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;
