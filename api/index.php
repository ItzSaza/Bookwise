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

    if ($resource === '' && $method === 'GET') {
        $database->query('SELECT 1');
        respond(['success' => true, 'message' => 'Bookwise API is connected to MySQL.']);
    }

    if ($resource === 'categories' && $method === 'GET') {
        $categories = $database->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
        respond(['success' => true, 'data' => $categories]);
    }

    if ($resource === 'suppliers' && $method === 'GET') {
        $suppliers = $database->query("SELECT id, name FROM suppliers WHERE status = 'Active' ORDER BY name")->fetchAll();
        respond(['success' => true, 'data' => $suppliers]);
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
