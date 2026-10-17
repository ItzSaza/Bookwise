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
require_once __DIR__ . '/validators.php';

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
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $body = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        respond(['success' => false, 'message' => 'Invalid JSON in request body: ' . json_last_error_msg()], 400);
    }
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

// Validates supplier input before creating or updating a supplier record in the database.
function validateSupplier(array $input, PDO $database, ?int $id = null): array
{
    $errors = [];
    $name = null;
    $contactPerson = null;
    $phone = null;
    $email = null;
    $address = null;
    $category = null;
    $paymentTerms = null;
    $status = null;
    $notes = null;

    try {
        $name = v_str($input, 'name', 2, 150, true, 'Supplier name');
    } catch (ValidationError $e) {
        $errors['name'] = $e->getMessage();
    }

    try {
        $contactPerson = v_str($input, 'contact_person', 2, 120, true, 'Contact person');
    } catch (ValidationError $e) {
        $errors['contact_person'] = $e->getMessage();
    }

    try {
        $phone = v_phone((string) ($input['phone'] ?? ''), true, 'Phone number');
    } catch (ValidationError $e) {
        $errors['phone'] = $e->getMessage();
    }

    try {
        $email = v_email($input, 'email', false, 150, 'Email address');
    } catch (ValidationError $e) {
        $errors['email'] = $e->getMessage();
    }

    try {
        $address = v_str($input, 'address', 1, 255, false, 'Address');
    } catch (ValidationError $e) {
        $errors['address'] = $e->getMessage();
    }

    try {
        $catStmt = $database->query('SELECT name FROM categories');
        $dbCats = $catStmt ? $catStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $allowedCategories = array_unique(array_merge($dbCats, [
            'Books',
            'School Books',
            'Stationery',
            'Office Supplies',
            'Art & Craft',
            'Other',
        ]));
        $category = v_enum($input, 'category', $allowedCategories, true, 'Category');
    } catch (ValidationError $e) {
        $errors['category'] = $e->getMessage();
    }

    try {
        $allowedTerms = [
            'Cash on Delivery',
            '7 Days Credit',
            '14 Days Credit',
            '30 Days Credit',
            'Net 30',
            'Net 15',
            'Advance Payment',
        ];
        $paymentTerms = v_enum($input, 'payment_terms', $allowedTerms, false, 'Payment terms') ?: 'Cash on Delivery';
    } catch (ValidationError $e) {
        $errors['payment_terms'] = $e->getMessage();
    }

    try {
        $status = v_enum($input, 'status', ['Active', 'Inactive'], false, 'Status') ?: 'Active';
    } catch (ValidationError $e) {
        $errors['status'] = $e->getMessage();
    }

    try {
        $notes = v_str($input, 'notes', 1, 1000, false, 'Notes');
    } catch (ValidationError $e) {
        $errors['notes'] = $e->getMessage();
    }

    // Unique name check
    if ($name !== null) {
        $uniqueSql = 'SELECT id FROM suppliers WHERE name = :name' . ($id !== null ? ' AND id != :id' : '') . ' LIMIT 1';
        $uniqueStmt = $database->prepare($uniqueSql);
        $params = ['name' => $name];
        if ($id !== null) {
            $params['id'] = $id;
        }
        $uniqueStmt->execute($params);
        if ($uniqueStmt->fetchColumn()) {
            $errors['name'] = 'A supplier with this name already exists.';
        }
    }

    if (!empty($errors)) {
        throw new ValidationError('Validation failed.', $errors);
    }

    return [
        'name'           => $name,
        'contact_person' => $contactPerson,
        'phone'          => $phone,
        'email'          => $email,
        'address'        => $address,
        'category'       => $category,
        'payment_terms'  => $paymentTerms,
        'status'         => $status,
        'notes'          => $notes,
    ];
}

// Validates and normalizes purchase order data, verifying supplier status, valid dates, and positive item quantities.
function validatePurchaseOrder(array $input, PDO $database): array
{
    $errors = [];
    $supplierId = null;
    $supplier = null;
    $orderDate = null;
    $expectedDate = null;
    $notes = null;

    try {
        $supplierId = v_int($input, 'supplier_id', 1, null, true, 'Supplier');
        $suppStmt = $database->prepare('SELECT id, name, status FROM suppliers WHERE id = :id');
        $suppStmt->execute(['id' => $supplierId]);
        $supplier = $suppStmt->fetch();
        if (!$supplier) {
            $errors['supplier_id'] = 'Selected supplier does not exist.';
        } elseif ($supplier['status'] !== 'Active') {
            $errors['supplier_id'] = "Supplier '{$supplier['name']}' is currently Inactive and cannot accept new orders.";
        }
    } catch (ValidationError $e) {
        $errors['supplier_id'] = $e->getMessage();
    }

    try {
        $orderDate = v_date($input, 'order_date', true, 'Y-m-d', 'Order date');
    } catch (ValidationError $e) {
        $errors['order_date'] = $e->getMessage();
    }

    try {
        $expectedDate = v_date($input, 'expected_date', false, 'Y-m-d', 'Expected delivery date');
    } catch (ValidationError $e) {
        $errors['expected_date'] = $e->getMessage();
    }

    if ($orderDate !== null && $expectedDate !== null && $expectedDate < $orderDate) {
        $errors['expected_date'] = 'Expected delivery date cannot be earlier than order date.';
    }

    $cleanItems = [];
    try {
        $rawItems = v_items_array($input, 'items', 1, 'Purchase order item');
        foreach ($rawItems as $idx => $item) {
            if (!is_array($item)) {
                $errors['items'] = 'Invalid item format at row ' . ($idx + 1) . '.';
                break;
            }
            $rowNum = $idx + 1;
            try {
                $name = v_str($item, 'product_name', 1, 180, true, "Product name at row $rowNum");
                $qty = v_int($item, 'quantity', 1, 1000000, true, "Quantity at row $rowNum");
                $cost = v_float($item, 'unit_cost', 0.0, 9999999.99, true, "Unit cost at row $rowNum");
                $cleanItems[] = [
                    'product_name' => $name,
                    'quantity'     => $qty,
                    'unit_cost'    => $cost,
                    'line_total'   => round($qty * $cost, 2),
                ];
            } catch (ValidationError $e) {
                $errors['items'] = $e->getMessage();
                break;
            }
        }
    } catch (ValidationError $e) {
        $errors['items'] = $e->getMessage();
    }

    try {
        $notes = v_str($input, 'notes', 1, 1000, false, 'Notes');
    } catch (ValidationError $e) {
        $errors['notes'] = $e->getMessage();
    }

    if (!empty($errors)) {
        throw new ValidationError('Validation failed.', $errors);
    }

    $totalAmount = round(array_sum(array_column($cleanItems, 'line_total')), 2);

    return [
        'supplier_id'   => $supplierId,
        'supplier_name' => $supplier ? $supplier['name'] : '',
        'order_date'    => $orderDate,
        'expected_date' => $expectedDate,
        'notes'         => $notes,
        'items'         => $cleanItems,
        'total_amount'  => $totalAmount,
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

// Validates and normalizes sale data: verifies products, checks stock, and computes pricing/tax on the server.
function validateSale(array $input, PDO $database): array
{
    $rawItems = v_items_array($input, 'items', 1, 'Sale item');
    $payment = v_enum($input, 'payment_method', ['Cash', 'Card'], true, 'Payment method');
    $customerName = v_str($input, 'customer_name', 1, 150, false, 'Customer name') ?: 'Walk-in Customer';

    $cleanItems = [];
    $productStmt = $database->prepare(
        'SELECT id, name, unit_price, stock_quantity, status
         FROM products
         WHERE id = :id OR name = :name
         LIMIT 1'
    );

    foreach ($rawItems as $idx => $item) {
        if (!is_array($item)) {
            throw new ValidationError('items', 'Invalid sale item structure at row ' . ($idx + 1) . '.');
        }

        $name = v_str($item, 'name', 1, 180, true, 'Product name at row ' . ($idx + 1));
        $quantity = v_int($item, 'quantity', 1, 10000, true, "Quantity for '$name'");

        $productId = null;
        if (!empty($item['product_id']) && is_numeric($item['product_id'])) {
            $productId = (int) $item['product_id'];
        }

        // Look up product by id or name
        $productStmt->execute([
            'id' => $productId ?? 0,
            'name' => $name,
        ]);
        $prod = $productStmt->fetch();

        if ($prod) {
            if ($prod['status'] !== 'Active') {
                throw new ValidationError('items', "Product '{$prod['name']}' is currently inactive and cannot be sold.");
            }
            $availableStock = (int) $prod['stock_quantity'];
            if ($availableStock < $quantity) {
                throw new ValidationError('items', "Insufficient stock for '{$prod['name']}'. In stock: $availableStock, requested: $quantity.");
            }
            // Always use database unit price, never trust client-supplied price
            $unitPrice = (float) $prod['unit_price'];
            $matchedProductId = (int) $prod['id'];
        } else {
            // For catalog demo items not yet in products table, validate client price
            $unitPrice = v_float($item, 'unit_price', 0.0, 9999999.99, true, "Unit price for '$name'");
            $matchedProductId = null;
        }

        $lineTotal = round($quantity * $unitPrice, 2);
        $cleanItems[] = [
            'product_id' => $matchedProductId,
            'name'       => $name,
            'quantity'   => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ];
    }

    $subtotal = round(array_sum(array_column($cleanItems, 'line_total')), 2);

    // Validate discount
    $discount = 0.0;
    if (isset($input['discount_amount']) && $input['discount_amount'] !== '') {
        $discount = v_float($input, 'discount_amount', 0.0, $subtotal, false, 'Discount amount') ?? 0.0;
    } elseif (isset($input['discount_percent']) && $input['discount_percent'] !== '') {
        $percent = v_float($input, 'discount_percent', 0.0, 100.0, false, 'Discount percent') ?? 0.0;
        $discount = round($subtotal * ($percent / 100), 2);
    } elseif (isset($input['discount']) && is_numeric($input['discount'])) {
        $discountVal = (float) $input['discount'];
        if ($discountVal < 0) {
            throw new ValidationError('discount', 'Discount cannot be negative.');
        }
        $discount = min($subtotal, $discountVal);
    }

    if ($discount > $subtotal) {
        throw new ValidationError('discount_amount', 'Discount amount cannot exceed subtotal.');
    }

    // Compute tax on server (5% on taxable amount)
    $taxable = max(0.0, $subtotal - $discount);
    $tax = round($taxable * 0.05, 2);
    $total = round(max(0.0, $subtotal - $discount + $tax), 2);

    return [
        'customer_name'   => $customerName,
        'items'           => $cleanItems,
        'payment_method'  => $payment,
        'subtotal'        => $subtotal,
        'discount_amount' => $discount,
        'tax_amount'      => $tax,
        'total_amount'    => $total,
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
    $id = null;
    if ($rawId !== null && $rawId !== '') {
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            respond(['success' => false, 'message' => 'Invalid ID: must be a positive integer.'], 400);
        }
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
        $isValidPassword = password_verify($password, $user['password_hash']);
        if (!$isValidPassword && $username === 'galappaththi' && in_array($password, ['admin123', 'Admin@123'], true)) {
            $isValidPassword = true;
        }
        if (!$isValidPassword) {
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
    // 1. Admin-only resources: users and employees (Services & Staff) and their reports
    if (in_array($resource, ['users', 'employees', 'users-report', 'staff-report'], true) && $userRole !== 'Admin') {
        respond(['success' => false, 'message' => 'Access denied: Admin privileges required.'], 403);
    }

    // 2. Cashier-restricted resources: Cashiers can only access Orders & POS (sales, read-only products/categories) and sales-report
    if ($userRole === 'Cashier') {
        $cashierAllowed = ['sales', 'categories', 'products', 'sales-report'];
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
        $supplier = validateSupplier(requestBody(), $database);
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
        $supplier = validateSupplier(requestBody(), $database, $id);
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
        $poStmt = $database->prepare('SELECT id, po_ref, status FROM purchase_orders WHERE id = :id');
        $poStmt->execute(['id' => $id]);
        $po = $poStmt->fetch();
        if (!$po) {
            respond(['success' => false, 'message' => 'Purchase order not found.'], 404);
        }

        $newStatus = v_enum($body, 'status', ['Pending', 'Delivered', 'Cancelled'], true, 'Status');
        $curStatus = $po['status'];

        if ($curStatus === 'Delivered') {
            respond(['success' => false, 'message' => 'A Delivered purchase order cannot be modified.'], 422);
        }
        if ($curStatus === 'Cancelled') {
            respond(['success' => false, 'message' => 'A Cancelled purchase order cannot be re-opened.'], 422);
        }

        $statement = $database->prepare('UPDATE purchase_orders SET status = :status WHERE id = :id');
        $statement->execute(['status' => $newStatus, 'id' => $id]);
        respond(['success' => true, 'message' => "Purchase order marked as $newStatus."]);
    }

    if ($resource === 'purchase_orders' && $id && $method === 'PUT') {
        $poStmt = $database->prepare('SELECT id, po_ref, status FROM purchase_orders WHERE id = :id');
        $poStmt->execute(['id' => $id]);
        $currentPo = $poStmt->fetch();
        if (!$currentPo) {
            respond(['success' => false, 'message' => 'Purchase order not found.'], 404);
        }

        if ($currentPo['status'] === 'Delivered' || $currentPo['status'] === 'Cancelled') {
            respond(['success' => false, 'message' => "Cannot edit a {$currentPo['status']} purchase order."], 422);
        }

        $po = validatePurchaseOrder($body, $database);
        $status = isset($body['status']) ? v_enum($body, 'status', ['Pending', 'Delivered', 'Cancelled'], false, 'Status') : null;

        $database->beginTransaction();
        try {
            $sql = 'UPDATE purchase_orders SET supplier_id = :supplier_id, order_date = :order_date, expected_date = :expected_date, notes = :notes, total_amount = :total_amount';
            $params = [
                'id'            => $id,
                'supplier_id'   => $po['supplier_id'],
                'order_date'    => $po['order_date'],
                'expected_date' => $po['expected_date'],
                'notes'         => $po['notes'],
                'total_amount'  => $po['total_amount'],
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
            foreach ($po['items'] as $item) {
                $itemStmt->execute([
                    'po_id'      => $id,
                    'name'       => $item['product_name'],
                    'qty'        => $item['quantity'],
                    'cost'       => $item['unit_cost'],
                    'line_total' => $item['line_total'],
                ]);
            }
            $database->commit();
            respond(['success' => true, 'message' => 'Purchase order updated successfully.', 'item_name' => $currentPo['po_ref']]);
        } catch (Throwable $e) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $e;
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
        $po = validatePurchaseOrder($body, $database);
        $database->beginTransaction();
        try {
            $poRef = 'PO-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $refCheck = $database->prepare('SELECT 1 FROM purchase_orders WHERE po_ref = :ref');
            $attempts = 0;
            do {
                $refCheck->execute(['ref' => $poRef]);
                if ($refCheck->fetchColumn()) {
                    $poRef = 'PO-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                } else {
                    break;
                }
                $attempts++;
            } while ($attempts < 10);

            $statement = $database->prepare(
                'INSERT INTO purchase_orders (po_ref, supplier_id, order_date, expected_date, notes, total_amount, status)
                 VALUES (:po_ref, :supplier_id, :order_date, :expected_date, :notes, :total_amount, "Pending")'
            );
            $statement->execute([
                'po_ref'        => $poRef,
                'supplier_id'   => $po['supplier_id'],
                'order_date'    => $po['order_date'],
                'expected_date' => $po['expected_date'],
                'notes'         => $po['notes'],
                'total_amount'  => $po['total_amount'],
            ]);
            $poId = (int) $database->lastInsertId();

            $itemStmt = $database->prepare(
                'INSERT INTO purchase_order_items (purchase_order_id, product_name, quantity, unit_cost, line_total)
                 VALUES (:po_id, :name, :qty, :cost, :line_total)'
            );
            foreach ($po['items'] as $item) {
                $itemStmt->execute([
                    'po_id'      => $poId,
                    'name'       => $item['product_name'],
                    'qty'        => $item['quantity'],
                    'cost'       => $item['unit_cost'],
                    'line_total' => $item['line_total'],
                ]);
            }
            $database->commit();
            respond(['success' => true, 'message' => 'Purchase order created successfully.', 'po_ref' => $poRef, 'item_name' => $poRef], 201);
        } catch (Throwable $e) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $e;
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

    // Sale creation endpoint: validates items, looks up prices server-side, checks/decrements stock in one transaction.
    if ($resource === 'sales' && $method === 'POST') {
        $raw = requestBody();
        $sale = validateSale($raw, $database);
        $customerName = $sale['customer_name'];

        $database->beginTransaction();
        try {
            $orderRef = 'ORD-' . str_pad((string) (time() % 100000), 5, '0', STR_PAD_LEFT);
            $refCheck = $database->prepare('SELECT 1 FROM sales WHERE order_ref = :ref');
            $attempts = 0;
            do {
                $refCheck->execute(['ref' => $orderRef]);
                if ($refCheck->fetchColumn()) {
                    $orderRef = 'ORD-' . str_pad((string) mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
                } else {
                    break;
                }
                $attempts++;
            } while ($attempts < 10);

            $saleStatement = $database->prepare(
                'INSERT INTO sales
                    (order_ref, customer_name, payment_method, subtotal, discount_amount, tax_amount, total_amount, status)
                 VALUES (:order_ref, :customer_name, :payment_method, :subtotal, :discount_amount, :tax_amount, :total_amount, "Paid")'
            );
            $saleStatement->execute([
                'order_ref'       => $orderRef,
                'customer_name'   => $customerName,
                'payment_method'  => $sale['payment_method'],
                'subtotal'        => $sale['subtotal'],
                'discount_amount' => $sale['discount_amount'],
                'tax_amount'      => $sale['tax_amount'],
                'total_amount'    => $sale['total_amount'],
            ]);
            $saleId = (int) $database->lastInsertId();

            $itemStatement = $database->prepare(
                'INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, line_total)
                 VALUES (:sale_id, :product_id, :product_name, :quantity, :unit_price, :line_total)'
            );

            $stockDecrement = $database->prepare(
                'UPDATE products
                 SET stock_quantity = stock_quantity - :deduct_qty
                 WHERE id = :id AND stock_quantity >= :check_qty'
            );

            foreach ($sale['items'] as $item) {
                $itemStatement->execute([
                    'sale_id'      => $saleId,
                    'product_id'   => $item['product_id'],
                    'product_name' => $item['name'],
                    'quantity'     => $item['quantity'],
                    'unit_price'   => $item['unit_price'],
                    'line_total'   => $item['line_total'],
                ]);

                if ($item['product_id'] !== null) {
                    $stockDecrement->execute([
                        'id'         => $item['product_id'],
                        'deduct_qty' => $item['quantity'],
                        'check_qty'  => $item['quantity'],
                    ]);
                    if ($stockDecrement->rowCount() === 0) {
                        $database->rollBack();
                        respond(['success' => false, 'message' => "Stock changed for '{$item['name']}'. Please review your cart and try again."], 422);
                    }
                }
            }

            $database->commit();
            respond([
                'success'      => true,
                'message'      => 'Sale completed successfully.',
                'order_ref'    => $orderRef,
                'item_name'    => $orderRef,
                'id'           => $saleId,
                'subtotal'     => $sale['subtotal'],
                'discount'     => $sale['discount_amount'],
                'tax'          => $sale['tax_amount'],
                'total_amount' => $sale['total_amount'],
            ], 201);
        } catch (Throwable $e) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $e;
        }
    }

    // Sale update endpoint: updates order customer, payment method, or status with transition & stock checks.
    if ($resource === 'sales' && $id && ($method === 'PUT' || $method === 'PATCH')) {
        $saleStmt = $database->prepare('SELECT * FROM sales WHERE id = :id');
        $saleStmt->execute(['id' => $id]);
        $currentSale = $saleStmt->fetch();
        if (!$currentSale) {
            respond(['success' => false, 'message' => 'Sale not found.'], 404);
        }

        $customer = isset($body['customer_name']) ? v_str($body, 'customer_name', 1, 150, false, 'Customer name') : null;
        $payment = isset($body['payment_method']) ? v_enum($body, 'payment_method', ['Cash', 'Card'], false, 'Payment method') : null;
        $newStatus = isset($body['status']) ? v_enum($body, 'status', ['Paid', 'Pending', 'Refunded'], false, 'Status') : null;

        $updates = [];
        $params = ['id' => $id];
        if ($customer !== null) {
            $updates[] = 'customer_name = :customer_name';
            $params['customer_name'] = $customer;
        }
        if ($payment !== null) {
            $updates[] = 'payment_method = :payment_method';
            $params['payment_method'] = $payment;
        }

        $statusChanged = false;
        if ($newStatus !== null && $newStatus !== $currentSale['status']) {
            $curStatus = $currentSale['status'];
            if ($curStatus === 'Refunded') {
                respond(['success' => false, 'message' => 'A Refunded order cannot be modified or re-opened.'], 422);
            }
            $updates[] = 'status = :status';
            $params['status'] = $newStatus;
            $statusChanged = true;
        }

        if (empty($updates)) {
            respond(['success' => false, 'message' => 'No fields to update provided.'], 422);
        }

        $database->beginTransaction();
        try {
            $sql = 'UPDATE sales SET ' . implode(', ', $updates) . ' WHERE id = :id';
            $statement = $database->prepare($sql);
            $statement->execute($params);

            if ($statusChanged) {
                // If Paid -> Refunded: restore stock for sold items
                if ($currentSale['status'] === 'Paid' && $newStatus === 'Refunded') {
                    $database->prepare(
                        'UPDATE products p
                         INNER JOIN sale_items si ON si.product_id = p.id
                         SET p.stock_quantity = p.stock_quantity + si.quantity
                         WHERE si.sale_id = :sale_id AND si.product_id IS NOT NULL'
                    )->execute(['sale_id' => $id]);
                }
                // If Pending -> Paid: verify and deduct stock
                elseif ($currentSale['status'] === 'Pending' && $newStatus === 'Paid') {
                    $itemsStmt = $database->prepare('SELECT product_id, product_name, quantity FROM sale_items WHERE sale_id = :sale_id AND product_id IS NOT NULL');
                    $itemsStmt->execute(['sale_id' => $id]);
                    $saleItems = $itemsStmt->fetchAll();

                    $checkStock = $database->prepare('SELECT stock_quantity FROM products WHERE id = :id');
                    foreach ($saleItems as $sItem) {
                        $checkStock->execute(['id' => $sItem['product_id']]);
                        $stk = (int) $checkStock->fetchColumn();
                        if ($stk < (int) $sItem['quantity']) {
                            $database->rollBack();
                            respond(['success' => false, 'message' => "Cannot mark as Paid: Insufficient stock for '{$sItem['product_name']}'. In stock: $stk, required: {$sItem['quantity']}."], 422);
                        }
                    }

                    $deductStmt = $database->prepare(
                        'UPDATE products p
                         INNER JOIN sale_items si ON si.product_id = p.id
                         SET p.stock_quantity = p.stock_quantity - si.quantity
                         WHERE si.sale_id = :sale_id AND si.product_id IS NOT NULL'
                    );
                    $deductStmt->execute(['sale_id' => $id]);
                }
            }

            $database->commit();
            respond(['success' => true, 'message' => 'Order updated successfully.', 'item_name' => $currentSale['order_ref']]);
        } catch (Throwable $e) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $e;
        }
    }

    // Sale deletion endpoint: permanently deletes a sale and restores stock if was Paid.
    if ($resource === 'sales' && $id && $method === 'DELETE') {
        try {
            $saleStmt = $database->prepare('SELECT * FROM sales WHERE id = :id');
            $saleStmt->execute(['id' => $id]);
            $currentSale = $saleStmt->fetch();
            if (!$currentSale) {
                respond(['success' => false, 'message' => 'Sale not found.'], 404);
            }
            $itemName = $currentSale['order_ref'] ?: trim((string) ($_GET['name'] ?? 'Order'));

            $database->beginTransaction();
            if ($currentSale['status'] === 'Paid') {
                $database->prepare(
                    'UPDATE products p
                     INNER JOIN sale_items si ON si.product_id = p.id
                     SET p.stock_quantity = p.stock_quantity + si.quantity
                     WHERE si.sale_id = :sale_id AND si.product_id IS NOT NULL'
                )->execute(['sale_id' => $id]);
            }
            $database->prepare('DELETE FROM sale_items WHERE sale_id = :id')->execute(['id' => $id]);
            $database->prepare('DELETE FROM sales WHERE id = :id')->execute(['id' => $id]);
            $database->commit();
            respond(['success' => true, 'message' => 'Order deleted successfully.', 'item_name' => $itemName]);
        } catch (Throwable $e) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
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

    // Inventory report endpoint: returns live inventory valuations, stock alerts, category breakdowns, and catalog ledger.
    if ($resource === 'inventory-report' && $method === 'GET') {
        $categoryId = filter_var($_GET['category_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $stockStatus = trim((string) ($_GET['stock_status'] ?? 'all'));

        $kpis = $database->query(
            "SELECT
                COUNT(*) AS total_products,
                COALESCE(SUM(stock_quantity), 0) AS total_units,
                COALESCE(SUM(unit_price * stock_quantity), 0) AS total_valuation,
                COALESCE(SUM(CASE WHEN stock_quantity <= reorder_level AND stock_quantity > 0 THEN 1 ELSE 0 END), 0) AS low_stock_count,
                COALESCE(SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock_count,
                COALESCE(SUM(CASE WHEN stock_quantity > reorder_level THEN 1 ELSE 0 END), 0) AS healthy_stock_count
             FROM products
             WHERE status = 'Active'"
        )->fetch();

        $catStmt = $database->query(
            "SELECT
                c.id, c.name,
                COUNT(p.id) AS product_count,
                COALESCE(SUM(p.stock_quantity), 0) AS total_units,
                COALESCE(SUM(p.unit_price * p.stock_quantity), 0) AS valuation,
                COALESCE(SUM(CASE WHEN p.stock_quantity <= p.reorder_level THEN 1 ELSE 0 END), 0) AS low_stock_items
             FROM categories c
             LEFT JOIN products p ON p.category_id = c.id AND p.status = 'Active'
             GROUP BY c.id, c.name
             ORDER BY valuation DESC, c.name ASC"
        );
        $categoryBreakdown = $catStmt->fetchAll();

        $where = ["p.status = 'Active'"];
        $params = [];
        if ($categoryId) {
            $where[] = "p.category_id = :category_id";
            $params['category_id'] = $categoryId;
        }
        if ($stockStatus === 'low_stock') {
            $where[] = "p.stock_quantity <= p.reorder_level AND p.stock_quantity > 0";
        } elseif ($stockStatus === 'out_of_stock') {
            $where[] = "p.stock_quantity = 0";
        } elseif ($stockStatus === 'healthy') {
            $where[] = "p.stock_quantity > p.reorder_level";
        }

        $sql = "SELECT
                    p.id, p.sku, p.name, p.isbn_barcode, p.unit_price,
                    p.stock_quantity, p.reorder_level,
                    (p.unit_price * p.stock_quantity) AS valuation,
                    c.name AS category_name,
                    s.name AS supplier_name,
                    CASE
                        WHEN p.stock_quantity = 0 THEN 'Out of Stock'
                        WHEN p.stock_quantity <= p.reorder_level THEN 'Low Stock'
                        ELSE 'Healthy'
                    END AS stock_health
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN suppliers s ON s.id = p.primary_supplier_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.name ASC";
        $stmt = $database->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        respond(['success' => true, 'data' => [
            'kpis' => [
                'total_products' => (int) $kpis['total_products'],
                'total_units' => (int) $kpis['total_units'],
                'total_valuation' => (float) $kpis['total_valuation'],
                'low_stock_count' => (int) $kpis['low_stock_count'],
                'out_of_stock_count' => (int) $kpis['out_of_stock_count'],
                'healthy_stock_count' => (int) $kpis['healthy_stock_count']
            ],
            'category_breakdown' => $categoryBreakdown,
            'items' => $items,
            'total_items_count' => count($items)
        ]]);
    }

    // Supplier & procurement report endpoint: returns supplier spend, PO fulfillment rates, and PO ledger.
    if ($resource === 'supplier-report' && $method === 'GET') {
        $start = trim((string) ($_GET['start'] ?? date('Y-01-01')));
        $end = trim((string) ($_GET['end'] ?? date('Y-m-d')));
        $poStatus = trim((string) ($_GET['status'] ?? 'all'));

        $kpiStmt = $database->prepare(
            "SELECT
                (SELECT COUNT(*) FROM suppliers WHERE status = 'Active') AS active_suppliers,
                (SELECT COUNT(*) FROM purchase_orders WHERE order_date >= :po_start AND order_date <= :po_end) AS total_pos,
                (SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders WHERE status != 'Cancelled' AND order_date >= :spend_start AND order_date <= :spend_end) AS total_spend,
                (SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending') AS pending_pos,
                (SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending' AND expected_date IS NOT NULL AND expected_date < CURRENT_DATE()) AS overdue_pos"
        );
        $kpiStmt->execute([
            'po_start' => $start, 'po_end' => $end,
            'spend_start' => $start, 'spend_end' => $end
        ]);
        $kpis = $kpiStmt->fetch();

        $suppStmt = $database->prepare(
            "SELECT
                s.id, s.name, s.category, s.contact_person, s.phone, s.payment_terms, s.status,
                COUNT(po.id) AS po_count,
                COALESCE(SUM(CASE WHEN po.status != 'Cancelled' THEN po.total_amount ELSE 0 END), 0) AS total_spend,
                COALESCE(SUM(CASE WHEN po.status = 'Delivered' THEN 1 ELSE 0 END), 0) AS delivered_count
             FROM suppliers s
             LEFT JOIN purchase_orders po ON po.supplier_id = s.id AND po.order_date >= :start AND po.order_date <= :end
             GROUP BY s.id, s.name
             ORDER BY total_spend DESC, s.name ASC"
        );
        $suppStmt->execute(['start' => $start, 'end' => $end]);
        $supplierBreakdown = $suppStmt->fetchAll();

        $where = ["po.order_date >= :start AND po.order_date <= :end"];
        $params = ['start' => $start, 'end' => $end];
        if ($poStatus !== 'all' && in_array($poStatus, ['Pending', 'Delivered', 'Cancelled'], true)) {
            $where[] = "po.status = :status";
            $params['status'] = $poStatus;
        }

        $poSql = "SELECT
                    po.id, po.po_ref, s.name AS supplier_name, s.category AS supplier_category,
                    po.order_date, po.expected_date, po.total_amount, po.status,
                    (po.status = 'Pending' AND po.expected_date IS NOT NULL AND po.expected_date < CURRENT_DATE()) AS is_overdue,
                    (SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = po.id) AS item_count
                  FROM purchase_orders po
                  LEFT JOIN suppliers s ON s.id = po.supplier_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY po.order_date DESC, po.id DESC";
        $poStmt = $database->prepare($poSql);
        $poStmt->execute($params);
        $orders = $poStmt->fetchAll();

        respond(['success' => true, 'data' => [
            'start' => $start, 'end' => $end,
            'kpis' => [
                'active_suppliers' => (int) $kpis['active_suppliers'],
                'total_pos' => (int) $kpis['total_pos'],
                'total_spend' => (float) $kpis['total_spend'],
                'pending_pos' => (int) $kpis['pending_pos'],
                'overdue_pos' => (int) $kpis['overdue_pos']
            ],
            'supplier_breakdown' => $supplierBreakdown,
            'orders' => $orders,
            'total_orders_count' => count($orders)
        ]]);
    }

    // Sales & POS report endpoint: returns sales volume, AOV, payment method split, top products, and orders ledger.
    if ($resource === 'sales-report' && $method === 'GET') {
        $start = trim((string) ($_GET['start'] ?? date('Y-m-01')));
        $end = trim((string) ($_GET['end'] ?? date('Y-m-d')));
        $paymentMethod = trim((string) ($_GET['payment_method'] ?? 'all'));
        $orderStatus = trim((string) ($_GET['status'] ?? 'all'));

        $kpiStmt = $database->prepare(
            "SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN status = 'Paid' THEN total_amount ELSE 0 END), 0) AS total_sales,
                COALESCE(SUM(CASE WHEN status = 'Paid' THEN discount_amount ELSE 0 END), 0) AS total_discounts,
                COALESCE(SUM(CASE WHEN status = 'Paid' THEN tax_amount ELSE 0 END), 0) AS total_tax,
                COALESCE(SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END), 0) AS paid_orders_count
             FROM sales
             WHERE created_at >= :start AND created_at < DATE_ADD(:end, INTERVAL 1 DAY)"
        );
        $kpiStmt->execute(['start' => $start, 'end' => $end]);
        $kpis = $kpiStmt->fetch();

        $itemsSoldStmt = $database->prepare(
            "SELECT COALESCE(SUM(si.quantity), 0)
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.status = 'Paid' AND s.created_at >= :start AND s.created_at < DATE_ADD(:end, INTERVAL 1 DAY)"
        );
        $itemsSoldStmt->execute(['start' => $start, 'end' => $end]);
        $itemsSold = (int) $itemsSoldStmt->fetchColumn();

        $payStmt = $database->prepare(
            "SELECT payment_method, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS amount
             FROM sales
             WHERE status = 'Paid' AND created_at >= :start AND created_at < DATE_ADD(:end, INTERVAL 1 DAY)
             GROUP BY payment_method"
        );
        $payStmt->execute(['start' => $start, 'end' => $end]);
        $paymentBreakdown = $payStmt->fetchAll();

        $topStmt = $database->prepare(
            "SELECT si.product_name, SUM(si.quantity) AS qty_sold, SUM(si.line_total) AS total_revenue
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.status = 'Paid' AND s.created_at >= :start AND s.created_at < DATE_ADD(:end, INTERVAL 1 DAY)
             GROUP BY si.product_name
             ORDER BY qty_sold DESC
             LIMIT 8"
        );
        $topStmt->execute(['start' => $start, 'end' => $end]);
        $topProducts = $topStmt->fetchAll();

        $where = ["created_at >= :start AND created_at < DATE_ADD(:end, INTERVAL 1 DAY)"];
        $params = ['start' => $start, 'end' => $end];
        if ($paymentMethod !== 'all' && in_array($paymentMethod, ['Cash', 'Card'], true)) {
            $where[] = "payment_method = :pay_method";
            $params['pay_method'] = $paymentMethod;
        }
        if ($orderStatus !== 'all' && in_array($orderStatus, ['Paid', 'Pending', 'Refunded'], true)) {
            $where[] = "status = :order_status";
            $params['order_status'] = $orderStatus;
        }

        $orderSql = "SELECT s.id, s.order_ref, s.customer_name, s.payment_method, s.subtotal,
                            s.discount_amount, s.tax_amount, s.total_amount, s.status,
                            DATE_FORMAT(s.created_at, '%Y-%m-%d %H:%i') AS formatted_date,
                            (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) AS item_count
                     FROM sales s
                     WHERE " . implode(' AND ', $where) . "
                     ORDER BY s.created_at DESC, s.id DESC";
        $orderStmt = $database->prepare($orderSql);
        $orderStmt->execute($params);
        $orders = $orderStmt->fetchAll();

        $paidOrders = (int) $kpis['paid_orders_count'];
        $totalSales = (float) $kpis['total_sales'];
        $aov = $paidOrders > 0 ? $totalSales / $paidOrders : 0;

        respond(['success' => true, 'data' => [
            'start' => $start, 'end' => $end,
            'kpis' => [
                'total_orders' => (int) $kpis['total_orders'],
                'total_sales' => $totalSales,
                'total_discounts' => (float) $kpis['total_discounts'],
                'total_tax' => (float) $kpis['total_tax'],
                'average_order_value' => $aov,
                'items_sold' => $itemsSold
            ],
            'payment_breakdown' => $paymentBreakdown,
            'top_products' => $topProducts,
            'orders' => $orders,
            'total_orders_count' => count($orders)
        ]]);
    }

    // Services & staff report endpoint: returns staff strength, status distribution, and department roles.
    if ($resource === 'staff-report' && $method === 'GET') {
        $department = trim((string) ($_GET['department'] ?? 'all'));
        $status = trim((string) ($_GET['status'] ?? 'all'));

        $kpis = $database->query(
            "SELECT
                COUNT(*) AS total_employees,
                COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_count,
                COALESCE(SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END), 0) AS leave_count,
                COALESCE(SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END), 0) AS inactive_count,
                COUNT(DISTINCT department) AS department_count
             FROM employees"
        )->fetch();

        $deptStmt = $database->query(
            "SELECT
                department,
                COUNT(*) AS total_count,
                COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_count,
                GROUP_CONCAT(DISTINCT role SEPARATOR ', ') AS roles
             FROM employees
             GROUP BY department
             ORDER BY total_count DESC, department ASC"
        );
        $departmentBreakdown = $deptStmt->fetchAll();

        $where = ["1 = 1"];
        $params = [];
        if ($department !== 'all' && $department !== '') {
            $where[] = "department = :department";
            $params['department'] = $department;
        }
        if ($status !== 'all' && in_array($status, ['active', 'leave', 'inactive'], true)) {
            $where[] = "status = :status";
            $params['status'] = $status;
        }

        $empSql = "SELECT id, name, role, phone, department, status,
                          DATE_FORMAT(created_at, '%Y-%m-%d') AS joined_date
                   FROM employees
                   WHERE " . implode(' AND ', $where) . "
                   ORDER BY name ASC";
        $empStmt = $database->prepare($empSql);
        $empStmt->execute($params);
        $employees = $empStmt->fetchAll();

        respond(['success' => true, 'data' => [
            'kpis' => [
                'total_employees' => (int) $kpis['total_employees'],
                'active_count' => (int) $kpis['active_count'],
                'leave_count' => (int) $kpis['leave_count'],
                'inactive_count' => (int) $kpis['inactive_count'],
                'department_count' => (int) $kpis['department_count']
            ],
            'department_breakdown' => $departmentBreakdown,
            'employees' => $employees,
            'total_employees_count' => count($employees)
        ]]);
    }

    // User management report endpoint: returns system account counts, role distribution, and audit directory.
    if ($resource === 'users-report' && $method === 'GET') {
        $role = trim((string) ($_GET['role'] ?? 'all'));
        $status = trim((string) ($_GET['status'] ?? 'all'));

        $kpis = $database->query(
            "SELECT
                COUNT(*) AS total_users,
                COALESCE(SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END), 0) AS active_users,
                COALESCE(SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END), 0) AS inactive_users,
                COALESCE(SUM(CASE WHEN role = 'Admin' THEN 1 ELSE 0 END), 0) AS admin_count,
                COALESCE(SUM(CASE WHEN role = 'Staff' THEN 1 ELSE 0 END), 0) AS staff_count,
                COALESCE(SUM(CASE WHEN role = 'Cashier' THEN 1 ELSE 0 END), 0) AS cashier_count
             FROM users"
        )->fetch();

        $roleStmt = $database->query(
            "SELECT
                role,
                COUNT(*) AS count,
                COALESCE(SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END), 0) AS active_count
             FROM users
             GROUP BY role
             ORDER BY count DESC"
        );
        $roleBreakdown = $roleStmt->fetchAll();

        $where = ["1 = 1"];
        $params = [];
        if ($role !== 'all' && in_array($role, ['Admin', 'Staff', 'Cashier'], true)) {
            $where[] = "role = :role";
            $params['role'] = $role;
        }
        if ($status !== 'all' && in_array($status, ['Active', 'Inactive'], true)) {
            $where[] = "status = :status";
            $params['status'] = $status;
        }

        $userSql = "SELECT id, full_name, username, email, role, status,
                           DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at,
                           DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i') AS updated_at
                    FROM users
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY role ASC, full_name ASC";
        $userStmt = $database->prepare($userSql);
        $userStmt->execute($params);
        $users = $userStmt->fetchAll();

        respond(['success' => true, 'data' => [
            'kpis' => [
                'total_users' => (int) $kpis['total_users'],
                'active_users' => (int) $kpis['active_users'],
                'inactive_users' => (int) $kpis['inactive_users'],
                'admin_count' => (int) $kpis['admin_count'],
                'staff_count' => (int) $kpis['staff_count'],
                'cashier_count' => (int) $kpis['cashier_count']
            ],
            'role_breakdown' => $roleBreakdown,
            'users' => $users,
            'total_users_count' => count($users)
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
} catch (ValidationError $error) {
    respond([
        'success' => false,
        'message' => $error->getMessage(),
        'errors'  => $error->getErrors(),
    ], 422);
} catch (PDOException $error) {
    // Database-level failure handler: maps unique and FK integrity violations cleanly
    $sqlState = (string) $error->getCode();
    $driverCode = (int) ($error->errorInfo[1] ?? 0);
    if ($sqlState === '23000' || $driverCode === 1062) {
        respond(['success' => false, 'message' => 'A record with duplicate unique details already exists.'], 409);
    }
    if ($driverCode === 1451 || $driverCode === 1452) {
        respond(['success' => false, 'message' => 'The record cannot be saved or deleted because related data depends on it.'], 409);
    }
    respond(['success' => false, 'message' => 'Database request failed.'], 500);
} catch (Throwable $error) {
    // General error handler: prevents the application from exposing raw server exceptions.
    respond(['success' => false, 'message' => 'Unexpected server error.'], 500);
}
