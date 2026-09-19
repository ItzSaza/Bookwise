-- Bookwise Inventory schema
-- MySQL 8.0+

CREATE DATABASE IF NOT EXISTS bookwise
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE bookwise;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(120) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    email VARCHAR(150) NULL,
    address VARCHAR(255) NULL,
    category VARCHAR(100) NOT NULL,
    payment_terms VARCHAR(50) NOT NULL DEFAULT 'Cash on Delivery',
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suppliers_name (name),
    INDEX idx_suppliers_status (status),
    INDEX idx_suppliers_category (category)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(40) NOT NULL,
    name VARCHAR(180) NOT NULL,
    author VARCHAR(150) NULL,
    isbn_barcode VARCHAR(50) NULL,
    category_id INT UNSIGNED NOT NULL,
    unit_price DECIMAL(12, 2) NOT NULL,
    stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    reorder_level INT UNSIGNED NOT NULL DEFAULT 0,
    primary_supplier_id INT UNSIGNED NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_products_sku (sku),
    UNIQUE KEY uq_products_isbn_barcode (isbn_barcode),
    INDEX idx_products_name (name),
    INDEX idx_products_category (category_id),
    INDEX idx_products_stock (stock_quantity, reorder_level),
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_products_supplier
        FOREIGN KEY (primary_supplier_id) REFERENCES suppliers (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT chk_products_unit_price CHECK (unit_price >= 0),
    CONSTRAINT chk_products_stock_quantity CHECK (stock_quantity >= 0),
    CONSTRAINT chk_products_reorder_level CHECK (reorder_level >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_ref VARCHAR(30) NOT NULL UNIQUE,
    customer_name VARCHAR(150) NOT NULL DEFAULT 'Walk-in Customer',
    payment_method ENUM('Cash', 'Card') NOT NULL,
    subtotal DECIMAL(12, 2) NOT NULL,
    discount_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12, 2) NOT NULL,
    status ENUM('Paid', 'Pending', 'Refunded') NOT NULL DEFAULT 'Paid',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sales_created_at (created_at),
    INDEX idx_sales_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sale_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(180) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(12, 2) NOT NULL,
    line_total DECIMAL(12, 2) NOT NULL,
    CONSTRAINT fk_sale_items_sale
        FOREIGN KEY (sale_id) REFERENCES sales (id)
        ON DELETE CASCADE,
    CONSTRAINT chk_sale_items_quantity CHECK (quantity > 0),
    CONSTRAINT chk_sale_items_unit_price CHECK (unit_price >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    role ENUM('Admin', 'Cashier', 'Staff') NOT NULL DEFAULT 'Staff',
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_status (status),
    INDEX idx_users_role (role)
) ENGINE=InnoDB;
