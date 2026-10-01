CREATE TABLE IF NOT EXISTS applications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL,
 phone VARCHAR(40) NOT NULL,
 location VARCHAR(160) NOT NULL,
 organisation VARCHAR(180) NOT NULL DEFAULT '',
 interest VARCHAR(60) NOT NULL,
 expertise TEXT NOT NULL,
 motivation TEXT NOT NULL,
 consent_at DATETIME NOT NULL,
 status ENUM('new','reviewing','accepted','declined') NOT NULL DEFAULT 'new',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY application_status (status, created_at)
) ENGINE=InnoDB;
