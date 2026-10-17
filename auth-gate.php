<?php
declare(strict_types=1);
session_start();
$requested = basename((string)($_GET['page'] ?? ''));
$allowed = [
    'add-product.html', 'add-supplier.html', 'create-purchase-order.html',
    'financial-report.html', 'financials.html', 'inventory.html',
    'inventory-report.html', 'orders-pos.html', 'sales-report.html',
    'services-staff.html', 'staff-report.html', 'supplier-report.html',
    'suppliers.html', 'user-management.html', 'users-report.html'
];
if (!in_array($requested, $allowed, true)) {
    http_response_code(404);
    exit('Page not found.');
}
if (empty($_SESSION['user'])) {
    header('Location: login.html', true, 302);
    exit;
}

$role = $_SESSION['user']['role'] ?? '';

// Role-based permissions matrix:
// 1. Admin: full access to all application modules and reports
// 2. Staff: Inventory, Suppliers, Orders & POS, Financials (and sub-pages); NO User Management or Services & Staff
// 3. Cashier: Orders & POS (orders-pos.html, sales-report.html)
if ($role === 'Cashier') {
    $cashierAllowed = ['orders-pos.html', 'sales-report.html'];
    if (!in_array($requested, $cashierAllowed, true)) {
        header('Location: orders-pos.html', true, 302);
        exit;
    }
} elseif ($role === 'Staff') {
    $staffForbidden = ['user-management.html', 'users-report.html', 'services-staff.html', 'staff-report.html'];
    if (in_array($requested, $staffForbidden, true)) {
        header('Location: inventory.html', true, 302);
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
readfile(__DIR__ . DIRECTORY_SEPARATOR . $requested);
