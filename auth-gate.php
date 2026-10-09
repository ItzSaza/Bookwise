<?php
declare(strict_types=1);
session_start();
$requested = basename((string)($_GET['page'] ?? ''));
$allowed = [
    'add-product.html', 'add-supplier.html', 'create-purchase-order.html',
    'financial-report.html', 'financials.html', 'inventory.html',
    'orders-pos.html', 'services-staff.html', 'supplier-report.html',
    'suppliers.html', 'user-management.html'
];
if (!in_array($requested, $allowed, true)) {
    http_response_code(404);
    exit('Page not found.');
}
if (empty($_SESSION['user'])) {
    header('Location: login.html', true, 302);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . DIRECTORY_SEPARATOR . $requested);
