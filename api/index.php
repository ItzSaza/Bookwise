<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
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
    $apiPath = preg_replace('#^.*?/api/?#', '', $path);
    $segments = $apiPath === '' ? [] : explode('/', $apiPath);
    $resource = $_GET['resource'] ?? ($segments[0] ?? '');
    $id = isset($segments[1])
        ? filter_var($segments[1], FILTER_VALIDATE_INT)
        : (isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null);
    $method = $_SERVER['REQUEST_METHOD'];
    $body = requestBody();

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
                'full_name' => $user['full_name'],
                'role'      => $user['role'],
            ],
        ]);
    }

    if (empty($_SESSION['user'])) {
        respond(['success' => false, 'message' => 'Please log in to access the system.'], 401);
    }

    // Category lookup endpoint: returns the available inventory categories.
    if ($resource === 'categories' && $method === 'GET') {
        $categories = $database->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
        respond(['success' => true, 'data' => $categories]);
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
        respond(['success' => true, 'message' => 'Supplier added successfully.', 'id' => (int) $database->lastInsertId()], 201);
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
        respond(['success' => true, 'message' => 'Supplier updated successfully.']);
    }

    // Supplier deletion endpoint: removes a supplier, or marks Inactive if referenced in orders/products.
    if ($resource === 'suppliers' && $id && $method === 'DELETE') {
        try {
            $statement = $database->prepare('DELETE FROM suppliers WHERE id = :id');
            $statement->execute(['id' => $id]);
            if ($statement->rowCount() === 0) {
                respond(['success' => false, 'message' => 'Supplier was not found.'], 404);
            }
            respond(['success' => true, 'message' => 'Supplier deleted successfully.']);
        } catch (PDOException $e) {
            $statement = $database->prepare("UPDATE suppliers SET status = 'Inactive' WHERE id = :id");
            $statement->execute(['id' => $id]);
            respond(['success' => true, 'message' => 'Supplier is referenced by orders/products; marked as Inactive instead.']);
        }
    }

        // ===== SUPPLIER MODULE ADDITIONS =====

    // Orders above this amount need Admin approval before goods can be received.
    $approvalThreshold = 20000.00;

    // Delivery statistics per supplier (used for the Reliability badge).
    if ($resource === 'supplier_stats' && $method === 'GET') {
        $statement = $database->query(
            "SELECT supplier_id,
                    SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN status = 'Delivered'
                              AND (expected_date IS NULL OR DATE(delivered_at) <= expected_date)
                             THEN 1 ELSE 0 END) AS on_time_count
             FROM purchase_orders
             GROUP BY supplier_id"
        );
        respond(['success' => true, 'data' => $statement->fetchAll()]);
    }

    // Products at or below their reorder level, with their linked supplier.
    if ($resource === 'low_stock' && $method === 'GET') {
        $statement = $database->query(
            "SELECT p.id, p.name, p.stock_quantity, p.reorder_level, p.unit_price,
                    s.id AS supplier_id, s.name AS supplier_name
             FROM products p
             INNER JOIN suppliers s ON s.id = p.primary_supplier_id
             WHERE p.status = 'Active' AND s.status = 'Active'
               AND p.stock_quantity <= p.reorder_level
             ORDER BY (CAST(p.reorder_level AS SIGNED) - CAST(p.stock_quantity AS SIGNED)) DESC"
        );
        respond(['success' => true, 'data' => $statement->fetchAll()]);
    }

    // Compare suppliers who have supplied a product before (cost, lead time, reliability).
    if ($resource === 'supplier_recommendations' && $method === 'GET') {
        $productName = trim((string) ($_GET['product_name'] ?? ''));
        if ($productName === '') {
            respond(['success' => false, 'message' => 'A product name is required.'], 422);
        }
        $statement = $database->prepare(
            "SELECT s.id AS supplier_id, s.name AS supplier_name,
                    ROUND(AVG(poi.unit_cost), 2) AS avg_cost,
                    COUNT(DISTINCT po.id) AS order_count,
                    ROUND(AVG(CASE WHEN po.status = 'Delivered'
                                   THEN DATEDIFF(po.delivered_at, po.order_date) END), 1) AS avg_lead_time_days,
                    SUM(CASE WHEN po.status = 'Delivered' THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN po.status = 'Delivered'
                              AND (po.expected_date IS NULL OR DATE(po.delivered_at) <= po.expected_date)
                             THEN 1 ELSE 0 END) AS on_time_count
             FROM purchase_order_items poi
             INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
             INNER JOIN suppliers s ON s.id = po.supplier_id
             WHERE poi.product_name LIKE :product_name
               AND s.status = 'Active' AND po.status <> 'Cancelled'
             GROUP BY s.id, s.name
             ORDER BY avg_cost ASC"
        );
        $statement->execute(['product_name' => '%' . $productName . '%']);
        respond(['success' => true, 'data' => $statement->fetchAll()]);
    }

    // Purchase order list.
    if ($resource === 'purchase_orders' && $method === 'GET' && !$id) {
        $statement = $database->query(
            "SELECT po.id, po.po_ref, po.supplier_id, s.name AS supplier_name,
                    po.order_date, po.expected_date, po.status, po.approval_status, po.total_amount,
                    CASE WHEN po.status = 'Pending' AND po.expected_date IS NOT NULL
                              AND po.expected_date < CURDATE() THEN 1 ELSE 0 END AS is_overdue,
                    COALESCE(recv.receipt_status, 'Not Yet Received') AS receipt_status
             FROM purchase_orders po
             INNER JOIN suppliers s ON s.id = po.supplier_id
             LEFT JOIN (
                 SELECT purchase_order_id,
                        CASE
                            WHEN SUM(received_quantity) IS NULL THEN 'Not Yet Received'
                            WHEN SUM(received_quantity) = SUM(quantity) THEN 'Fully Received'
                            WHEN SUM(received_quantity) < SUM(quantity) THEN 'Partially Received'
                            ELSE 'Over-Received'
                        END AS receipt_status
                 FROM purchase_order_items
                 GROUP BY purchase_order_id
             ) recv ON recv.purchase_order_id = po.id
             ORDER BY po.order_date DESC, po.id DESC"
        );
        respond(['success' => true, 'data' => $statement->fetchAll()]);
    }

    // Single purchase order with its items (used by the Receive page).
    if ($resource === 'purchase_orders' && $method === 'GET' && $id) {
        $statement = $database->prepare(
            'SELECT po.id, po.po_ref, po.supplier_id, s.name AS supplier_name,
                    po.order_date, po.expected_date, po.status, po.approval_status,
                    po.notes, po.total_amount
             FROM purchase_orders po
             INNER JOIN suppliers s ON s.id = po.supplier_id
             WHERE po.id = :id'
        );
        $statement->execute(['id' => $id]);
        $order = $statement->fetch();
        if (!$order) {
            respond(['success' => false, 'message' => 'Purchase order was not found.'], 404);
        }
        $itemStatement = $database->prepare(
            'SELECT id, product_name, quantity, unit_cost, line_total, received_quantity
             FROM purchase_order_items WHERE purchase_order_id = :id'
        );
        $itemStatement->execute(['id' => $id]);
        $order['items'] = $itemStatement->fetchAll();
        respond(['success' => true, 'data' => $order]);
    }

    // Create a purchase order (with validation). Orders over the threshold wait for approval.
    if ($resource === 'purchase_orders' && $method === 'POST') {
        $validDate = static function (?string $value): bool {
            if ($value === null) {
                return false;
            }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $parsed !== false && $parsed->format('Y-m-d') === $value;
        };

        $supplierId = filter_var($body['supplier_id'] ?? null, FILTER_VALIDATE_INT);
        $orderDate = trim((string) ($body['order_date'] ?? '')) ?: date('Y-m-d');
        $expectedDate = trim((string) ($body['expected_date'] ?? '')) ?: null;
        $notes = trim((string) ($body['notes'] ?? '')) ?: null;
        $rawItems = is_array($body['items'] ?? null) ? $body['items'] : [];

        if (!$supplierId || count($rawItems) === 0) {
            respond(['success' => false, 'message' => 'Supplier and at least one item are required.'], 422);
        }
        if (!$validDate($orderDate) || ($expectedDate !== null && !$validDate($expectedDate))) {
            respond(['success' => false, 'message' => 'Enter valid dates.'], 422);
        }
        if ($expectedDate !== null && $expectedDate < $orderDate) {
            respond(['success' => false, 'message' => 'Expected delivery cannot be before the order date.'], 422);
        }

        $supplierCheck = $database->prepare('SELECT status FROM suppliers WHERE id = :id');
        $supplierCheck->execute(['id' => $supplierId]);
        $supplierStatus = $supplierCheck->fetchColumn();
        if ($supplierStatus !== 'Active') {
            respond(['success' => false, 'message' => 'Choose an active supplier.'], 422);
        }

        $poItems = [];
        $poTotal = 0.0;
        foreach ($rawItems as $raw) {
            $name = trim((string) ($raw['product_name'] ?? ''));
            $qty = filter_var($raw['quantity'] ?? null, FILTER_VALIDATE_INT);
            $cost = filter_var($raw['unit_cost'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($name === '' || strlen($name) > 180 || $qty === false || $qty < 1 || $cost === false || $cost < 0) {
                respond(['success' => false, 'message' => 'Every item needs a name, a quantity of 1 or more, and a valid cost.'], 422);
            }
            $poItems[] = ['name' => $name, 'qty' => $qty, 'cost' => $cost, 'line' => $qty * $cost];
            $poTotal += $qty * $cost;
        }

        $approvalStatus = $poTotal > $approvalThreshold ? 'Pending Approval' : 'Not Required';
        $nextNumber = (int) $database->query(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(po_ref, 4) AS UNSIGNED)), 0) + 1 FROM purchase_orders"
        )->fetchColumn();
        $poRef = 'PO-' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);

        $database->beginTransaction();
        $statement = $database->prepare(
            'INSERT INTO purchase_orders
                (po_ref, supplier_id, order_date, expected_date, notes, total_amount, approval_status)
             VALUES (:po_ref, :supplier_id, :order_date, :expected_date, :notes, :total_amount, :approval_status)'
        );
        $statement->execute([
            'po_ref' => $poRef,
            'supplier_id' => $supplierId,
            'order_date' => $orderDate,
            'expected_date' => $expectedDate,
            'notes' => $notes,
            'total_amount' => $poTotal,
            'approval_status' => $approvalStatus,
        ]);
        $poId = (int) $database->lastInsertId();
        $itemStatement = $database->prepare(
            'INSERT INTO purchase_order_items (purchase_order_id, product_name, quantity, unit_cost, line_total)
             VALUES (:po_id, :name, :qty, :cost, :line)'
        );
        foreach ($poItems as $poItem) {
            $itemStatement->execute([
                'po_id' => $poId,
                'name' => $poItem['name'],
                'qty' => $poItem['qty'],
                'cost' => $poItem['cost'],
                'line' => $poItem['line'],
            ]);
        }
        $database->commit();

        respond([
            'success' => true,
            'message' => $approvalStatus === 'Pending Approval'
                ? 'Purchase order created. It is over Rs. ' . number_format($approvalThreshold) . ' so it needs Admin approval.'
                : 'Purchase order created successfully.',
            'po_ref' => $poRef,
            'approval_status' => $approvalStatus,
        ], 201);
    }

    // Purchase order actions: approve, reject, receive goods (or simple status change).
    if ($resource === 'purchase_orders' && $id && $method === 'PATCH') {
        $action = $body['action'] ?? null;

        if ($action === 'approve' || $action === 'reject') {
            $newApproval = $action === 'approve' ? 'Approved' : 'Rejected';
            $newStatus = $action === 'approve' ? 'Pending' : 'Cancelled';
            $statement = $database->prepare(
                "UPDATE purchase_orders SET approval_status = :approval, status = :status
                 WHERE id = :id AND approval_status = 'Pending Approval'"
            );
            $statement->execute(['approval' => $newApproval, 'status' => $newStatus, 'id' => $id]);
            if ($statement->rowCount() === 0) {
                respond(['success' => false, 'message' => 'Order was not found or is not waiting for approval.'], 404);
            }
            respond(['success' => true, 'message' => $action === 'approve' ? 'Purchase order approved.' : 'Purchase order rejected and cancelled.']);
        }

        if ($action === 'receive') {
            $orderStatement = $database->prepare('SELECT status, approval_status FROM purchase_orders WHERE id = :id');
            $orderStatement->execute(['id' => $id]);
            $order = $orderStatement->fetch();
            if (!$order) {
                respond(['success' => false, 'message' => 'Purchase order was not found.'], 404);
            }
            if ($order['status'] !== 'Pending') {
                respond(['success' => false, 'message' => 'Only pending orders can be received.'], 422);
            }
            if ($order['approval_status'] === 'Pending Approval') {
                respond(['success' => false, 'message' => 'This order must be approved before it can be received.'], 422);
            }

            $idStatement = $database->prepare('SELECT id FROM purchase_order_items WHERE purchase_order_id = :id');
            $idStatement->execute(['id' => $id]);
            $validItemIds = array_map('intval', array_column($idStatement->fetchAll(), 'id'));

            $rows = is_array($body['received_items'] ?? null) ? $body['received_items'] : [];
            $received = [];
            foreach ($rows as $row) {
                $itemId = filter_var($row['item_id'] ?? null, FILTER_VALIDATE_INT);
                $qty = filter_var($row['received_quantity'] ?? null, FILTER_VALIDATE_INT);
                if ($itemId === false || $qty === false || $qty < 0 || !in_array($itemId, $validItemIds, true)) {
                    respond(['success' => false, 'message' => 'Received quantities contain invalid values.'], 422);
                }
                $received[$itemId] = $qty;
            }
            if (count($received) !== count($validItemIds)) {
                respond(['success' => false, 'message' => 'Enter a received quantity for every item.'], 422);
            }

            $database->beginTransaction();
            $itemUpdate = $database->prepare('UPDATE purchase_order_items SET received_quantity = :qty WHERE id = :item_id');
            $stockUpdate = $database->prepare(
                "UPDATE products p
                 INNER JOIN purchase_order_items poi ON poi.product_name = p.name
                 SET p.stock_quantity = p.stock_quantity + :qty
                 WHERE poi.id = :item_id AND p.status = 'Active'"
            );
            foreach ($received as $itemId => $qty) {
                $itemUpdate->execute(['qty' => $qty, 'item_id' => $itemId]);
                if ($qty > 0) {
                    $stockUpdate->execute(['qty' => $qty, 'item_id' => $itemId]);
                }
            }
            $database->prepare("UPDATE purchase_orders SET status = 'Delivered', delivered_at = NOW() WHERE id = :id")
                ->execute(['id' => $id]);
            $database->commit();
            respond(['success' => true, 'message' => 'Goods receipt saved, stock updated, and order marked delivered.']);
        }

        respond(['success' => false, 'message' => 'A valid action is required.'], 422);
    }
    // ===== END OF SUPPLIER MODULE ADDITIONS =====

    // Purchase order endpoints
    if ($resource === 'purchase_orders' && $method === 'GET') {
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

    if ($resource === 'purchase_orders' && $id && $method === 'DELETE') {
        try {
            $statement = $database->prepare("UPDATE purchase_orders SET status = 'Cancelled' WHERE id = :id");
            $statement->execute(['id' => $id]);
            respond(['success' => true, 'message' => 'Purchase order cancelled.']);
        } catch (PDOException $e) {
            respond(['success' => false, 'message' => 'Failed to cancel purchase order.'], 500);
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
            respond(['success' => true, 'message' => 'Purchase order created successfully.', 'po_ref' => $poRef], 201);
        } catch (PDOException $e) {
            respond(['success' => false, 'message' => 'Could not save purchase order: ' . $e->getMessage()], 500);
        }
    }


    // Sales list endpoint: returns recent sales records with totals and basic summary data.
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

    // Sale creation endpoint: validates items, inserts the sales header, and creates item rows in one transaction.
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
        respond(['success' => true, 'message' => 'User added successfully.', 'id' => (int) $database->lastInsertId()], 201);
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
        respond(['success' => true, 'message' => 'User updated successfully.']);
    }

    // User status update endpoint: toggles the active/inactive status for an individual user.
    if ($resource === 'users' && $id && $method === 'PATCH') {
        $status = (($body['status'] ?? '') === 'Active') ? 'Active' : 'Inactive';
        $statement = $database->prepare('UPDATE users SET status = :status WHERE id = :id');
        $statement->execute(['status' => $status, 'id' => $id]);
        if ($statement->rowCount() === 0) {
            respond(['success' => false, 'message' => 'User was not found or no changes were made.'], 404);
        }
        respond(['success' => true, 'message' => 'User status updated successfully.']);
    }

    // User deletion endpoint: permanently deletes a user.
    if ($resource === 'users' && $id && $method === 'DELETE') {
        $statement = $database->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            respond(['success' => false, 'message' => 'User was not found.'], 404);
        }
        respond(['success' => true, 'message' => 'User deleted successfully.']);
    }

    // Employee lookup endpoint: returns all employee records sorted by name.
    if ($resource === 'employees' && $method === 'GET') {
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
        respond(['success' => true, 'message' => 'Employee added successfully.', 'id' => (int) $database->lastInsertId()], 201);
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
        respond(['success' => true, 'message' => 'Employee updated successfully.']);
    }

    // Employee deletion endpoint: removes an employee record.
    if ($resource === 'employees' && $id && $method === 'DELETE') {
        $statement = $database->prepare('DELETE FROM employees WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            respond(['success' => false, 'message' => 'Employee was not found.'], 404);
        }
        respond(['success' => true, 'message' => 'Employee deleted successfully.']);
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
        respond(['success' => true, 'message' => 'Expense added successfully.', 'expense_ref' => $expenseRef], 201);
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
        respond(['success' => true, 'message' => 'Expense updated successfully.']);
    }

    // Expense deletion endpoint: removes the selected expense record.
    if ($resource === 'expenses' && $id && $method === 'DELETE') {
        $statement = $database->prepare('DELETE FROM expenses WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            respond(['success' => false, 'message' => 'Expense was not found.'], 404);
        }
        respond(['success' => true, 'message' => 'Expense deleted successfully.']);
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
        respond(['success' => true, 'message' => 'Product added successfully.', 'id' => (int) $database->lastInsertId()], 201);
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
        respond(['success' => true, 'message' => 'Product updated successfully.']);
    }

    // Product deletion endpoint: removes product, or marks Inactive if referenced in orders.
    if ($resource === 'products' && $id && $method === 'DELETE') {
        try {
            $statement = $database->prepare('DELETE FROM products WHERE id = :id');
            $statement->execute(['id' => $id]);
            if ($statement->rowCount() === 0) {
                respond(['success' => false, 'message' => 'Product was not found.'], 404);
            }
            respond(['success' => true, 'message' => 'Product deleted successfully.']);
        } catch (PDOException $e) {
            $statement = $database->prepare("UPDATE products SET status = 'Inactive' WHERE id = :id");
            $statement->execute(['id' => $id]);
            respond(['success' => true, 'message' => 'Product is referenced by sales records; marked as Inactive instead.']);
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
