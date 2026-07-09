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

