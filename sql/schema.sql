-- Nostr Map — schéma base de données
-- MySQL 8

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS profiles (
  npub                 VARCHAR(64)   PRIMARY KEY,
  slug                 VARCHAR(50)   UNIQUE NOT NULL,
  cached_name          VARCHAR(100),
  cached_avatar        VARCHAR(500),
  cached_bio           TEXT,
  cached_nip05         VARCHAR(200),
  last_fetch           DATETIME,
  last_stats_fetch     DATETIME,
  registered_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_login           DATETIME,
  status               ENUM('active','pending','banned','pending_deletion') DEFAULT 'active',
  banned_reason        TEXT,
  nostr_created_at     INT,
  nostr_followers      INT DEFAULT 0,
  nostr_posts          INT DEFAULT 0,
  deletion_requested_by INT,
  deletion_requested_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_links (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  npub            VARCHAR(64) NOT NULL,
  platform        VARCHAR(30) NOT NULL,
  display_handle  VARCHAR(100),
  url             VARCHAR(500) NOT NULL,
  challenge       VARCHAR(60) NOT NULL,
  verified        BOOLEAN DEFAULT FALSE,
  verified_at     DATETIME,
  last_check      DATETIME,
  FOREIGN KEY (npub) REFERENCES profiles(npub) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proposals (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  npub_proposed   VARCHAR(64) NOT NULL,
  proposed_by     VARCHAR(64),
  message         TEXT,
  links_json      TEXT,
  cached_name     VARCHAR(100),
  cached_avatar   VARCHAR(500),
  status          ENUM('pending','accepted','rejected') DEFAULT 'pending',
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index pour les recherches
CREATE INDEX idx_profiles_slug       ON profiles(slug);
CREATE INDEX idx_profiles_status     ON profiles(status);
CREATE INDEX idx_profiles_registered ON profiles(registered_at DESC);
CREATE INDEX idx_social_npub         ON social_links(npub);
CREATE INDEX idx_social_verified     ON social_links(verified);
