# Bookwise Architecture Description

## 1. Architecture Summary

Bookwise uses a simple three-layer web application structure:

1. **Presentation layer** - static HTML pages, CSS, and browser JavaScript.
2. **Application/API layer** - a PHP JSON endpoint that validates requests and executes application operations.
3. **Data layer** - a MySQL database containing the domain tables and relationships.

The design is intentionally small and easy to demonstrate. Each page represents one business module and communicates with the PHP API using browser `fetch` requests.

## 2. High-Level Architecture

```text
User Browser
    |
    | HTML pages, CSS, JavaScript, fetch()
    v
Apache / WAMP Virtual Host
    |
    +--> Static frontend files
    |      index.html
    |      inventory.html
    |      suppliers.html
    |      orders-pos.html
    |      user-management.html
    |      services-staff.html
    |      financials.html
    |
    +--> PHP API
           api/index.php
           api/config/database.php
                    |
                    | PDO / MySQL connection
                    v
                MySQL: bookwise
```

## 3. Project Structure

```text
Bookwise/
├── index.html
├── inventory.html
├── add-product.html
├── suppliers.html
├── add-supplier.html
├── create-purchase-order.html
├── orders-pos.html
├── user-management.html
├── services-staff.html
├── financials.html
├── financial-report.html
├── style.css
├── script.js
├── .htaccess
├── api/
│   ├── .htaccess
│   ├── index.php
│   └── config/
│       ├── database.php
│       ├── database.example.php
│       └── database.local.php
└── database/
    ├── schema.sql
    └── seed.sql
```

## 4. Presentation Layer

The presentation layer is made up of static HTML pages. Each page:

- Displays one functional module.
- Uses the shared `style.css` design system where appropriate.
- Includes module-specific markup and behavior.
- Uses JavaScript event handlers for forms, searches, filters, and buttons.
- Calls the API with relative URLs such as `api/index.php?resource=products`.

The landing page provides a central entry point to the modules. The sidebar provides navigation between the management screens.

## 5. API/Application Layer

The main application entry point is [api/index.php](D:/SCU/Y2%20S2/PPA/Bookwise/api/index.php).

The API uses a lightweight resource-based routing approach:

```text
GET    /api/index.php?resource=products
POST   /api/index.php?resource=products
PUT    /api/index.php?resource=products&id=1
DELETE /api/index.php?resource=products&id=1
```

The same endpoint handles these resources:

- `categories`
- `products`
- `suppliers`
- `sales`
- `users`
- `employees`
- `financials`
- `expenses`

### API responsibilities

- Read the HTTP method and resource.
- Parse JSON request bodies.
- Validate required fields and numeric values.
- Execute parameterized PDO queries.
- Return JSON responses.
- Return HTTP status codes for success, validation errors, missing records, and server failures.

### Error handling

The API uses:

- HTTP `201` for successful creation.
- HTTP `422` for invalid input.
- HTTP `404` for missing endpoints or records.
- HTTP `500` for database or unexpected server errors.

Database exception details are not exposed to browser users.

## 6. Data Layer

The database connection is defined in [api/config/database.php](D:/SCU/Y2%20S2/PPA/Bookwise/api/config/database.php).

The connection uses:

- PDO.
- MySQL.
- UTF-8 character encoding.
- Exception mode.
- Associative-array fetch mode.
- Native prepared statements.

The database name is `bookwise`. Local credentials are kept outside source control in `api/config/database.local.php`, which is excluded through `.gitignore`.

## 7. Data Model

```text
categories 1 ---- many products
suppliers  1 ---- many products
sales      1 ---- many sale_items
```

### Main entities

- **categories** - product categories such as Books and Stationery.
- **suppliers** - supplier contact, category, payment, and status information.
- **products** - product name, SKU, category, price, stock, reorder level, and supplier.
- **sales** - completed sale header, payment method, totals, and status.
- **sale_items** - products recorded within each sale.
- **users** - system user accounts, roles, and status.
- **employees** - employee contact, role, department, and employment status.
- **expenses** - expense reference, type, description, amount, and date.

The product-to-category and product-to-supplier relationships use foreign keys. Sale items are linked to a sale and are deleted when their parent sale is deleted.

## 8. Module Data Flows

### Inventory flow

```text
inventory.html
    -> GET products
    -> display products
add-product.html
    -> POST product
    -> products table
inventory.html
    -> DELETE/deactivate product
```

### Supplier flow

```text
suppliers.html
    -> GET suppliers
    -> display/search/filter suppliers
add-supplier.html
    -> POST supplier
    -> suppliers table
suppliers.html
    -> DELETE/deactivate supplier
```

### POS flow

```text
orders-pos.html
    -> user builds cart in browser
    -> POST sale and sale items
    -> sales and sale_items tables
    -> GET recent sales
```

### User and staff flow

```text
user-management.html
    -> GET users
    -> POST user
    -> PATCH user status

services-staff.html
    -> GET employees
    -> POST employee
    -> PUT employee
    -> DELETE/deactivate employee
```

### Financial flow

```text
financials.html
    -> GET financial summary
       sales totals + expense totals + recent transactions
    -> POST expense
    -> refresh financial summary
```

## 9. Deployment and Runtime Environment

The current local deployment uses:

- WampServer.
- Apache virtual host `bookwise.local`.
- PHP running through Apache.
- MySQL running locally.
- Git working tree kept outside the default WAMP web root.

The Apache virtual host points to the Git project directory. `.htaccess` provides the default landing page, while `api/.htaccess` supports API routing when clean resource URLs are used.

## 10. Security and Reliability Measures in Scope

- SQL statements use PDO prepared statements.
- User input is validated before database insertion.
- Numeric product, stock, sales, and expense values are checked.
- Database credentials are stored in an ignored local configuration file.
- HTML output generated from API data is escaped in the main dynamic pages.
- Deactivation is preferred over physical deletion for products, suppliers, employees, and users where appropriate.

## 11. Current Architectural Boundaries

The following boundaries are deliberate for the assignment:

- Authentication is not implemented.
- The frontend does not use a JavaScript framework.
- Business logic is kept inside a single PHP API entry point.
- Purchase orders and shifts remain browser demonstrations and are not yet persistent database modules.
- POS sales store the sale item name and price snapshot, which keeps completed receipts understandable even if a product later changes.

This architecture is sufficient for demonstrating connected frontend modules, database persistence, validation, and CRUD operations without introducing unnecessary production complexity.

