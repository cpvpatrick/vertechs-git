-- =========================================
-- PulseKit Full Database Setup (FIXED)
-- =========================================

CREATE DATABASE IF NOT EXISTS pulsekit;
USE pulsekit;

-- -----------------------------
-- USER & AUTH TABLES
-- -----------------------------

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    verification_token VARCHAR(64),
    email_verified TINYINT(1) DEFAULT 0,
    dataset_loaded TINYINT(1) DEFAULT 0,
    pipeline_executed TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(255) NOT NULL,
    user_id INT NOT NULL,
    username VARCHAR(50) NOT NULL,
    login_time DATETIME NOT NULL,
    logout_time DATETIME,
    ip_address VARCHAR(45),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_activity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(255) NOT NULL,
    user_id INT NOT NULL,
    page_name VARCHAR(100) NOT NULL,
    visited_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------
-- ANALYTICS TABLES
-- -----------------------------

CREATE TABLE IF NOT EXISTS dim_product (
  product_id INT AUTO_INCREMENT PRIMARY KEY,
  product_code VARCHAR(50) NOT NULL,
  product_description VARCHAR(255),
  vendor_product_code VARCHAR(50),
  UNIQUE KEY uq_product_code (product_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dim_store (
  store_id INT AUTO_INCREMENT PRIMARY KEY,
  store_code VARCHAR(50) NOT NULL,
  store_description VARCHAR(255),
  nestle_region VARCHAR(80),
  nestle_store_cluster VARCHAR(80),
  UNIQUE KEY uq_store_code (store_code),
  KEY idx_region (nestle_region),
  KEY idx_cluster (nestle_store_cluster)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fact_sales (
  sales_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  period DATE NOT NULL,
  product_id INT NOT NULL,
  store_id INT NOT NULL,
  units_sold_ty INT,
  units_sold_ly INT,
  net_sales_ty_exvat DECIMAL(14,2),
  net_sales_ly_exvat DECIMAL(14,2),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_sales_product 
    FOREIGN KEY (product_id) REFERENCES dim_product(product_id)
    ON DELETE CASCADE,

  CONSTRAINT fk_sales_store 
    FOREIGN KEY (store_id) REFERENCES dim_store(store_id)
    ON DELETE CASCADE,

  UNIQUE KEY uq_period_product_store (period, product_id, store_id),
  KEY idx_period (period),
  KEY idx_product (product_id),
  KEY idx_store (store_id)
) ENGINE=InnoDB;

-- -----------------------------
-- SEASONALITY TABLES
-- -----------------------------

CREATE TABLE IF NOT EXISTS seasonality_profiles (
  profile_key VARCHAR(50) PRIMARY KEY,
  profile_order INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  in_text_citation TEXT NOT NULL,
  reference_text TEXT NOT NULL,
  reference_url TEXT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_seasonality_map (
  product_id INT NOT NULL PRIMARY KEY,
  profile_key VARCHAR(50) NOT NULL,

  CONSTRAINT fk_psm_product 
    FOREIGN KEY (product_id) REFERENCES dim_product(product_id)
    ON DELETE CASCADE,

  CONSTRAINT fk_psm_profile 
    FOREIGN KEY (profile_key) REFERENCES seasonality_profiles(profile_key)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================================
-- SEED DATA
-- =========================================

-- PRODUCTS
INSERT IGNORE INTO dim_product 
(product_code, product_description, vendor_product_code) VALUES
('P001','NESTLE Milk 1L','V-P001'),
('P002','NESTLE Coffee 250g','V-P002'),
('P003','NESTLE Cereal 500g','V-P003'),
('P004','NESTLE Creamer 1kg','V-P004'),
('P005','NESTLE Snack Bars 12s','V-P005');

-- STORES
INSERT IGNORE INTO dim_store 
(store_code, store_description, nestle_region, nestle_store_cluster) VALUES
('S001','Southstar - Makati','GMA','Commercial'),
('S002','Southstar - BGC','GMA','BPO'),
('S003','Southstar - Cebu','Visayas','Mall'),
('S004','Southstar - Davao','Mindanao','Community');

-- SEASONALITY PROFILES
INSERT IGNORE INTO seasonality_profiles
(profile_key, profile_order, title, in_text_citation, reference_text, reference_url)
VALUES
('ber',1,'SEASONALITY PROFILE 1 — BER MONTHS INCREASE (SEP–DEC)',
 'Retailers experience rising sales from September to December.',
 'ABS-CBN News (2024)',
 'https://www.abs-cbn.com'),

('dec_peak',2,'SEASONALITY PROFILE 2 — DECEMBER PEAK',
 'Consumption peaks sharply in December.',
 'PIDS (2020)',
 'https://pids.gov.ph'),

('jan_drop',3,'SEASONALITY PROFILE 3 — JANUARY POST-HOLIDAY DROP',
 'Spending contracts in January.',
 'De Chavez (2019)',
 'https://www.rroij.com'),

('school',4,'SEASONALITY PROFILE 4 — SCHOOL OPENING LIFT (JUNE–JULY)',
 'Mid-year lift due to school reopening.',
 'Navarro (2024)',
 'https://ssrn.com'),

('rainy',5,'SEASONALITY PROFILE 5 — RAINY-SEASON EFFECTS',
 'Wet season affects consumption patterns.',
 'Parreño (2023)',
 'https://ictactjournals.in'),

('dry',6,'SEASONALITY PROFILE 6 — DRY-SEASON VARIATION',
 'Dry season impacts food pricing.',
 'Delima & Neri (2023)',
 'https://wjarr.com'),

('region_based',7,'SEASONALITY PROFILE 7 — REGION-BASED SEASONALITY',
 'Regional diversity affects demand cycles.',
 'Santiago (2010)',
 'https://pmr.upd.edu.ph');

-- MAP PRODUCTS TO PROFILES
INSERT IGNORE INTO product_seasonality_map (product_id, profile_key)
SELECT product_id, 'ber' FROM dim_product WHERE product_code='P001';

INSERT IGNORE INTO product_seasonality_map (product_id, profile_key)
SELECT product_id, 'dec_peak' FROM dim_product WHERE product_code='P002';

INSERT IGNORE INTO product_seasonality_map (product_id, profile_key)
SELECT product_id, 'school' FROM dim_product WHERE product_code='P003';

INSERT IGNORE INTO product_seasonality_map (product_id, profile_key)
SELECT product_id, 'rainy' FROM dim_product WHERE product_code='P004';

INSERT IGNORE INTO product_seasonality_map (product_id, profile_key)
SELECT product_id, 'jan_drop' FROM dim_product WHERE product_code='P005';

-- SALES DATA (Sample)
INSERT IGNORE INTO fact_sales 
(period, product_id, store_id, units_sold_ty, units_sold_ly, net_sales_ty_exvat, net_sales_ly_exvat)
SELECT m.period, p.product_id, s.store_id,
       FLOOR(50 + RAND()*200),
       FLOOR(40 + RAND()*180),
       ROUND(5000 + RAND()*30000, 2),
       ROUND(4500 + RAND()*28000, 2)
FROM (
  SELECT '2023-01-01' AS period UNION ALL
  SELECT '2023-02-01' UNION ALL
  SELECT '2023-03-01' UNION ALL
  SELECT '2023-04-01' UNION ALL
  SELECT '2023-05-01' UNION ALL
  SELECT '2023-06-01' UNION ALL
  SELECT '2023-07-01' UNION ALL
  SELECT '2023-08-01' UNION ALL
  SELECT '2023-09-01' UNION ALL
  SELECT '2023-10-01' UNION ALL
  SELECT '2023-11-01' UNION ALL
  SELECT '2023-12-01'
) m
CROSS JOIN dim_product p
CROSS JOIN dim_store s;
