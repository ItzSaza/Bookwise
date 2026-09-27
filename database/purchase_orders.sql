-- Purchase Order tables for the Supplier Management module.
-- Run this in phpMyAdmin's SQL tab AFTER database/schema.sql and database/seed.sql.

USE bookwise;

CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    po_ref VARCHAR(30) NOT NULL UNIQUE,
    supplier_id INT UNSIGNED NOT NULL,
    order_date DATE NOT NULL,
    expected_date DATE NULL,
    status ENUM('Pending', 'Delivered', 'Cancelled') NOT NULL DEFAULT 'Pending',
    notes TEXT NULL,
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_po_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    INDEX idx_po_status (status),
    INDEX idx_po_supplier (supplier_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(180) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_cost DECIMAL(12, 2) NOT NULL,
    line_total DECIMAL(12, 2) NOT NULL,
    CONSTRAINT fk_po_items_po
        FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id)
        ON DELETE CASCADE,
    CONSTRAINT chk_po_items_quantity CHECK (quantity > 0),
    CONSTRAINT chk_po_items_unit_cost CHECK (unit_cost >= 0)
) ENGINE=InnoDB;

-- Sample purchase orders, matching the earlier front-end prototype data.
-- "Overdue" is not a stored status — it's computed by the API when a Pending
-- order's expected_date has already passed, the same way low-stock is computed
-- for products rather than stored as a flag.
INSERT INTO purchase_orders (po_ref, supplier_id, order_date, expected_date, status, total_amount)
SELECT 'PO-0142', id, '2026-08-21', '2026-08-29', 'Pending', 21050.00 FROM suppliers WHERE name = 'Vijitha Yapa Distributors'
UNION ALL
SELECT 'PO-0141', id, '2026-08-18', '2026-08-25', 'Pending', 14300.00 FROM suppliers WHERE name = 'Wasana Stationery Hub'
UNION ALL
SELECT 'PO-0139', id, '2026-08-12', '2026-08-19', 'Delivered', 9800.00 FROM suppliers WHERE name = 'Colombo Art Supplies'
UNION ALL
SELECT 'PO-0136', id, '2026-08-03', '2026-08-10', 'Delivered', 18600.00 FROM suppliers WHERE name = 'Vijitha Yapa Distributors'
ON DUPLICATE KEY UPDATE
    order_date = VALUES(order_date),
    expected_date = VALUES(expected_date),
    status = VALUES(status),
    total_amount = VALUES(total_amount);

INSERT INTO purchase_order_items (purchase_order_id, product_name, quantity, unit_cost, line_total)
SELECT po.id, 'Grade 10 Science Textbook', 50, 421.00, 21050.00 FROM purchase_orders po
    WHERE po.po_ref = 'PO-0142' AND NOT EXISTS (SELECT 1 FROM purchase_order_items WHERE purchase_order_id = po.id)
UNION ALL
SELECT po.id, 'A4 Spiral Notebook', 100, 143.00, 14300.00 FROM purchase_orders po
    WHERE po.po_ref = 'PO-0141' AND NOT EXISTS (SELECT 1 FROM purchase_order_items WHERE purchase_order_id = po.id)
UNION ALL
SELECT po.id, 'Watercolour Paint Set', 20, 490.00, 9800.00 FROM purchase_orders po
    WHERE po.po_ref = 'PO-0139' AND NOT EXISTS (SELECT 1 FROM purchase_order_items WHERE purchase_order_id = po.id)
UNION ALL
SELECT po.id, 'Atomic Habits', 40, 465.00, 18600.00 FROM purchase_orders po
    WHERE po.po_ref = 'PO-0136' AND NOT EXISTS (SELECT 1 FROM purchase_order_items WHERE purchase_order_id = po.id);
