-- Migration : système d'administration Nostr Map
-- À exécuter une seule fois sur la base existante

CREATE TABLE IF NOT EXISTS admin_users (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(50)  UNIQUE NOT NULL,
  password     VARCHAR(255) NOT NULL,          -- bcrypt
  role         ENUM('admin','modo') DEFAULT 'modo',
  email        VARCHAR(200),
  active       BOOLEAN DEFAULT TRUE,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_login   DATETIME,
  created_by   INT,
  FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_activity (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  admin_id     INT NOT NULL,
  action       VARCHAR(100) NOT NULL,
  target_type  VARCHAR(30),           -- 'profile', 'link', 'proposal', 'admin_user'
  target_id    VARCHAR(100),
  details      TEXT,
  ip           VARCHAR(45),
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_activity_admin    ON admin_activity(admin_id);
CREATE INDEX idx_activity_created  ON admin_activity(created_at DESC);

-- Colonnes TOTP pour admin_users
-- Le secret TOTP est stocke chiffre, donc il faut une colonne plus large qu'un
-- secret Base32 brut.
-- ALTER TABLE admin_users ADD COLUMN totp_secret VARCHAR(255) DEFAULT NULL;
-- ALTER TABLE admin_users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0;
-- ALTER TABLE admin_users MODIFY COLUMN totp_secret VARCHAR(255) DEFAULT NULL;

-- Messages de contact utilisateurs
CREATE TABLE IF NOT EXISTS contact_messages (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  npub         VARCHAR(64) NOT NULL,
  cached_name  VARCHAR(100),
  motif        VARCHAR(50) NOT NULL,
  message      TEXT NOT NULL,
  status       ENUM('new','read','resolved') DEFAULT 'new',
  admin_note   TEXT,
  reviewed_by  INT,
  reviewed_at  DATETIME,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_contact_status ON contact_messages(status);
CREATE INDEX idx_contact_npub   ON contact_messages(npub);
