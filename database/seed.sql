-- Bookwise Inventory development data
-- Run after database/schema.sql.

USE bookwise;

INSERT INTO categories (name)
VALUES
    ('Books'),
    ('School Books'),
    ('Stationery'),
    ('Office Supplies'),
    ('Art & Craft')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO suppliers
    (name, contact_person, phone, email, address, category, payment_terms, status, notes)
VALUES
    (
        'Vijitha Yapa Distributors',
        'K. Ranasinghe',
        '077 221 5540',
        'orders@vijithayapa.example',
        '45 Galle Road, Colombo 03',
        'Books',
        '30 Days Credit',
        'Active',
        'Primary book supplier'
    ),
    (
        'Wasana Stationery Hub',
        'W. Perera',
        '071 449 8823',
        'sales@wasana.example',
        '18 Main Street, Colombo 01',
        'Stationery',
        'Cash on Delivery',
        'Active',
        'Stationery and school supplies'
    ),
    (
        'Colombo Art Supplies',
        'D. Silva',
        '070 336 1290',
        'hello@colomboart.example',
        '72 Union Place, Colombo 02',
        'Art & Craft',
        '14 Days Credit',
        'Active',
        'Art and craft products'
    ),
    (
        'Metro Office Traders',
        'N. Fernando',
        '076 902 4471',
        'contact@metrooffice.example',
        '12 High Level Road, Nugegoda',
        'Office Supplies',
        '7 Days Credit',
        'Inactive',
        'Inactive development supplier'
    )
ON DUPLICATE KEY UPDATE
    contact_person = VALUES(contact_person),
    phone = VALUES(phone),
    email = VALUES(email),
    address = VALUES(address),
    category = VALUES(category),
    payment_terms = VALUES(payment_terms),
    status = VALUES(status),
    notes = VALUES(notes);

INSERT INTO products
    (sku, name, author, isbn_barcode, category_id, unit_price, stock_quantity, reorder_level, primary_supplier_id, status)
SELECT
    source.sku,
    source.name,
    source.author,
    source.isbn_barcode,
    categories.id,
    source.unit_price,
    source.stock_quantity,
    source.reorder_level,
    suppliers.id,
    source.status
FROM (
    SELECT 'BK001' AS sku, 'Atomic Habits' AS name, 'James Clear' AS author, '9780735211292' AS isbn_barcode,
           'Books' AS category_name, 2350.00 AS unit_price, 34 AS stock_quantity, 10 AS reorder_level,
           'Vijitha Yapa Distributors' AS supplier_name, 'Active' AS status
    UNION ALL
    SELECT 'BK002', 'Rich Dad Poor Dad', 'Robert Kiyosaki', '9780446691262',
           'Books', 1950.00, 28, 10, 'Vijitha Yapa Distributors', 'Active'
    UNION ALL
    SELECT 'SB001', 'Grade 10 Science Textbook', NULL, '9789550000001',
           'School Books', 890.00, 6, 15, 'Vijitha Yapa Distributors', 'Active'
    UNION ALL
    SELECT 'ST001', 'Premium Gel Pens (10pcs)', NULL, NULL,
           'Stationery', 850.00, 52, 20, 'Wasana Stationery Hub', 'Active'
    UNION ALL
    SELECT 'ST002', 'A4 Spiral Notebook', NULL, NULL,
           'Stationery', 300.00, 9, 25, 'Wasana Stationery Hub', 'Active'
    UNION ALL
    SELECT 'OS001', 'Office Stapler Set', NULL, NULL,
           'Office Supplies', 1200.00, 17, 8, 'Metro Office Traders', 'Active'
    UNION ALL
    SELECT 'AC001', 'Watercolour Paint Set', NULL, NULL,
           'Art & Craft', 2100.00, 11, 12, 'Colombo Art Supplies', 'Active'
) AS source
INNER JOIN categories
    ON categories.name = source.category_name
LEFT JOIN suppliers
    ON suppliers.name = source.supplier_name
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    author = VALUES(author),
    isbn_barcode = VALUES(isbn_barcode),
    category_id = VALUES(category_id),
    unit_price = VALUES(unit_price),
    stock_quantity = VALUES(stock_quantity),
    reorder_level = VALUES(reorder_level),
    primary_supplier_id = VALUES(primary_supplier_id),
    status = VALUES(status);
