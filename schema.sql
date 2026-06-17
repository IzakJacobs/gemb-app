-- GEMB Estate Access System — Database Schema
-- Run this in cPanel → phpMyAdmin on the visitor_system database.
-- Safe to run on an existing database: uses IF NOT EXISTS / MODIFY.

-- -------------------------------------------------------
-- users (residents)
-- Migrates the existing table; pin column kept but unused
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100)  NOT NULL DEFAULT '',
    unit       VARCHAR(20)   NOT NULL DEFAULT '',
    email      VARCHAR(255)  NOT NULL UNIQUE,
    pin_hash   VARCHAR(255)  NOT NULL DEFAULT '',
    status     ENUM('pending','approved') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add new columns to existing table if upgrading
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS name       VARCHAR(100) NOT NULL DEFAULT '' AFTER id,
    ADD COLUMN IF NOT EXISTS unit       VARCHAR(20)  NOT NULL DEFAULT '' AFTER name,
    ADD COLUMN IF NOT EXISTS pin_hash   VARCHAR(255) NOT NULL DEFAULT '' AFTER email,
    ADD COLUMN IF NOT EXISTS status     ENUM('pending','approved') NOT NULL DEFAULT 'pending' AFTER pin_hash,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- -------------------------------------------------------
-- admins
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(255) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- invitations  (created by residents)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS invitations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    visitor_name    VARCHAR(100) NOT NULL,
    plate           VARCHAR(20)  NOT NULL,
    idnum           VARCHAR(20)  NOT NULL,
    visit_date      DATE         NOT NULL,
    invited_by      INT          NOT NULL,
    invited_by_name VARCHAR(100) NOT NULL,
    unit            VARCHAR(20)  NOT NULL,
    status          ENUM('pending','granted','denied') NOT NULL DEFAULT 'pending',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- access_log  (entries logged by security guard)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS access_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    visitor_name    VARCHAR(100) NOT NULL,
    plate           VARCHAR(20)  NOT NULL,
    idnum           VARCHAR(20)  NOT NULL DEFAULT '',
    visit_date      DATE,
    invited_by_name VARCHAR(100) NOT NULL DEFAULT '',
    unit            VARCHAR(20)  NOT NULL DEFAULT '',
    action          ENUM('granted','denied') NOT NULL,
    source          ENUM('qr','manual') NOT NULL DEFAULT 'qr',
    verify_state    VARCHAR(20)  NOT NULL DEFAULT '',
    invitation_id   INT DEFAULT NULL,
    logged_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- login_attempts  (rate-limiting / lockout)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    identifier   VARCHAR(255) NOT NULL UNIQUE,
    attempts     INT          NOT NULL DEFAULT 0,
    locked_until DATETIME     DEFAULT NULL,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- settings  (key-value store for hmac_secret, guard_hash, etc.)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    `key`      VARCHAR(50) PRIMARY KEY,
    value      TEXT        NOT NULL,
    updated_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
