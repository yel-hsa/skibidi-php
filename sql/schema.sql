-- SKIBIDI — schema + least-privilege user + seed data (local dev).
-- Doc-root = project root; serve with:  php -S 0.0.0.0:8080 -t .   then open /app/index.php
-- On InfinityFree: DROP the two CREATE USER / GRANT blocks (the panel gives you a DB
-- + user), rename the DB to the panel's if0_XXXX name, and import via phpMyAdmin.
-- After importing, run once:  php sql/seed_hashes.php   (fills bcrypt password_hash).

CREATE DATABASE IF NOT EXISTS skibidi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE skibidi;

DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS newsletter;
DROP TABLE IF EXISTS reviews;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  email          VARCHAR(190) NOT NULL UNIQUE,
  password       VARCHAR(190) NULL,          -- PLAINTEXT on purpose (vulnerable login path)
  password_hash  VARCHAR(255) NULL,          -- bcrypt, filled by sql/seed_hashes.php
  role           ENUM('buyer','admin') NOT NULL DEFAULT 'buyer',
  name           VARCHAR(120) NOT NULL DEFAULT '',
  phone          VARCHAR(40)  NOT NULL DEFAULT '',
  address        VARCHAR(190) NOT NULL DEFAULT '',
  city           VARCHAR(80)  NOT NULL DEFAULT '',
  province       VARCHAR(80)  NOT NULL DEFAULT '',
  postal_code    VARCHAR(20)  NOT NULL DEFAULT '',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE categories (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80) NOT NULL,
  slug        VARCHAR(80) NOT NULL UNIQUE,
  description VARCHAR(190) NOT NULL DEFAULT '',
  image_url   VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

CREATE TABLE products (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(160) NOT NULL,
  description  TEXT NULL,
  price        DECIMAL(10,2) NOT NULL DEFAULT 0,
  stock        INT NOT NULL DEFAULT 0,
  category_id  INT NULL,
  image_url    VARCHAR(255) NOT NULL DEFAULT '',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  INDEX (category_id)
) ENGINE=InnoDB;
CREATE TABLE orders (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  order_ref      VARCHAR(40) NOT NULL,
  user_id        INT NULL,
  status         VARCHAR(20) NOT NULL DEFAULT 'Pending',
  customer_name  VARCHAR(120) NOT NULL DEFAULT '',
  customer_email VARCHAR(190) NOT NULL DEFAULT '',
  phone          VARCHAR(40)  NOT NULL DEFAULT '',
  ship_address   VARCHAR(190) NOT NULL DEFAULT '',
  ship_city      VARCHAR(80)  NOT NULL DEFAULT '',
  ship_province  VARCHAR(80)  NOT NULL DEFAULT '',
  ship_postal    VARCHAR(20)  NOT NULL DEFAULT '',
  subtotal       DECIMAL(10,2) NOT NULL DEFAULT 0,
  shipping       DECIMAL(10,2) NOT NULL DEFAULT 0,
  total          DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_method VARCHAR(40) NOT NULL DEFAULT 'Cash on Delivery',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX (user_id)
) ENGINE=InnoDB;

CREATE TABLE order_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  order_id    INT NOT NULL,
  product_id  INT NULL,
  name        VARCHAR(160) NOT NULL DEFAULT '',
  price       DECIMAL(10,2) NOT NULL DEFAULT 0,
  quantity    INT NOT NULL DEFAULT 1,
  line_total  DECIMAL(10,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX (order_id)
) ENGINE=InnoDB;

CREATE TABLE reviews (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT NOT NULL,
  user_id     INT NULL,
  author_name VARCHAR(120) NOT NULL DEFAULT '',
  rating      TINYINT NOT NULL DEFAULT 5,
  body        TEXT NOT NULL,              -- rendered RAW in vulnerable mode (A3 Stored XSS)
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  INDEX (product_id)
) ENGINE=InnoDB;

CREATE TABLE newsletter (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(190) NOT NULL DEFAULT '',
  ip         VARCHAR(45)  NOT NULL DEFAULT '',
  success    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (email, created_at),
  INDEX (ip, created_at)
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NULL,
  action     VARCHAR(80)  NOT NULL DEFAULT '',
  detail     VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
-- ---- Least-privilege DB user (DML only; no DDL => the app can't drop its tables).
-- ---- DELETE these four statements when importing on InfinityFree.
CREATE USER IF NOT EXISTS 'skibidi'@'localhost' IDENTIFIED BY 'skibidi123';
CREATE USER IF NOT EXISTS 'skibidi'@'127.0.0.1' IDENTIFIED BY 'skibidi123';
GRANT SELECT, INSERT, UPDATE, DELETE ON skibidi.* TO 'skibidi'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON skibidi.* TO 'skibidi'@'127.0.0.1';
FLUSH PRIVILEGES;

-- The five categories of the original storefront, in display order (id 1..5).
INSERT INTO categories (name, slug, description, image_url) VALUES
 ('Bath & Body',   'bath-body',    'Soap, body wash, lotion and deodorant.',           '../assets/img/categories/bath-body.svg'),
 ('Hair Care',     'hair-care',    'Shampoo, conditioner and hair oil.',               '../assets/img/categories/hair-care.svg'),
 ('Oral Care',     'oral-care',    'Toothpaste, toothbrush and mouthwash.',            '../assets/img/categories/oral-care.svg'),
 ('Skin Care',     'skin-care',    'Facial wash, moisturizer and sunscreen.',          '../assets/img/categories/skin-care.svg'),
 ('Personal Care', 'personal-care','Cotton pads, tissues, wipes and similar products.','../assets/img/categories/personal-care.svg');

-- Plaintext passwords are rockyou-style ON PURPOSE (vulnerable login path + brute-force
-- target). password_hash is filled afterward by `php sql/seed_hashes.php`.
INSERT INTO users (email, password, password_hash, role, name, phone) VALUES
 ('admin@skibidi.test','admin123',   NULL,'admin','Site Admin','09170000001'),
 ('maya@skibidi.test', 'password123',NULL,'buyer','Maya Cruz', '09170000002'),
 ('jay@skibidi.test',  'letmein',    NULL,'buyer','Jay Reyes', '09170000003'),
 ('kai@skibidi.test',  'qwerty123',  NULL,'buyer','Kai Santos','09170000004'),
 ('tone@skibidi.test', 'sunshine',   NULL,'buyer','Tone Lim',  '09170000005');

INSERT INTO products (name, description, price, stock, category_id, image_url) VALUES
 ('SPF Daily Moisturizer','Broad-spectrum SPF 40 in a moisturiser that behaves like skincare, not sunscreen. No white cast, no heaviness - a matte, comfortable base for the whole day.',720.00,0,4,'../assets/img/products/spf-daily-moisturizer.svg'),
 ('Gentle Facial Cleanser','A pH-balanced cleanser for morning and evening use. It removes sunscreen and daily grime while respecting the skin barrier, leaving a smooth, unstripped surface ready for the rest of your routine.',480.00,18,4,'../assets/img/products/gentle-facial-cleanser.svg'),
 ('Nourishing Conditioner','A light conditioning cream that detangles without weighing hair down. Rice protein and jojoba smooth the cuticle for softness and a natural, low-shine finish.',395.00,21,2,'../assets/img/products/nourishing-conditioner.svg'),
 ('Daily Balance Shampoo','A weightless daily cleanser that lifts away build-up without stripping the scalp. Formulated with rice water and aloe for balance, movement and a clean, quiet finish. Suitable for everyday use on all hair types.',380.00,24,2,'../assets/img/products/daily-balance-shampoo.svg'),
 ('Hydrating Body Wash','A softening gel-to-cream wash that leaves skin comfortable rather than tight. Glycerin and oat extract hold moisture in place, while a low, clean lather rinses away completely.',340.00,32,1,'../assets/img/products/hydrating-body-wash.svg'),
 ('Soft Touch Hand Lotion','A fast-absorbing lotion for hands that are washed often. Shea butter and panthenol settle in quickly, so you can go straight back to work without any residue.',260.00,4,1,'../assets/img/products/soft-touch-hand-lotion.svg'),
 ('Daily Defense Deodorant','An aluminium-free stick that keeps up with a full day in a warm climate. Mineral salts and bamboo powder manage odour and moisture with a soft, clean scent.',220.00,41,5,'../assets/img/products/daily-defense-deodorant.svg'),
 ('Fresh Mint Toothpaste','A fluoride paste with a restrained peppermint finish - fresh, never harsh. Gentle polishing agents help lift surface stains from everyday coffee and tea.',165.00,60,3,'../assets/img/products/fresh-mint-toothpaste.svg');

INSERT INTO reviews (product_id, user_id, author_name, rating, body) VALUES
 (2, 2, 'Maya Cruz', 5, 'Gentle and effective, my skin feels great.'),
 (4, 3, 'Jay Reyes', 4, 'Good everyday shampoo, nice light scent.'),
 (8, 4, 'Kai Santos',5, 'Keeps my breath fresh all morning.');

SELECT 'users' AS tbl, COUNT(*) AS n FROM users
UNION ALL SELECT 'products',   COUNT(*) FROM products
UNION ALL SELECT 'categories', COUNT(*) FROM categories
UNION ALL SELECT 'reviews',    COUNT(*) FROM reviews;


