# ERP ↔ E-commerce Connector (Base Project)

Laravel integration hub that syncs products, inventory, customers, orders, and dispatch between an **ERP** and **e-commerce** platform. Each deployment uses one pair:

| Deployment | ERP | E-commerce |
|------------|-----|------------|
| Reference A | **Odoo** | Shopify |
| Reference B | **ERPNext** | Shopify |

Field mappings, sync direction, and API paths are driven by **Field Config** (`product_field_configs`) — not hardcoded in PHP. Odoo and ERPNext can coexist in the codebase; only the active `erp_driver` setting is used at runtime.

See [docs/ADDING_A_DRIVER.md](docs/ADDING_A_DRIVER.md) for adding new platforms.

---

## Requirements

- PHP 8.2+
- Composer
- MySQL / MariaDB
- Node.js (optional, for front-end asset builds)

---

## Local development

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

Open **http://127.0.0.1:8000** and sign in.

Configure credentials under **Dashboard → Settings**:

1. **Global Settings** — set **ERP Driver** slug (`odoo`, `erpnext`, …) and **ERP Display Name** (label in UI)
2. **ERP Settings** — connection fields for the active driver (auto-created from `config/connectors.php`)
3. **E-commerce Settings** — Shopify (or other) credentials

---

## ERPNext + Shopify setup

Typical mixed-direction deployment:

| Entity | Direction | Flow |
|--------|-----------|------|
| Products | `ecom_to_erp` | Shopify → ERPNext |
| Inventory | `ecom_to_erp` | Shopify → ERPNext |
| Customers | `erp_to_ecom` | ERPNext → Shopify |
| Sales orders | `erp_to_ecom` | ERPNext → Shopify |
| Dispatch | `erp_to_ecom` | ERPNext Delivery Note → Shopify fulfillment |

Set directions in **Dashboard → Global Settings** (sync mode per entity).

### 1. Migrations (schema only)

```bash
php artisan migrate
```

Field config **data** is not applied by migrations on production — use the SQL seeds below.

### 2. ERPNext credentials

**Dashboard → ERP Settings** (when `erp_driver = erpnext`):

| Setting | Example |
|---------|---------|
| Site URL | `https://mycompany.erpnext.com` |
| API Key / Secret | User → Settings → API Access → Generate Keys |
| Default Warehouse | `Stores - YC` |
| Warehouse Map | JSON: ERPNext warehouse name → Shopify location GID |
| Selling Price List | e.g. `Standard Selling` |

Use the **ERPNext site URL only** — no `/app`, `/api`, or connector path.

Optional `.env` overrides:

```env
ERP_DRIVER=erpnext
ERPNEXT_URL=https://mycompany.erpnext.com
ERPNEXT_API_KEY=
ERPNEXT_API_SECRET=
```

### 3. SQL seeds (run on server)

Run in phpMyAdmin or MySQL CLI **after** migrations:

| File | Purpose |
|------|---------|
| `database/sql/erpnext_seed.sql` | Full Shopify ↔ ERPNext field configs + fixes |
| `database/sql/erpnext_reverse_sync.sql` | Mixed sync directions + enable flags |
| `database/sql/erpnext_product_ecom_to_erp.sql` | Product Shopify → ERPNext only (partial patch) |

Then always:

```bash
php artisan cache:clear
```

### 4. Field Config

**Dashboard → Field Config** (Products, Customers, Orders, Inventory, Dispatch):

- **E-commerce field** — Shopify GraphQL path (e.g. `defaultAddress.city`, `inventoryLevel.quantities.0.quantity`)
- **ERPNext field** — Frappe field name (e.g. `address_line1`, `item_code`, `terms`)
- **Scope** — `default`, `header`, `line`, `address`, `contact`, `template`, `variant`
- **Direction** — `erp_to_ecom` or `ecom_to_erp`

Fetch builds its ERPNext field list from active configs and **drops any field that does not exist** on the ERPNext doctype (e.g. Odoo-only names like `note` on Sales Order — use ERPNext `terms` instead).

### 5. ERPNext-specific behaviour

- **Customers** — fetch enriches linked **Address** and **Contact** docs; Shopify push uses `customerAddressCreate` / `customerAddressUpdate` (not `defaultAddress` on `CustomerInput`).
- **Inventory push** — Stock Reconciliation (not legacy `update_stock_qty`).
- **Products** — `disabled` flag: ERPNext `0` = enabled, `1` = disabled.
- **Sales orders** — Shopify `note` maps to ERPNext `terms` (Terms and Conditions), not `note`.

### 6. Verify

```bash
php artisan sync:all --dry-run
php artisan cache:clear
```

Use dashboard **Fetch** / **Post** per entity and check **Sync Logs** for wire payloads.

---

## Odoo + Shopify setup

Same flow as above with `erp_driver = odoo`. Credentials: Odoo URL, database, username, API key. Field configs use `erp_driver = odoo`. Odoo mappings are seeded via Laravel migrations (see `database/migrations/*seed*`).

Use the **Odoo base URL only** — no `/web`, `/public`, or Laravel connector URL.

---

## Production deployment (cPanel / Bluehost)

Example install path:

```
/home/USERNAME/public_html/oddoshopify/
```

Example public URL:

```
https://your-domain.example/oddoshopify/
```

### Environment

Set in `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example/oddoshopify
```

Run after any `.env` change:

```bash
php artisan config:clear
php artisan route:clear
php artisan config:cache
```

Ensure permissions:

```bash
chmod -R 775 storage bootstrap/cache
```

### Serve without `/public` in the URL

Laravel’s web root stays the `public/` folder. Add two files at the **project root** (same level as `app/`, `routes/`, `artisan`).

**`index.php`** (project root):

```php
<?php

require __DIR__.'/public/index.php';
```

**`.htaccess`** (project root):

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /oddoshopify/

    # Serve static files from public/ (CSS, JS, images)
    RewriteCond public/$1 -f [OR]
    RewriteCond public/$1 -d
    RewriteRule ^(.+)$ public/$1 [L]

    # Everything else → root index.php
    RewriteRule ^ index.php [L]
</IfModule>
```

Replace `/oddoshopify/` with your subdirectory name if different.

**`public/index.php`** — add this block immediately **before** `->handleRequest(Request::capture())`:

```php
// Subdirectory deployment (shared hosting)
$base = '/oddoshopify';
if (!empty($_SERVER['REQUEST_URI']) && str_starts_with($_SERVER['REQUEST_URI'], $base)) {
    $uri = substr($_SERVER['REQUEST_URI'], strlen($base));
    $_SERVER['REQUEST_URI'] = ($uri === '' || $uri === false) ? '/' : $uri;
}
```

Keep `public/.htaccess` as the standard Laravel file (no `RewriteBase`).

> **Do not** move Laravel files out of `public/` into the project root — that exposes `.env` and other sensitive directories.

---

## Cron / scheduled sync

Scheduled tasks are defined in `routes/console.php`.

| Task | Frequency |
|------|-----------|
| Full sync (`sync:all`) | Every 5 minutes |
| Amazon products | Hourly |
| Amazon inventory | Every 15 minutes |
| Amazon orders | Every 5 minutes |
| Pending alerts | Hourly |
| Log prune | Weekly |

### Full sync order

When `sync:all` runs, entities sync in this order:

1. Products  
2. Inventory  
3. Customers  
4. Orders  
5. Dispatch  

Each step respects **Global Settings** toggles (`*_sync_enabled`) and direction (`*_sync_mode`: `erp_to_ecom`, `ecom_to_erp`, `bidirectional`). Disabled entities are skipped.

### Recommended cPanel cron (Laravel scheduler)

Run every minute; Laravel decides what to execute:

| Field | Value |
|-------|--------|
| Minute | `*` |
| Hour | `*` |
| Day | `*` |
| Month | `*` |
| Weekday | `*` |

**Command** (adjust paths):

```bash
cd /home/USERNAME/public_html/oddoshopify && /usr/local/bin/php artisan schedule:run >> storage/logs/cron.log 2>&1
```

Find the PHP binary with `which php` in cPanel Terminal. On many Bluehost servers it is `/usr/local/bin/php` or `/opt/cpanel/ea-php82/root/usr/bin/php`, not `/usr/bin/php`.

### Alternative: run sync directly every 5 minutes

```bash
cd /home/USERNAME/public_html/oddoshopify && /usr/local/bin/php artisan sync:all >> storage/logs/cron.log 2>&1
```

Use `*/5` for the minute field.

### Verify cron is working

```bash
cd ~/public_html/oddoshopify
php artisan sync:all --dry-run
php artisan sync:all
php artisan schedule:list
```

Check logs:

- `storage/logs/laravel.log` — sync activity and errors  
- `storage/logs/cron.log` — cron command output (if configured)

Enable at least one entity in **Global Settings** or `sync:all` will skip all steps.

---

## Artisan sync commands

| Command | Description |
|---------|-------------|
| `php artisan sync:all` | Full pipeline (products → inventory → customers → orders → dispatch) |
| `php artisan sync:all --only=products` | Run a single step |
| `php artisan sync:all --dry-run` | Show planned steps without running |
| `php artisan sync:products` | Products only |
| `php artisan sync:inventory` | Inventory only |
| `php artisan sync:customers` | Customers only |
| `php artisan sync:orders` | Orders only |
| `php artisan sync:dispatch` | Dispatch only |
| `php artisan sync:amazon-products` | Amazon products |
| `php artisan sync:amazon-inventory` | Amazon inventory |
| `php artisan sync:amazon-orders` | Amazon orders |

Manual sync can also be triggered from **Settings** in the dashboard (requires `trigger-sync` permission).

---

## Dashboard features

- **Products, Inventory, Customers, Orders** — list, fetch, push, bulk delete  
- **Dispatch** — fulfillment sync (ERPNext Delivery Note or Odoo picking → Shopify fulfillment)  
- **Sync Logs** — request/response history per entity (includes GraphQL wire payloads)  
- **Field Config** — per-entity mappings, scopes, transforms, conditions  
- **Alerts** — configurable notifications  
- **Global Settings** — sync enable/disable, direction per entity, credentials  

Delete removes records from Shopify and the ERP (with confirmation). Shopify orders are cancelled, not hard-deleted.

---

## Troubleshooting

### 404 on subdirectory URL (`/oddoshopify/login`)

Laravel is running but routes do not match. Ensure the subdirectory fix is in `public/index.php` and `APP_URL` has no `/public` suffix.

### HTTP 500 after `.htaccess` changes

- Remove `Require all denied` and `index.php/$1` rules (often unsupported on shared hosting).  
- Use the root `index.php` + simple `.htaccess` setup above.  
- Check `storage/logs/laravel.log` and cPanel **Error Log**.

### Cron runs manually but not from server

- Use the full PHP path from `which php`.  
- Log to `storage/logs/cron.log` instead of `/dev/null`.  
- Cron must `cd` to the project root (where `artisan` lives), not `public/`.

### ERPNext: `Field not permitted in query: …`

Fetch requested a field name that does not exist on the ERPNext doctype (often an Odoo field left in config). Fix **Field Config** `erp_field` to a real ERPNext name, or run `database/sql/erpnext_seed.sql`. After deploy, invalid fields are dropped automatically; check `storage/logs/laravel.log` for `dropped invalid … fetch field(s)`.

Examples:

| Wrong (Odoo-style) | ERPNext Sales Order |
|--------------------|---------------------|
| `note` | `terms` |
| `partner_id` | `customer` |
| `order_line` | `items` |

Always run `php artisan cache:clear` after SQL or Field Config changes.

### ERPNext: customer address not on Shopify

Re-fetch customer from ERPNext (payload must include `_address` / `_contact`), then re-post. Addresses use `customerAddressCreate` / `customerAddressUpdate`, not `CustomerInput.defaultAddress`.

### ERPNext: products show Disabled

Ensure `disabled` mapping treats ERPNext `0` as active. Re-fetch and re-push after fixing field config conditions.

### Dispatch sync errors

**Odoo:**

```
Could not resolve fulfillment_order_id for Odoo product_id …
```

The Odoo product must be synced to Shopify first and appear on the order.

**ERPNext:** Delivery Note line configs need `against_sales_order` and `so_detail` (Sales Order Item row id). Re-fetch the sales order before dispatch push.

### Wrong ERP URL in settings

- **Odoo:** base URL only — no `/web` or connector path.  
- **ERPNext:** site root only — e.g. `https://mycompany.erpnext.com`.

---

## Project structure (key paths)

```
app/
  Console/Commands/       # sync:* artisan commands
  Http/Controllers/       # dashboard + API
  Services/Sync/          # ScheduledSyncRunner, UniversalSyncService, FieldMappingService
  Services/Odoo/          # Odoo RPC client
  Services/Erp/ErpNext/   # ERPNext REST client + entity services
  Services/Shopify/       # Shopify GraphQL/REST
database/sql/
  erpnext_seed.sql        # ERPNext + Shopify field configs (manual)
  erpnext_reverse_sync.sql
  erpnext_product_ecom_to_erp.sql
config/connectors.php     # erpnext / odoo / shopify driver registration
routes/
  web.php                 # dashboard routes
  console.php             # scheduler definitions
resources/views/dashboard/
storage/logs/             # application logs
public/                   # web root (index.php, assets)
```

---

## License

Proprietary — internal use.
