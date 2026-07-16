-- Test Schema for ScenarioLoader Integration Tests
-- Target: SQLite (in-memory)

CREATE TABLE groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created DATETIME,
    modified DATETIME
);

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER,
    username TEXT NOT NULL,
    email TEXT NOT NULL,
    birthday DATE,
    created DATETIME,
    modified DATETIME,
    FOREIGN KEY (group_id) REFERENCES groups(id)
);

CREATE TABLE profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    bio TEXT,
    address TEXT,
    created DATETIME,
    modified DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE shops (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    shop_name TEXT NOT NULL,
    location TEXT,
    opened_at DATETIME,
    created DATETIME,
    modified DATETIME
);

CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_code TEXT NOT NULL,
    name TEXT NOT NULL,
    base_price INTEGER,
    created DATETIME,
    modified DATETIME
);

CREATE TABLE shop_products (
    shop_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    sale_price INTEGER,
    start_time DATETIME,
    is_active INTEGER DEFAULT 1,
    meta_json TEXT,
    created DATETIME,
    modified DATETIME,
    PRIMARY KEY (shop_id, product_id),
    FOREIGN KEY (shop_id) REFERENCES shops(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- AuditLog テスト用テーブル（AuditLogWriter/AuditLogBehavior/AuditLogComponent/AuditLogPurgeService 共通）
CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    category VARCHAR(50) NOT NULL,
    action VARCHAR(50) NOT NULL,
    target_id VARCHAR(50) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    context TEXT NULL,
    created DATETIME NOT NULL
);

-- Behaviorテスト用テーブル（auditLogSanitizeコールバックのテストを含む）
CREATE TABLE test_articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    author VARCHAR(100) NULL,
    email VARCHAR(255) NULL,
    password VARCHAR(255) NULL,
    created DATETIME NOT NULL,
    modified DATETIME NOT NULL
);

