<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();

require_once __DIR__ . '/config/database.php';

// Shared API response helper: sends a JSON response and stops script execution.
function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Reads the raw JSON request body and converts it into a PHP array for validation and processing.
function requestBody(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

// Validates product form data before inserting or updating a product record.
function validateProduct(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $categoryValue = $input['category_id'] ?? null;
    $priceValue = $input['unit_price'] ?? null;
    $stockValue = $input['stock_quantity'] ?? null;
    $reorderValue = $input['reorder_level'] ?? null;

    $categoryId = filter_var($categoryValue, FILTER_VALIDATE_INT);
    $price = is_numeric($priceValue) ? (float) $priceValue : false;
    $stock = filter_var($stockValue, FILTER_VALIDATE_INT);
    $reorder = filter_var($reorderValue, FILTER_VALIDATE_INT);

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

// Validates supplier input before creating a supplier record in the database.
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

// Validates and normalizes expense data shared by the create and update endpoints.
function validateExpense(array $input): array
{
    $type = trim((string) ($input['expense_type'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $amountValue = $input['amount'] ?? null;
    $amount = is_numeric($amountValue) ? (float) $amountValue : false;
    $date = trim((string) ($input['expense_date'] ?? date('Y-m-d')));
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    if ($type === '' || strlen($type) > 100 || $description === '' || strlen($description) > 255
        || $amount === false || !is_finite($amount) || $amount <= 0
        || $parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
        respond(['success' => false, 'message' => 'Enter a valid category, description, positive amount, and date.'], 422);
    }

    return [
        'expense_type' => $type,
        'description' => $description,
        'amount' => $amount,
        'expense_date' => $date,
    ];
}

// Validates and normalizes sale data, including item totals, discount, tax, and final total.
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

// Main API entry point: opens the database connection and routes the request to the correct logic block.
try {
    $database = getDatabaseConnection();
    $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');
    $apiPath = preg_replace('#^.*?(?:^|/)api(?:/index\.php)?/?#i', '', $path);
    $segments = $apiPath === '' ? [] : explode('/', $apiPath);

    $resource = trim((string) ($_GET['resource'] ?? ($segments[0] ?? '')));
    if ($resource === 'index.php') {
        $resource = '';
    }

    $rawId = $_GET['id'] ?? ($segments[1] ?? null);
    $id = ($rawId !== null && $rawId !== '') ? filter_var($rawId, FILTER_VALIDATE_INT) : null;
    if ($id === false) {
        $id = null;
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $body = requestBody();

    if ($method === 'POST') {
        $override = $_POST['_method'] ?? $_GET['_method'] ?? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
        if ($override && in_array(strtoupper($override), ['PUT', 'PATCH', 'DELETE'], true)) {
            $method = strtoupper($override);
        } elseif (($_GET['action'] ?? '') === 'delete' || ($_POST['action'] ?? '') === 'delete' || ($body['action'] ?? '') === 'delete') {
            $method = 'DELETE';
        }
    }

    if ($id === null && !empty($body['id'])) {
        $candidateId = filter_var($body['id'], FILTER_VALIDATE_INT);
        if ($candidateId !== false) {
            $id = $candidateId;
        }
    }

    // Root health-check endpoint: confirms the API is running and the database is reachable.
    if ($resource === '' && $method === 'GET') {
        $database->query('SELECT 1');
        respond(['success' => true, 'message' => 'Bookwise API is connected to MySQL.']);
    }

    // Session inspection and logout endpoints.
    if ($resource === 'auth' && $method === 'GET') {
        if (empty($_SESSION['user'])) respond(['success' => false, 'message' => 'Not authenticated.'], 401);
        respond(['success' => true, 'user' => $_SESSION['user']]);
    }
    if ($resource === 'logout' && $method === 'POST') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        respond(['success' => true, 'message' => 'Logged out.']);
    }

    // Authentication endpoint: validates username and bcrypt password against the users table.
    if ($resource === 'auth' && $method === 'POST') {
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($username === '' || $password === '') {
            respond(['success' => false, 'message' => 'Username and password are required.'], 422);
        }
        $statement = $database->prepare(
            'SELECT id, full_name, role, status, password_hash
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();
        if (!$user) {
            respond(['success' => false, 'message' => 'Invalid username or password.'], 401);
        }
        if ($user['status'] !== 'Active') {
            respond(['success' => false, 'message' => 'Your account is inactive. Contact an administrator.'], 403);
        }
        if (!password_verify($password, $user['password_hash'])) {
            respond(['success' => false, 'message' => 'Invalid username or password.'], 401);
        }
        $database->prepare("UPDATE users SET status = 'Active' WHERE id = :id")->execute(['id' => $user['id']]);
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int) $user['id'], 'username' => $username, 'full_name' => $user['full_name'], 'role' => $user['role']];
        respond([
            'success'   => true,
            'message'   => 'Login successful.',
            'user'      => [
                'id'        => (int) $user['id'],
                'username'  => $username,
                'full_name' => $user['full_name'],
                'role'      => $user['role'],
            ],
        ]);
    }

    if (empty($_SESSION['user'])) {
        respond(['success' => false, 'message' => 'Please log in to access the system.'], 401);
    }

    $userRole = $_SESSION['user']['role'] ?? '';

    // Role-based access control for API endpoints:
    // 1. Admin-only resources: users and employees (Services & Staff)
    if (in_array($resource, ['users', 'employees'], true) && $userRole !== 'Admin') {
        respond(['success' => false, 'message' => 'Access denied: Admin privileges required.'], 403);
    }

    // 2. Cashier-restricted resources: Cashiers can only access Orders & POS (sales, read-only products/categories)
    if ($userRole === 'Cashier') {
        $cashierAllowed = ['sales', 'categories', 'products'];
        if (!in_array($resource, $cashierAllowed, true) || ($resource === 'products' && $method !== 'GET')) {
            respond(['success' => false, 'message' => 'Access denied: Cashiers can only access Orders & POS.'], 403);
        }
    }

    // Category management endpoints (Create, Read, Update, Delete)
    if ($resource === 'categories' && $method === 'GET') {
        if ($id) {
            $statement = $database->prepare('SELECT id, name FROM categories WHERE id = :id');
            $statement->execute(['id' => $id]);
            $cat = $statement->fetch();
            if (!$cat) respond(['success' => false, 'message' => 'Category not found.'], 404);
            respond(['success' => true, 'data' => $cat]);
        }
        $categories = $database->query(
            'SELECT c.id, c.name, COUNT(p.id) AS product_count
             FROM categories c
             LEFT JOIN products p ON p.category_id = c.id
             GROUP BY c.id
             ORDER BY c.name'
        )->fetchAll();
        respond(['success' => true, 'data' => $categories]);
    }

    if ($resource === 'categories' && $method === 'POST') {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            respond(['success' => false, 'message' => 'Category name is required.'], 422);
        }
        try {
            $statement = $database->prepare('INSERT INTO categories (name) VALUES (:name)');
            $statement->execute(['name' => $name]);
            respond(['success' => true, 'message' => 'Category created successfully.', 'id' => (int) $database->lastInsertId(), 'item_name' => $name], 201);
        } catch (PDOException $e) {
            respond(['success' => false, 'message' => 'A category with this name already exists.'], 422);
        }
    }

    if ($resource === 'categories' && $id && $method === 'PUT') {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            respond(['success' => false, 'message' => 'Category name is required.'], 422);
        }
        try {
            $statement = $database->prepare('UPDATE categories SET name = :name WHERE id = :id');
            $statement->execute(['name' => $name, 'id' => $id]);
            respond(['success' => true, 'message' => 'Category updated successfully.', 'item_name' => $name]);
        } catch (PDOException $e) {
            respond(['success' => false, 'message' => 'Category name already exists or update failed.'], 422);
        }
    }

    if ($resource === 'categories' && $id && $method === 'DELETE') {
        try {
            $catStmt = $database->prepare('SELECT name FROM categories WHERE id = :id');
            $catStmt->execute(['id' => $id]);
            $catRow = $catStmt->fetch();
            $itemName = $catRow ? $catRow['name'] : trim((string) ($_GET['name'] ?? ''));

            $statement = $database->prepare('DELETE FROM categories WHERE id = :id');
            $statement->execute(['id' => $id]);
            if ($statement->rowCount() === 0) {
                respond(['success' => false, 'message' => 'Category not found.'], 404);
            }
            respond(['success' => true, 'message' => 'Category deleted successfully.', 'item_name' => $itemName ?: 'Category']);
        } catch (PDOException $e) {
            respond(['success' => false, 'message' => 'Cannot delete this category because products are assigned to it.'], 422);
        }
    }

    // Supplier retrieval endpoint: fetches supplier records, optionally filtered by active status, or single supplier if id given.
    if ($resource === 'suppliers' && $method === 'GET') {
        if ($id) {
            $statement = $database->prepare('SELECT * FROM suppliers WHERE id = :id');
            $statement->execute(['id' => $id]);
            $supplier = $statement->fetch();
            if (!$supplier) {
                respond(['success' => false, 'message' => 'Supplier was not found.'], 404);
            }
            respond(['success' => true, 'data' => $supplier]);
        }
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

    // Supplier creation endpoint: validates and inserts a new supplier record.
    if ($resource === 'suppliers' && $method === 'POST') {
        $supplier = validateSupplier(requestBody());
        $statement = $database->prepare(
            'INSERT INTO suppliers
                (name, contact_person, phone, email, address, category, payment_terms, status, notes)
             VALUES (:name, :contact_person, :phone, :email, :address, :category, :payment_terms, :status, :notes)'
        );
        $statement->execute($supplier);
        respond(['success' => true, 'message' => 'Supplier added successfully.', 'id' => (int) $database->lastInsertId(), 'item_name' => $supplier['name']], 201);
    }

    // Supplier update endpoint: updates an existing supplier record.
    if ($resource === 'suppliers' && $id && $method === 'PUT') {
        $supplier = validateSupplier(requestBody());
        $statement = $database->prepare(
            'UPDATE suppliers
             SET name = :name, contact_person = :contact_person, phone = :phone, email = :email,
                 address = :address, category = :category, payment_terms = :payment_terms,
                 status = :status, notes = :notes
             WHERE id = :id'
        );
        $supplier['id'] = $id;
        $statement->execute($supplier);
        respond(['success' => true, 'message' => 'Supplier updated successfully.', 'item_name' => $supplier['name']]);
    }

    // Supplier deletion endpoint: removes a supplier, or marks Inactive if referenced in orders/products.
    if ($resource === 'suppliers' && $id && $method === 'DELETE') {
        try {
            $suppStmt = $database->prepare('SELECT name FROM suppliers WHERE id = :id');
            $suppStmt->execute(['id' => $id]);
            $suppRow = $suppStmt->fetch();
            $itemName = $suppRow ? $suppRow['name'] : trim((string) ($_GET['name'] ?? ''));

            $statement = $database->prepare('DELETE FROM suppliers WHERE id = :id');
            $statement->execute(['id' => $id]);
            if ($statement->rowCount() === 0) {
                respond(['success' => false, 'message' => 'Supplier was not found.'], 404);
            }
            respond(['success' => true, 'message' => 'Supplier deleted successfully.', 'item_name' => $itemName ?: 'Supplier']);
        } catch (PDOException $e) {
            $statement = $database->prepare("UPDATE suppliers SET status = 'Inactive' WHERE id = :id");
            $statement->execute(['id' => $id]);
            respond(['success' => true, 'message' => 'Supplier is referenced by orders/products; marked as Inactive instead.', 'item_name' => $itemName ?: 'Supplier']);
        }
    }

    // Purchase order endpoints (Create, Read, Update, Delete)
    if ($resource === 'purchase_orders' && $method === 'GET') {
        if ($id) {
            try {
                $statement = $database->prepare(
                    "SELECT po.id, po.po_ref, po.supplier_id, s.name AS supplier_name, po.order_date, po.expected_date,
                            po.notes, po.status, po.total_amount,
                            (po.status = 'Pending' AND po.expected_date IS NOT NULL AND po.expected_date < CURRENT_DATE()) AS is_overdue
                     FROM purchase_orders po
                     LEFT JOIN suppliers s ON s.id = po.supplier_id
                     WHERE po.id = :id"
                );
                $statement->execute(['id' => $id]);
                $po = $statement->fetch();
                if (!$po) {
                    respond(['success' => false, 'message' => 'Purchase order not found.'], 404);
                }
                $itemStmt = $database->prepare('SELECT * FROM purchase_order_items WHERE purchase_order_id = :id');
                $itemStmt->execute(['id' => $id]);
                $po['items'] = $itemStmt->fetchAll();
                respond(['success' => true, 'data' => $po]);
            } catch (PDOException $e) {
                respond(['success' => false, 'message' => 'Error loading purchase order.'], 500);
            }
        }
        try {
            $statement = $database->query(
                "SELECT po.id, po.po_ref, po.supplier_id, s.name AS supplier_name, po.order_date, po.expected_date,
                        po.status, po.total_amount,
                        (po.status = 'Pending' AND po.expected_date IS NOT NULL AND po.expected_date < CURRENT_DATE()) AS is_overdue
                 FROM purchase_orders po
                 LEFT JOIN suppliers s ON s.id = po.supplier_id
                 ORDER BY po.order_date DESC"
            );
            respond(['success' => true, 'data' => $statement->fetchAll()]);
        } catch (PDOException $e) {
            respond(['success' => true, 'data' => []]);
        }
    }

    if ($resource === 'purchase_orders' && $id && $method === 'PATCH') {
        $status = in_array($body['status'] ?? '', ['Pending', 'Delivered', 'Cancelled'], true) ? $body['status'] : 'Delivered';
        try {
            $statement = $database->prepare('UPDATE purchase_orders SET status = :status WHERE id = :id');
            $statement->execute(['status' => $status, 'id' => $id]);
            respond(['success' => true, 'message' => "Purchase order marked as $status."]);
        } catch (PDOException $e) {
            respond(['success' => false, 'message' => 'Failed to update purchase order.'], 500);
        }
    }

    if ($resource === 'purchase_orders' && $id && $method === 'PUT') {
        try {
            $supplierId = filter_var($body['supplier_id'] ?? null, FILTER_VALIDATE_INT);
            $orderDate = trim((string) ($body['order_date'] ?? date('Y-m-d')));
            $expectedDate = trim((string) ($body['expected_date'] ?? '')) ?: null;
            $notes = trim((string) ($body['notes'] ?? '')) ?: null;
            $status = in_array($body['status'] ?? '', ['Pending', 'Delivered', 'Cancelled'], true) ? $body['status'] : null;
            $items = $body['items'] ?? [];
            if (!$supplierId || empty($items)) {
                respond(['success' => false, 'message' => 'Supplier and at least one item are required.'], 422);
            }
            $total = 0;
            foreach ($items as $item) {
                $total += ((int)($item['quantity'] ?? 0)) * ((float)($item['unit_cost'] ?? 0));
            }
            $database->beginTransaction();
            $sql = 'UPDATE purchase_orders SET supplier_id = :supplier_id, order_date = :order_date, expected_date = :expected_date, notes = :notes, total_amount = :total_amount';
            $params = [
                'id' => $id,
                'supplier_id' => $supplierId,
                'order_date' => $orderDate,
                'expected_date' => $expectedDate,
                'notes' => $notes,
                'total_amount' => $total,
            ];
            if ($status !== null) {
                $sql .= ', status = :status';
                $params['status'] = $status;
            }
            $sql .= ' WHERE id = :id';
            $statement = $database->prepare($sql);
            $statement->execute($params);

            $database->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = :id')->execute(['id' => $id]);
            $itemStmt = $database->prepare(
                'INSERT INTO purchase_order_items (purchase_order_id, product_name, quantity, unit_cost, line_total)
                 VALUES (:po_id, :name, :qty, :cost, :line_total)'
            );
            foreach ($items as $item) {
                $qty = (int)($item['quantity'] ?? 1);
                $cost = (float)($item['unit_cost'] ?? 0);
                $itemStmt->execute([
                    'po_id' => $id,
                    'name' => trim((string)$item['product_name']),
                    'qty' => $qty,
                    'cost' => $cost,
                    'line_total' => $qty * $cost,
                ]);
            }
            $database->commit();
            respond(['success' => true, 'message' => 'Purchase order updated successfully.', 'item_name' => $body['po_ref'] ?? null]);
        } catch (PDOException $e) {
            if ($database->inTransaction()) $database->rollBack();
            respond(['success' => false, 'message' => 'Could not update purchase order: ' . $e->getMessage()], 500);
        }
    }

    if ($resource === 'purchase_orders' && $id && $method === 'DELETE') {
        try {
            $poStmt = $database->prepare('SELECT po_ref FROM purchase_orders WHERE id = :id');
            $poStmt->execute(['id' => $id]);
            $poRow = $poStmt->fetch();
            $itemName = $poRow ? $poRow['po_ref'] : trim((string) ($_GET['name'] ?? ''));

            $permanent = ($_GET['permanent'] ?? '') === 'true';
            if ($permanent) {
                $database->beginTransaction();
                $database->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = :id')->execute(['id' => $id]);
                $database->prepare('DELETE FROM purchase_orders WHERE id = :id')->execute(['id' => $id]);
                $database->commit();
                respond(['success' => true, 'message' => 'Purchase order deleted successfully.', 'item_name' => $itemName ?: 'Purchase Order']);
            } else {
                $statement = $database->prepare("UPDATE purchase_orders SET status = 'Cancelled' WHERE id = :id");
                $statement->execute(['id' => $id]);
                respond(['success' => true, 'message' => 'Purchase order cancelled.', 'item_name' => $itemName ?: 'Purchase Order']);
            }
        } catch (PDOException $e) {
            if ($database->inTransaction()) $database->rollBack();
            respond(['success' => false, 'message' => 'Failed to delete purchase order.'], 500);
        }
    }

    if ($resource === 'purchase_orders' && $method === 'POST') {
        try {
            $supplierId = filter_var($body['supplier_id'] ?? null, FILTER_VALIDATE_INT);
            $orderDate = trim((string) ($body['order_date'] ?? date('Y-m-d')));
            $expectedDate = trim((string) ($body['expected_date'] ?? '')) ?: null;
            $notes = trim((string) ($body['notes'] ?? '')) ?: null;
            $items = $body['items'] ?? [];
            if (!$supplierId || empty($items)) {
                respond(['success' => false, 'message' => 'Supplier and at least one item are required.'], 422);
            }
            $total = 0;
            foreach ($items as $item) {
                $total += ((int)($item['quantity'] ?? 0)) * ((float)($item['unit_cost'] ?? 0));
            }
            $poRef = 'PO-' . str_pad((string)(mt_rand(1, 9999)), 4, '0', STR_PAD_LEFT);
            $statement = $database->prepare(
                'INSERT INTO purchase_orders (po_ref, supplier_id, order_date, expected_date, notes, total_amount, status)
                 VALUES (:po_ref, :supplier_id, :order_date, :expected_date, :notes, :total_amount, "Pending")'
            );
            $statement->execute([
                'po_ref' => $poRef,
                'supplier_id' => $supplierId,
                'order_date' => $orderDate,
                'expected_date' => $expectedDate,
                'notes' => $notes,
                'total_amount' => $total,
            ]);
            $poId = (int) $database->lastInsertId();
            $itemStmt = $database->prepare(
                'INSERT INTO purchase_order_items (purchase_order_id, product_name, quantity, unit_cost, line_total)
                 VALUES (:po_id, :name, :qty, :cost, :line_total)'
            );
            foreach ($items as $item) {
                $qty = (int)($item['quantity'] ?? 1);
                $cost = (float)($item['unit_cost'] ?? 0);
                $itemStmt->execute([
                    'po_id' => $poId,
                    'name' => trim((string)$item['product_name']),
                    'qty' => $qty,
                    'cost' => $cost,
                    'line_total' => $qty * $cost,
                ]);
            }
            respond(['success' => true, 'message' => 'Purchase order created successfully.', 'po_ref' => $poRef, 'item_name' => $poRef], 201);
        } catch (PDOException $e) {
            respond(['success' => false, 'message' => 'Could not save purchase order: ' . $e->getMessage()], 500);
        }
    }

    // Sales endpoints (Create, Read, Update, Delete)
    if ($resource === 'sales' && $method === 'GET') {
        if ($id) {
            $statement = $database->prepare(
                'SELECT s.*, DATE_FORMAT(s.created_at, "%d %b %Y, %H:%i") AS date
                 FROM sales s WHERE s.id = :id'
            );
            $statement->execute(['id' => $id]);
            $sale = $statement->fetch();
            if (!$sale) {
                respond(['success' => false, 'message' => 'Sale not found.'], 404);
            }
            $itemStmt = $database->prepare('SELECT * FROM sale_items WHERE sale_id = :id');
            $itemStmt->execute(['id' => $id]);
            $sale['items'] = $itemStmt->fetchAll();
            respond(['success' => true, 'data' => $sale]);
        }
        $statement = $database->query(
            'SELECT s.id, s.order_ref AS ref, s.customer_name AS customer, COUNT(si.id) AS items,
                    s.payment_method AS payment, s.subtotal, s.discount_amount, s.tax_amount,
                    s.total_amount AS amount, s.status,
                    DATE_FORMAT(s.created_at, "%d %b %Y, %H:%i") AS date
             FROM sales s
             LEFT JOIN sale_items si ON si.sale_id = s.id
             GROUP BY s.id
             ORDER BY s.created_at DESC
             LIMIT 50'
        );
        respond(['success' => true, 'data' => $statement->fetchAll()]);
    }

    // Sale creation endpoint: validates items, inserts the sales header, and creates item rows in one transaction.
    if ($resource === 'sales' && $method === 'POST') {
        $raw = requestBody();
        $sale = validateSale($raw);
        $customerName = trim((string) ($raw['customer_name'] ?? 'Walk-in Customer')) ?: 'Walk-in Customer';
        $database->beginTransaction();
        $orderRef = 'ORD-' . str_pad((string) (time() % 100000), 5, '0', STR_PAD_LEFT);
        $saleStatement = $database->prepare(
            'INSERT INTO sales
                (order_ref, customer_name, payment_method, subtotal, discount_amount, tax_amount, total_amount)
             VALUES (:order_ref, :customer_name, :payment_method, :subtotal, :discount_amount, :tax_amount, :total_amount)'
        );
        $saleStatement->execute([
            'order_ref' => $orderRef,
            'customer_name' => $customerName,
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
        respond(['success' => true, 'message' => 'Sale completed successfully.', 'order_ref' => $orderRef, 'item_name' => $orderRef, 'id' => $saleId], 201);
    }

    // Sale update endpoint: updates order customer, payment method, or status.
    if ($resource === 'sales' && $id && ($method === 'PUT' || $method === 'PATCH')) {
        $customer = trim((string) ($body['customer_name'] ?? ''));
        $payment = in_array($body['payment_method'] ?? '', ['Cash', 'Card'], true) ? $body['payment_method'] : null;
        $status = in_array($body['status'] ?? '', ['Paid', 'Pending', 'Refunded'], true) ? $body['status'] : null;

        $updates = [];
        $params = ['id' => $id];
        if ($customer !== '') {
            $updates[] = 'customer_name = :customer_name';
            $params['customer_name'] = $customer;
        }
        if ($payment !== null) {
            $updates[] = 'payment_method = :payment_method';
            $params['payment_method'] = $payment;
        }
        if ($status !== null) {
            $updates[] = 'status = :status';
            $params['status'] = $status;
        }

        if (empty($updates)) {
            respond(['success' => false, 'message' => 'No fields to update provided.'], 422);
        }

        try {
            $sql = 'UPDATE sales SET ' . implode(', ', $updates) . ' WHERE id = :id';
            $statement = $database->prepare($sql);
            $statement->execute($params);

            $saleStmt = $database->prepare('SELECT order_ref FROM sales WHERE id = :id');
            $saleStmt->execute(['id' => $id]);
            $saleRow = $saleStmt->fetch();
            $orderRef = $saleRow ? $saleRow['order_ref'] : null;

            respond(['success' => true, 'message' => 'Order updated successfully.', 'item_name' => $orderRef]);
        } catch (PDOException $e) {
            respond(['success' => false, 'message' => 'Failed to update order: ' . $e->getMessage()], 500);
        }
    }

    // Sale deletion endpoint: permanently deletes a sale and its associated line items.
    if ($resource === 'sales' && $id && $method === 'DELETE') {
        try {
            $saleStmt = $database->prepare('SELECT order_ref FROM sales WHERE id = :id');
            $saleStmt->execute(['id' => $id]);
            $saleRow = $saleStmt->fetch();
            $itemName = $saleRow ? $saleRow['order_ref'] : trim((string) ($_GET['name'] ?? ''));

            $database->beginTransaction();
            $database->prepare('DELETE FROM sale_items WHERE sale_id = :id')->execute(['id' => $id]);
            $statement = $database->prepare('DELETE FROM sales WHERE id = :id');
            $statement->execute(['id' => $id]);
            if ($statement->rowCount() === 0) {
                $database->rollBack();
                respond(['success' => false, 'message' => 'Sale not found.'], 404);
            }
            $database->commit();
            respond(['success' => true, 'message' => 'Order deleted successfully.', 'item_name' => $itemName ?: 'Order']);
        } catch (PDOException $e) {
            if ($database->inTransaction()) $database->rollBack();
            respond(['success' => false, 'message' => 'Failed to delete order: ' . $e->getMessage()], 500);
        }
    }

    // User list endpoint: retrieves staff and admin user records, or single user if id given.
    if ($resource === 'users' && $method === 'GET') {
        if ($id) {
            $statement = $database->prepare(
                'SELECT id, full_name, username, email, role, status
                 FROM users
                 WHERE id = :id'
            );
            $statement->execute(['id' => $id]);
            $user = $statement->fetch();
            if (!$user) {
                respond(['success' => false, 'message' => 'User was not found.'], 404);
            }
            respond(['success' => true, 'data' => $user]);
        }
        $statement = $database->query(
            'SELECT id, full_name, email, role, status
             FROM users
             ORDER BY full_name'
        );
        respond(['success' => true, 'data' => $statement->fetchAll()]);
    }

    // User creation endpoint: validates the user record before adding it to the system.
    if ($resource === 'users' && $method === 'POST') {
        $name     = trim((string) ($body['full_name'] ?? ''));
        $username = trim((string) ($body['username'] ?? ''));
        $email    = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $role     = in_array($body['role'] ?? '', ['Admin', 'Cashier', 'Staff'], true) ? $body['role'] : 'Staff';
        $status   = ($body['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['success' => false, 'message' => 'A valid name and email are required.'], 422);
        }
        // Derive a username from the first name segment if not provided.
        if ($username === '') {
            $username = strtolower(preg_replace('/\s+/', '', explode(' ', $name)[0]));
        }
        $hash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]) : '';
        $statement = $database->prepare(
            'INSERT INTO users (full_name, username, email, password_hash, role, status)
             VALUES (:full_name, :username, :email, :password_hash, :role, :status)'
        );
        $statement->execute([
            'full_name'     => $name,
            'username'      => $username,
            'email'         => $email,
            'password_hash' => $hash,
            'role'          => $role,
            'status'        => $status,
        ]);
        respond(['success' => true, 'message' => 'User added successfully.', 'id' => (int) $database->lastInsertId(), 'item_name' => $name], 201);
    }

    // User update endpoint: modifies an existing user record.
    if ($resource === 'users' && $id && $method === 'PUT') {
        $name   = trim((string) ($body['full_name'] ?? ''));
        $email  = trim((string) ($body['email'] ?? ''));
        $role   = in_array($body['role'] ?? '', ['Admin', 'Cashier', 'Staff'], true) ? $body['role'] : 'Staff';
        $status = ($body['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['success' => false, 'message' => 'A valid name and email are required.'], 422);
        }
        $statement = $database->prepare(
            'UPDATE users
             SET full_name = :full_name, email = :email, role = :role, status = :status
             WHERE id = :id'
        );
        $statement->execute([
            'id'        => $id,
            'full_name' => $name,
            'email'     => $email,
            'role'      => $role,
            'status'    => $status,
        ]);
        respond(['success' => true, 'message' => 'User updated successfully.', 'item_name' => $name]);
    }

    // User status update endpoint: toggles the active/inactive status for an individual user.
    if ($resource === 'users' && $id && $method === 'PATCH') {
        $status = (($body['status'] ?? '') === 'Active') ? 'Active' : 'Inactive';
        $statement = $database->prepare('UPDATE users SET status = :status WHERE id = :id');
        $statement->execute(['status' => $status, 'id' => $id]);
        if ($statement->rowCount() === 0) {
            respond(['success' => false, 'message' => 'User was not found or no changes were made.'], 404);
        }
        $uStmt = $database->prepare('SELECT full_name, username FROM users WHERE id = :id');
        $uStmt->execute(['id' => $id]);
        $uRow = $uStmt->fetch();
        $uName = $uRow ? ($uRow['full_name'] ?: $uRow['username']) : null;
        respond(['success' => true, 'message' => 'User status updated successfully.', 'item_name' => $uName]);
    }

    // User deletion endpoint: permanently deletes a user.
    if ($resource === 'users' && $id && $method === 'DELETE') {
        $uStmt = $database->prepare('SELECT full_name, username FROM users WHERE id = :id');
        $uStmt->execute(['id' => $id]);
        $uRow = $uStmt->fetch();
        $itemName = $uRow ? ($uRow['full_name'] ?: $uRow['username']) : trim((string) ($_GET['name'] ?? ''));

        $statement = $database->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            respond(['success' => false, 'message' => 'User was not found.'], 404);
        }
        respond(['success' => true, 'message' => 'User deleted successfully.', 'item_name' => $itemName ?: 'User']);
    }

    // Employee lookup endpoint: returns all employee records sorted by name or single employee if id given.
    if ($resource === 'employees' && $method === 'GET') {
        if ($id) {
            $statement = $database->prepare('SELECT id, name, role, phone, department, status FROM employees WHERE id = :id');
            $statement->execute(['id' => $id]);
            $emp = $statement->fetch();
            if (!$emp) {
                respond(['success' => false, 'message' => 'Employee was not found.'], 404);
            }
            respond(['success' => true, 'data' => $emp]);
        }
        $statement = $database->query(
            'SELECT id, name, role, phone, department, status
             FROM employees
             ORDER BY name'
        );
        respond(['success' => true, 'data' => $statement->fetchAll()]);
    }

    // Employee creation endpoint: validates the employee details and inserts the record.
    if ($resource === 'employees' && $method === 'POST') {
        $employee = [
            'name' => trim((string) ($body['name'] ?? '')),
            'role' => trim((string) ($body['role'] ?? '')),
            'phone' => trim((string) ($body['phone'] ?? '')),
            'department' => trim((string) ($body['department'] ?? '')),
            'status' => in_array($body['status'] ?? '', ['active', 'leave', 'inactive'], true)
                ? $body['status']
                : 'active',
        ];
        if ($employee['name'] === '' || $employee['role'] === '' || $employee['phone'] === '' || $employee['department'] === '') {
            respond(['success' => false, 'message' => 'Name, role, phone, and department are required.'], 422);
        }
        $statement = $database->prepare(
            'INSERT INTO employees (name, role, phone, department, status)
             VALUES (:name, :role, :phone, :department, :status)'
        );
        $statement->execute($employee);
        respond(['success' => true, 'message' => 'Employee added successfully.', 'id' => (int) $database->lastInsertId(), 'item_name' => $employee['name']], 201);
    }

    // Employee update endpoint: edits an existing employee record using the provided id.
    if ($resource === 'employees' && $id && $method === 'PUT') {
        $employee = [
            'id' => $id,
            'name' => trim((string) ($body['name'] ?? '')),
            'role' => trim((string) ($body['role'] ?? '')),
            'phone' => trim((string) ($body['phone'] ?? '')),
            'department' => trim((string) ($body['department'] ?? '')),
            'status' => in_array($body['status'] ?? '', ['active', 'leave', 'inactive'], true)
                ? $body['status']
                : 'active',
        ];
        if ($employee['name'] === '' || $employee['role'] === '' || $employee['phone'] === '' || $employee['department'] === '') {
            respond(['success' => false, 'message' => 'Name, role, phone, and department are required.'], 422);
        }
        $statement = $database->prepare(
            'UPDATE employees
             SET name = :name, role = :role, phone = :phone, department = :department, status = :status
             WHERE id = :id'
        );
        $statement->execute($employee);
        respond(['success' => true, 'message' => 'Employee updated successfully.', 'item_name' => $employee['name']]);
    }

    // Employee deletion endpoint: removes an employee record.
    if ($resource === 'employees' && $id && $method === 'DELETE') {
        $empStmt = $database->prepare('SELECT name FROM employees WHERE id = :id');
        $empStmt->execute(['id' => $id]);
        $empRow = $empStmt->fetch();
        $itemName = $empRow ? $empRow['name'] : trim((string) ($_GET['name'] ?? ''));

        $statement = $database->prepare('DELETE FROM employees WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            respond(['success' => false, 'message' => 'Employee was not found.'], 404);
        }
        respond(['success' => true, 'message' => 'Employee deleted successfully.', 'item_name' => $itemName ?: 'Employee']);
    }

    // Financial report endpoint: returns live database totals and ledger for a selected date range.
    if ($resource === 'financial-report' && $method === 'GET') {
        $start = trim((string) ($_GET['start'] ?? date('Y-m-01')));
        $end = trim((string) ($_GET['end'] ?? date('Y-m-d')));
        $validDate = static function (string $value): bool {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $date !== false && $date->format('Y-m-d') === $value;
        };
        if (!$validDate($start) || !$validDate($end) || $start > $end) {
            respond(['success' => false, 'message' => 'Choose a valid date range.'], 422);
        }

        $salesStmt = $database->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM sales
             WHERE status = 'Paid' AND created_at >= :start AND created_at < DATE_ADD(:end, INTERVAL 1 DAY)"
        );
        $salesStmt->execute(['start' => $start, 'end' => $end]);
        $revenue = (float) $salesStmt->fetchColumn();

        $expenseStmt = $database->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM expenses
             WHERE expense_date >= :start AND expense_date <= :end"
        );
        $expenseStmt->execute(['start' => $start, 'end' => $end]);
        $expenseTotal = (float) $expenseStmt->fetchColumn();

        $incomeBreakdownStmt = $database->prepare(
            "SELECT payment_method AS category, COALESCE(SUM(total_amount), 0) AS amount
             FROM sales
             WHERE status = 'Paid' AND created_at >= :start AND created_at < DATE_ADD(:end, INTERVAL 1 DAY)
             GROUP BY payment_method ORDER BY amount DESC"
        );
        $incomeBreakdownStmt->execute(['start' => $start, 'end' => $end]);
        $incomeBreakdown = $incomeBreakdownStmt->fetchAll();

        $expenseBreakdownStmt = $database->prepare(
            "SELECT expense_type AS category, COALESCE(SUM(amount), 0) AS amount
             FROM expenses WHERE expense_date >= :start AND expense_date <= :end
             GROUP BY expense_type ORDER BY amount DESC"
        );
        $expenseBreakdownStmt->execute(['start' => $start, 'end' => $end]);
        $expenseBreakdown = $expenseBreakdownStmt->fetchAll();

        $ledgerStmt = $database->prepare(
            "SELECT * FROM (
                SELECT DATE(created_at) AS transaction_date, order_ref AS ref, 'Sale' AS type,
                       customer_name AS description, total_amount AS amount
                FROM sales WHERE status = 'Paid' AND created_at >= :sale_start AND created_at < DATE_ADD(:sale_end, INTERVAL 1 DAY)
                UNION ALL
                SELECT expense_date AS transaction_date, expense_ref AS ref, 'Expense' AS type,
                       CONCAT(expense_type, ' — ', description) AS description, -amount AS amount
                FROM expenses WHERE expense_date >= :expense_start AND expense_date <= :expense_end
             ) AS ledger ORDER BY transaction_date DESC, ref DESC"
        );
        $ledgerStmt->execute(['sale_start' => $start, 'sale_end' => $end, 'expense_start' => $start, 'expense_end' => $end]);
        $ledger = $ledgerStmt->fetchAll();

        $outstanding = (float) $database->query(
            "SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE status = 'Pending'"
        )->fetchColumn();

        respond(['success' => true, 'data' => [
            'start' => $start, 'end' => $end,
            'revenue' => $revenue, 'expenses' => $expenseTotal,
            'profit' => $revenue - $expenseTotal,
            'profit_margin' => $revenue > 0 ? (($revenue - $expenseTotal) / $revenue) * 100 : 0,
            'outstanding' => $outstanding,
            'income_breakdown' => $incomeBreakdown,
            'expense_breakdown' => $expenseBreakdown,
            'ledger' => $ledger,
            'transaction_count' => count($ledger)
        ]]);
    }

    // Financial summary endpoint: calculates revenue, expenses, profit, and recent transaction history for the month.
    if ($resource === 'financials' && $method === 'GET') {
        $salesTotal = (float) $database->query(
            "SELECT COALESCE(SUM(total_amount), 0) FROM sales
             WHERE created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') AND status = 'Paid'"
        )->fetchColumn();
        $expenseTotal = (float) $database->query(
            "SELECT COALESCE(SUM(amount), 0) FROM expenses
             WHERE expense_date >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')"
        )->fetchColumn();
        $outstandingStatement = $database->query(
            "SELECT COALESCE(SUM(total_amount), 0) AS total,
                    COUNT(*) AS invoice_count
             FROM sales WHERE status = 'Pending'"
        );
        $outstanding = $outstandingStatement->fetch();

        // Aggregate paid sales from Monday through Sunday of the current week.
        $weeklySales = $database->query(
            "SELECT DATE(created_at) AS sale_date, COALESCE(SUM(total_amount), 0) AS total
             FROM sales
             WHERE status = 'Paid'
               AND created_at >= DATE_SUB(CURRENT_DATE, INTERVAL WEEKDAY(CURRENT_DATE) DAY)
               AND created_at < DATE_ADD(DATE_SUB(CURRENT_DATE, INTERVAL WEEKDAY(CURRENT_DATE) DAY), INTERVAL 7 DAY)
             GROUP BY DATE(created_at)
             ORDER BY sale_date"
        )->fetchAll();

        $transactions = $database->query(
            "SELECT order_ref AS ref, 'Sale' AS type, total_amount AS amount, created_at AS transaction_date
             FROM sales WHERE status = 'Paid'
             UNION ALL
             SELECT expense_ref, expense_type, -amount, expense_date
             FROM expenses
             ORDER BY transaction_date DESC LIMIT 20"
        )->fetchAll();
        respond([
            'success' => true,
            'data' => [
                'revenue' => $salesTotal,
                'expenses' => $expenseTotal,
                'profit' => $salesTotal - $expenseTotal,
                'outstanding' => (float) $outstanding['total'],
                'outstanding_count' => (int) $outstanding['invoice_count'],
                'weekly_sales' => $weeklySales,
                'transactions' => $transactions,
            ],
        ]);
    }

    // Expense listing endpoint: returns all expense records for the financial management screen.
    if ($resource === 'expenses' && $method === 'GET') {
        $expenses = $database->query(
            'SELECT id, expense_ref, expense_type, description, amount, expense_date
             FROM expenses
             ORDER BY expense_date DESC, id DESC'
        )->fetchAll();
        respond(['success' => true, 'data' => $expenses]);
    }

    // Expense creation endpoint: validates and stores a new expense record.
    if ($resource === 'expenses' && $method === 'POST') {
        $expense = validateExpense($body);
        $expenseRef = 'EXP-' . strtoupper(bin2hex(random_bytes(4)));
        $statement = $database->prepare(
            'INSERT INTO expenses (expense_ref, expense_type, description, amount, expense_date)
             VALUES (:expense_ref, :expense_type, :description, :amount, :expense_date)'
        );
        $statement->execute(['expense_ref' => $expenseRef] + $expense);
        respond(['success' => true, 'message' => 'Expense added successfully.', 'expense_ref' => $expenseRef, 'item_name' => $expenseRef], 201);
    }

    // Expense update endpoint: changes editable fields while preserving the original reference.
    if ($resource === 'expenses' && $id && $method === 'PUT') {
        $expense = validateExpense($body);
        $statement = $database->prepare(
            'UPDATE expenses
             SET expense_type = :expense_type, description = :description,
                 amount = :amount, expense_date = :expense_date
             WHERE id = :id'
        );
        $statement->execute($expense + ['id' => $id]);
        if ($statement->rowCount() === 0) {
            $exists = $database->prepare('SELECT 1 FROM expenses WHERE id = :id');
            $exists->execute(['id' => $id]);
            if (!$exists->fetchColumn()) {
                respond(['success' => false, 'message' => 'Expense was not found.'], 404);
            }
        }
        respond(['success' => true, 'message' => 'Expense updated successfully.', 'item_name' => $expense['description']]);
    }

    // Expense deletion endpoint: removes the selected expense record.
    if ($resource === 'expenses' && $id && $method === 'DELETE') {
        $expStmt = $database->prepare('SELECT expense_ref, description FROM expenses WHERE id = :id');
        $expStmt->execute(['id' => $id]);
        $expRow = $expStmt->fetch();
        $itemName = $expRow ? ($expRow['expense_ref'] ?: $expRow['description']) : trim((string) ($_GET['name'] ?? ''));

        $statement = $database->prepare('DELETE FROM expenses WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            respond(['success' => false, 'message' => 'Expense was not found.'], 404);
        }
        respond(['success' => true, 'message' => 'Expense deleted successfully.', 'item_name' => $itemName ?: 'Expense']);
    }

    // Product listing endpoint: returns active products with search/category filters, or single product by id.
    if ($resource === 'products' && $method === 'GET') {
        if ($id) {
            $statement = $database->prepare(
                'SELECT p.id, p.sku, p.name, p.author, p.isbn_barcode, p.unit_price,
                        p.stock_quantity, p.reorder_level, p.status,
                        p.category_id, c.name AS category_name,
                        p.primary_supplier_id, s.name AS supplier_name
                 FROM products p
                 INNER JOIN categories c ON c.id = p.category_id
                 LEFT JOIN suppliers s ON s.id = p.primary_supplier_id
                 WHERE p.id = :id'
            );
            $statement->execute(['id' => $id]);
            $product = $statement->fetch();
            if (!$product) {
                respond(['success' => false, 'message' => 'Product was not found.'], 404);
            }
            respond(['success' => true, 'data' => $product]);
        }
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

    // Product creation endpoint: validates the incoming product data and inserts a new record.
    if ($resource === 'products' && $method === 'POST') {
        $product = validateProduct(requestBody());
        $sku = 'DEMO-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $statement = $database->prepare(
            'INSERT INTO products
                (sku, name, isbn_barcode, category_id, unit_price, stock_quantity, reorder_level, primary_supplier_id, status)
             VALUES (:sku, :name, :isbn_barcode, :category_id, :unit_price, :stock_quantity, :reorder_level, :primary_supplier_id, :status)'
        );
        $statement->execute(['sku' => $sku] + $product);
        respond(['success' => true, 'message' => 'Product added successfully.', 'id' => (int) $database->lastInsertId(), 'item_name' => $product['name']], 201);
    }

    // Product update endpoint: updates the selected product with the provided validated values.
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
            $exists = $database->prepare('SELECT 1 FROM products WHERE id = :id');
            $exists->execute(['id' => $id]);
            if (!$exists->fetchColumn()) {
                respond(['success' => false, 'message' => 'Product was not found.'], 404);
            }
        }
        respond(['success' => true, 'message' => 'Product updated successfully.', 'item_name' => $product['name']]);
    }

    // Product deletion endpoint: removes product, or marks Inactive if referenced in orders.
    if ($resource === 'products' && $id && $method === 'DELETE') {
        try {
            $prodStmt = $database->prepare('SELECT name FROM products WHERE id = :id');
            $prodStmt->execute(['id' => $id]);
            $prodRow = $prodStmt->fetch();
            $itemName = $prodRow ? $prodRow['name'] : trim((string) ($_GET['name'] ?? ''));

            $statement = $database->prepare('DELETE FROM products WHERE id = :id');
            $statement->execute(['id' => $id]);
            if ($statement->rowCount() === 0) {
                respond(['success' => false, 'message' => 'Product was not found.'], 404);
            }
            respond(['success' => true, 'message' => 'Product deleted successfully.', 'item_name' => $itemName ?: 'Product']);
        } catch (PDOException $e) {
            $statement = $database->prepare("UPDATE products SET status = 'Inactive' WHERE id = :id");
            $statement->execute(['id' => $id]);
            respond(['success' => true, 'message' => 'Product is referenced by sales records; marked as Inactive instead.', 'item_name' => $itemName ?: 'Product']);
        }
    }

    // Fallback response for unknown endpoints.
    respond(['success' => false, 'message' => 'Endpoint not found.'], 404);
} catch (PDOException $error) {
    // Database-level failure handler: returns a 500 error when the SQL query fails.
    respond(['success' => false, 'message' => 'Database request failed.'], 500);
} catch (Throwable $error) {
    // General error handler: prevents the application from exposing raw server exceptions.
    respond(['success' => false, 'message' => 'Unexpected server error.'], 500);
}
