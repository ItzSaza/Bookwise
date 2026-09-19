# Bookwise Functionality Report

## 1. Project Overview

Bookwise is an online bookshop management console for Minre Book Shop. The system provides a browser-based interface for managing products, suppliers, sales, users, employees, shifts, and financial information.

The project uses a simple PHP and MySQL backend so that each major frontend module can demonstrate database connectivity and CRUD-style operations. The implementation intentionally uses simple forms, seeded demo data, and clear workflows suitable for a university assignment demonstration.

## 2. Completed Functional Modules

### 2.1 User Management

**Purpose:** Manage system accounts and their roles.

**Implemented functionality:**

- View users loaded from MySQL.
- Search users by name.
- Filter users by role.
- Filter users by active or inactive status.
- Add a new user.
- Activate or deactivate a user.
- Display active, inactive, administrator, and staff counts.

**Database operations:**

- `GET /api/index.php?resource=users`
- `POST /api/index.php?resource=users`
- `PATCH /api/index.php?resource=users&id={id}`

**CRUD coverage:** Create, Read, and Update.

### 2.2 Inventory Management

**Purpose:** Manage products and monitor stock levels.

**Implemented functionality:**

- View products loaded from MySQL.
- Search products by name.
- Filter products by category.
- Add a product.
- Automatically generate a demonstration SKU.
- Keep barcode or ISBN optional.
- Display low-stock warnings based on reorder level.
- Deactivate a product.

**Database operations:**

- `GET /api/index.php?resource=products`
- `GET /api/index.php?resource=categories`
- `GET /api/index.php?resource=suppliers`
- `POST /api/index.php?resource=products`
- `PUT /api/index.php?resource=products&id={id}`
- `DELETE /api/index.php?resource=products&id={id}`

**CRUD coverage:** Create, Read, Update, and Delete/deactivate.

### 2.3 Supplier Management

**Purpose:** Maintain supplier contact and business information.

**Implemented functionality:**

- View suppliers loaded from MySQL.
- Search suppliers by name.
- Filter suppliers by product category.
- Filter inactive suppliers.
- Add a supplier.
- Deactivate a supplier.
- Display supplier contact information and payment terms.

**Database operations:**

- `GET /api/index.php?resource=suppliers`
- `POST /api/index.php?resource=suppliers`
- `DELETE /api/index.php?resource=suppliers&id={id}`

**CRUD coverage:** Create, Read, and Delete/deactivate.

### 2.4 Orders and Point of Sale

**Purpose:** Create walk-in customer sales and review recent orders.

**Implemented functionality:**

- Browse products in the POS screen.
- Search products by title, author, or SKU.
- Add products to a cart.
- Increase or decrease item quantities.
- Remove items from a cart.
- Apply a percentage discount.
- Calculate tax and final total.
- Select Cash or Card payment.
- Complete and save a sale.
- Display a sale receipt.
- Load recent saved orders from MySQL.

**Database operations:**

- `GET /api/index.php?resource=sales`
- `POST /api/index.php?resource=sales`

**CRUD coverage:** Create and Read.

### 2.5 Services and Staff

**Purpose:** Manage employee records and basic staff information.

**Implemented functionality:**

- View employees loaded from MySQL.
- Search employees by name.
- Filter employees by role or department.
- Filter inactive employees.
- Add an employee.
- Edit employee details.
- Deactivate an employee.
- Display employee totals and leave counts.
- Maintain a simple browser-based shift schedule demonstration.

**Database operations:**

- `GET /api/index.php?resource=employees`
- `POST /api/index.php?resource=employees`
- `PUT /api/index.php?resource=employees&id={id}`
- `DELETE /api/index.php?resource=employees&id={id}`

**CRUD coverage:** Create, Read, Update, and Delete/deactivate.

### 2.6 Financial Management

**Purpose:** Display sales income, expenses, and profit information.

**Implemented functionality:**

- Calculate current-month revenue from paid sales.
- Calculate current-month expenses.
- Calculate net profit.
- Display recent sales and expense transactions.
- Add an expense using a simple form prompt.
- Generate a link to the existing financial report page.

**Database operations:**

- `GET /api/index.php?resource=financials`
- `POST /api/index.php?resource=expenses`

**CRUD coverage:** Create and Read.

## 3. Shared Frontend Functionality

- Shared Bookwise visual theme with navy, burgundy, gold, cream, and white interface elements.
- Shared sidebar navigation between modules.
- Landing page at `index.html`.
- Responsive layout support for smaller screens.
- Apache directory index configuration through `.htaccess`.
- User-friendly validation messages for missing or invalid values.
- Database errors are returned as JSON responses instead of exposing database details.

## 4. Database Entities

The MySQL database is named `bookwise` and currently contains:

- `categories`
- `products`
- `suppliers`
- `sales`
- `sale_items`
- `users`
- `employees`
- `expenses`

The schema is defined in `database/schema.sql`. Demonstration records are provided in `database/seed.sql`.

## 5. Assignment CRUD Summary

| Module | Create | Read | Update | Delete/deactivate |
|---|---:|---:|---:|---:|
| User Management | Yes | Yes | Yes | Status update |
| Inventory | Yes | Yes | Yes | Yes |
| Suppliers | Yes | Yes | No | Yes |
| Orders and POS | Yes | Yes | No | Not required |
| Services and Staff | Yes | Yes | Yes | Yes |
| Financials | Yes | Yes | No | Not required |

Every major module has at least two demonstrable operations, satisfying the agreed assignment scope.

## 6. Demonstration Flow

1. Open `http://bookwise.local/`.
2. Open Inventory and demonstrate product search, filtering, adding, and deactivation.
3. Open Suppliers and demonstrate supplier search, filtering, adding, and deactivation.
4. Open Orders & POS, add a product to the cart, complete a sale, and refresh the page.
5. Open User Management and demonstrate adding and activating/deactivating a user.
6. Open Services & Staff and demonstrate adding or editing an employee.
7. Open Financials and show the saved POS sale in revenue and recent transactions.
8. Add a sample expense and show the updated profit calculation.

## 7. Known Scope Limitations

The following items are intentionally outside the simple assignment scope:

- Real authentication and password login.
- Barcode scanner hardware integration.
- Real payment gateway integration.
- Automated purchase-order persistence.
- Persistent shift scheduling.
- Payroll processing.
- Advanced financial reporting.
- Multi-branch stock management.
- Production-level authorization and audit logging.

These can be added later if the assignment requires them, but they are not necessary for the current CRUD and database-connectivity demonstration.

