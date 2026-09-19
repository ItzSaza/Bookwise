<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/database.php';

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function requestBody(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function validateProduct(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $categoryId = filter_var($input['category_id'] ?? null, FILTER_VALIDATE_INT);
    $price = filter_var($input['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);
    $stock = filter_var($input['stock_quantity'] ?? null, FILTER_VALIDATE_INT);
    $reorder = filter_var($input['reorder_level'] ?? null, FILTER_VALIDATE_INT);

    if ($name === '' || $categoryId === false || $price === false || $stock === false || $reorder === false) {
        respond(['success' => false, 'message' => 'Name, category, price, stock, and reorder level are required.'], 422);
    }

    if ($price < 0 || $stock < 0 || $reorder < 0) {
        respond(['success' => false, 'message' => 'Price and quantities cannot be negative.'], 422);
    }

    return [
        'name' => $name,
        'category_id' => $categoryId,
        'unit_price' => $price,
        'stock_quantity' => $stock,
        'reorder_level' => $reorder,
        'isbn_barcode' => trim((string) ($input['isbn_barcode'] ?? '')) ?: null,
        'primary_supplier_id' => ($input['primary_supplier_id'] ?? null) ?: null,
        'status' => ($input['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active',
    ];
}

function validateSupplier(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $contactPerson = trim((string) ($input['contact_person'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $category = trim((string) ($input['category'] ?? ''));

    if ($name === '' || $contactPerson === '' || $phone === '' || $category === '') {
        respond(['success' => false, 'message' => 'Name, contact person, phone, and category are required.'], 422);
    }

    return [
        'name' => $name,
        'contact_person' => $contactPerson,
        'phone' => $phone,
        'email' => trim((string) ($input['email'] ?? '')) ?: null,
        'address' => trim((string) ($input['address'] ?? '')) ?: null,
        'category' => $category,
        'payment_terms' => trim((string) ($input['payment_terms'] ?? 'Cash on Delivery')) ?: 'Cash on Delivery',
        'status' => ($input['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active',
        'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
    ];
}

function validateSale(array $input): array
{
    $items = $input['items'] ?? [];
    $payment = ($input['payment_method'] ?? '') === 'Card' ? 'Card' : 'Cash';
    if (!is_array($items) || count($items) === 0) {
        respond(['success' => false, 'message' => 'At least one sale item is required.'], 422);
    }

    $cleanItems = [];
    foreach ($items as $item) {
        $name = trim((string) ($item['name'] ?? ''));
        $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
        $unitPrice = filter_var($item['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($name === '' || $quantity === false || $quantity < 1 || $unitPrice === false || $unitPrice < 0) {
            respond(['success' => false, 'message' => 'Sale items contain invalid values.'], 422);
        }
        $cleanItems[] = [
            'name' => $name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
        ];
    }

    $subtotal = array_sum(array_column($cleanItems, 'line_total'));
    $discount = max(0, (float) ($input['discount_amount'] ?? 0));
    $tax = max(0, (float) ($input['tax_amount'] ?? 0));
    return [
        'items' => $cleanItems,
        'payment_method' => $payment,
        'subtotal' => $subtotal,
        'discount_amount' => $discount,
        'tax_amount' => $tax,
        'total_amount' => max(0, $subtotal - $discount + $tax),
    ];
}

try {
    $database = getDatabaseConnection();
    $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');
    $apiPath = preg_replace('#^.*?/api/?#', '', $path);
    $segments = $apiPath === '' ? [] : explode('/', $apiPath);
    $resource = $_GET['resource'] ?? ($segments[0] ?? '');
    $id = isset($segments[1])
        ? filter_var($segments[1], FILTER_VALIDATE_INT)
        : (isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null);
    $method = $_SERVER['REQUEST_METHOD'];
    $body = requestBody();

    if ($resource === '' && $method === 'GET') {
        $database->query('SELECT 1');
        respond(['success' => true, 'message' => 'Bookwise API is connected to MySQL.']);
    }

    if ($resource === 'categories' && $method === 'GET') {
        $categories = $database->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
        respond(['success' => true, 'data' => $categories]);
    }

    if ($resource === 'suppliers' && $method === 'GET') {
        $status = ($_GET['status'] ?? 'all') === 'active' ? 'Active' : null;
        $sql = 'SELECT id, name, contact_person, phone, email, address, category,
                       payment_terms, status, notes
                FROM suppliers';
        if ($status !== null) {
            $sql .= ' WHERE status = :status';
        }
        $sql .= ' ORDER BY name';
        $statement = $database->prepare($sql);
        $statement->execute($status === null ? [] : ['status' => $status]);
        $suppliers = $statement->fetchAll();
        respond(['success' => true, 'data' => $suppliers]);
    }

    if ($resource === 'suppliers' && $method === 'POST') {
        $supplier = validateSupplier(requestBody());
        $statement = $database->prepare(
            'INSERT INTO suppliers
                (name, contact_person, phone, email, address, category, payment_terms, status, notes)
             VALUES (:name, :contact_person, :phone, :email, :address, :category, :payment_terms, :status, :notes)'
        );
        $statement->execute($supplier);
        respond(['success' => true, 'message' => 'Supplier added successfully.', 'id' => (int) $database->lastInsertId()], 201);
    }

    if ($resource === 'suppliers' && $id && $method === 'DELETE') {
        $statement = $database->prepare("UPDATE suppliers SET status = 'Inactive' WHERE id = :id");
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            respond(['success' => false, 'message' => 'Supplier was not found.'], 404);
        }
        respond(['success' => true, 'message' => 'Supplier deactivated successfully.']);
    }

    if ($resource === 'sales' && $method === 'GET') {
        $statement = $database->query(
            'SELECT s.order_ref AS ref, s.customer_name AS customer, COUNT(si.id) AS items,
                    s.payment_method AS payment, s.total_amount AS amount, s.status,
                    DATE_FORMAT(s.created_at, "%d %b %Y, %H:%i") AS date
             FROM sales s
             LEFT JOIN sale_items si ON si.sale_id = s.id
             GROUP BY s.id
             ORDER BY s.created_at DESC
             LIMIT 50'
        );
        respond(['success' => true, 'data' => $statement->fetchAll()]);
    }

    if ($resource === 'sales' && $method === 'POST') {
        $sale = validateSale(requestBody());
        $database->beginTransaction();
        $orderRef = 'ORD-' . str_pad((string) (time() % 100000), 5, '0', STR_PAD_LEFT);
        $saleStatement = $database->prepare(
            'INSERT INTO sales
                (order_ref, customer_name, payment_method, subtotal, discount_amount, tax_amount, total_amount)
             VALUES (:order_ref, :customer_name, :payment_method, :subtotal, :discount_amount, :tax_amount, :total_amount)'
        );
        $saleStatement->execute([
            'order_ref' => $orderRef,
            'customer_name' => 'Walk-in Customer',
            'payment_method' => $sale['payment_method'],
            'subtotal' => $sale['subtotal'],
            'discount_amount' => $sale['discount_amount'],
            'tax_amount' => $sale['tax_amount'],
            'total_amount' => $sale['total_amount'],
        ]);
        $saleId = (int) $database->lastInsertId();
        $itemStatement = $database->prepare(
            'INSERT INTO sale_items (sale_id, product_name, quantity, unit_price, line_total)
             VALUES (:sale_id, :product_name, :quantity, :unit_price, :line_total)'
        );
        foreach ($sale['items'] as $item) {
            $itemStatement->execute([
                'sale_id' => $saleId,
                'product_name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'],
            ]);
        }
        $database->commit();
        respond(['success' => true, 'message' => 'Sale completed successfully.', 'order_ref' => $orderRef], 201);
    }

    if ($resource === 'users' && $method === 'GET') {
        $statement = $database->query(
            'SELECT id, full_name, email, role, status
             FROM users
             ORDER BY full_name'
        );
        respond(['success' => true, 'data' => $statement->fetchAll()]);
    }

    if ($resource === 'users' && $method === 'POST') {
        $name = trim((string) ($body['full_name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $role = in_array($body['role'] ?? '', ['Admin', 'Cashier', 'Staff'], true) ? $body['role'] : 'Staff';
        $status = ($body['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['success' => false, 'message' => 'A valid name and email are required.'], 422);
        }
        $statement = $database->prepare(
            'INSERT INTO users (full_name, email, role, status)
             VALUES (:full_name, :email, :role, :status)'
        );
        $statement->execute([
            'full_name' => $name,
            'email' => $email,
            'role' => $role,
            'status' => $status,
        ]);
        respond(['success' => true, 'message' => 'User added successfully.', 'id' => (int) $database->lastInsertId()], 201);
    }

    if ($resource === 'users' && $id && $method === 'PATCH') {
        $status = (($body['status'] ?? '') === 'Active') ? 'Active' : 'Inactive';
        $statement = $database->prepare('UPDATE users SET status = :status WHERE id = :id');
        $statement->execute(['status' => $status, 'id' => $id]);
        if ($statement->rowCount() === 0) {
            respond(['success' => false, 'message' => 'User was not found or no changes were made.'], 404);
        }
        respond(['success' => true, 'message' => 'User status updated successfully.']);
    }

    if ($resource === 'products' && $method === 'GET') {
        $search = trim((string) ($_GET['search'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $sql = 'SELECT p.id, p.sku, p.name, p.author, p.isbn_barcode, p.unit_price,
                       p.stock_quantity, p.reorder_level, p.status,
                       c.id AS category_id, c.name AS category_name,
                       s.id AS primary_supplier_id, s.name AS supplier_name
                FROM products p
                INNER JOIN categories c ON c.id = p.category_id
                LEFT JOIN suppliers s ON s.id = p.primary_supplier_id
                WHERE p.status = :status';
        $params = ['status' => 'Active'];
        if ($search !== '') {
            $sql .= ' AND p.name LIKE :search';
            $params['search'] = '%' . $search . '%';
        }
        if ($category !== '') {
            $sql .= ' AND c.name = :category';
            $params['category'] = $category;
        }
        $sql .= ' ORDER BY p.name';
        $statement = $database->prepare($sql);
        $statement->execute($params);
        respond(['success' => true, 'data' => $statement->fetchAll()]);
    }

    if ($resource === 'products' && $method === 'POST') {
        $product = validateProduct(requestBody());
        $sku = 'DEMO-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $statement = $database->prepare(
            'INSERT INTO products
                (sku, name, isbn_barcode, category_id, unit_price, stock_quantity, reorder_level, primary_supplier_id, status)
             VALUES (:sku, :name, :isbn_barcode, :category_id, :unit_price, :stock_quantity, :reorder_level, :primary_supplier_id, :status)'
        );
        $statement->execute(['sku' => $sku] + $product);
        respond(['success' => true, 'message' => 'Product added successfully.', 'id' => (int) $database->lastInsertId()], 201);
    }

    if ($resource === 'products' && $id && $method === 'PUT') {
        $product = validateProduct(requestBody());
        $statement = $database->prepare(
            'UPDATE products SET name = :name, isbn_barcode = :isbn_barcode, category_id = :category_id,
             unit_price = :unit_price, stock_quantity = :stock_quantity, reorder_level = :reorder_level,
             primary_supplier_id = :primary_supplier_id, status = :status WHERE id = :id'
        );
        $product['id'] = $id;
        $statement->execute($product);
        if ($statement->rowCount() === 0) {
            respond(['success' => false, 'message' => 'Product was not found or no changes were made.'], 404);
        }
        respond(['success' => true, 'message' => 'Product updated successfully.']);
    }

    if ($resource === 'products' && $id && $method === 'DELETE') {
        $statement = $database->prepare("UPDATE products SET status = 'Inactive' WHERE id = :id");
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            respond(['success' => false, 'message' => 'Product was not found.'], 404);
        }
        respond(['success' => true, 'message' => 'Product deleted successfully.']);
    }

    respond(['success' => false, 'message' => 'Endpoint not found.'], 404);
} catch (PDOException $error) {
    respond(['success' => false, 'message' => 'Database request failed.'], 500);
} catch (Throwable $error) {
    respond(['success' => false, 'message' => 'Unexpected server error.'], 500);
}
