# WC Manager - Project Context for Claude

## Project Overview
**WC Manager** is a PHP web application for managing WooCommerce products via REST API. It handles simple and variable products, attributes, variations, categories, images, and multi-level users.

**Tech Stack:** PHP 8.0+, MySQL/MariaDB, WooCommerce REST API v3, vanilla JS/CSS

## Directory Structure
```
wc-manager/
├── config/config.php          # Database & app settings
├── includes/                  # Core PHP classes
│   ├── Database.php           # PDO singleton
│   ├── Auth.php               # Session-based auth (admin/editor roles)
│   ├── Helpers.php            # Utility functions (CSRF, flash, settings, logging)
│   ├── WooCommerceClient.php  # WC REST API client (v3 + WP REST)
│   └── bootstrap.php          # Autoloader
├── sql/schema.sql             # Database schema (users, settings, activity_log)
├── public/                    # Document Root
│   ├── index.php              # Dashboard
│   ├── login.php / logout.php
│   ├── products.php           # Product listing
│   ├── product_edit.php       # Add/edit product (simple + variable)
│   ├── categories.php         # Category management
│   ├── users.php              # User management (admin only)
│   ├── settings.php           # WC connection settings (admin only)
│   ├── manage-posts.php       # Blog post management
│   ├── ajax/                  # AJAX endpoints
│   ├── assets/                # CSS/JS
│   └── uploads/products/      # Local image storage before WC upload
```

## Key Features
- **Auth**: Username/password with roles (admin, editor). Default admin: `admin` / `admin123` — **MUST CHANGE ON FIRST LOGIN**
- **Products**: CRUD for simple & variable products, variations batch operations
- **Images**: Local upload → WC media library via WP REST API (requires WP Application Password)
- **Categories**: Hierarchical product categories with images
- **Activity Log**: All actions logged to DB

## Configuration (config/config.php)
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'wc_manager');
define('DB_USER', 'wc_user');
define('DB_PASS', '...');
define('APP_DEBUG', true);  // false in production
```

## WC Connection Settings (stored in DB `settings` table)
- `store_url` - WooCommerce site URL
- `consumer_key` / `consumer_secret` - WC REST API keys (read/write)
- `wp_username` / `wp_app_password` - **Required for media upload**

## Important Notes
1. **Media uploads require WP Application Password** — Consumer Key/Secret alone cannot upload images
2. **Panel domain must be publicly accessible** — WC downloads images from the panel's public URL
3. **Document Root must be `public/`** — Other folders protected by `.htaccess`
4. **Default admin password is `admin123`** — Change immediately after first login

## Common Tasks
- Add product: `product_edit.php` (GET for form, POST via AJAX `ajax/product_save.php`)
- Manage variations: `ajax/variation_generate.php`, `ajax/variations_save_batch.php`
- Upload images: `ajax/upload.php` → stored in `public/uploads/products/`
- Test WC connection: `settings.php` → `ajax/settings_test.php`

## Security
- CSRF protection on all POST/PUT/DELETE
- Prepared statements everywhere
- `.htaccess` blocks direct access to `config/`, `includes/`, `sql/`
- Role-based access control (admin vs editor)


# WC Manager - Project Context for Claude

## Project Overview

WC Manager is a PHP web application for managing WooCommerce products through WooCommerce and WordPress REST APIs.

It manages:
- Simple products
- Variable products
- Attributes
- Variations
- Categories
- Images
- Product videos
- Blog posts
- Multi-level users

## Tech Stack

- PHP 8.3
- MySQL/MariaDB
- WooCommerce REST API v3
- WordPress REST API
- Vanilla JavaScript
- CSS
- PHP-FPM
- Nginx
- Ubuntu Linux

## Architecture

WC Manager is a separate PHP application from the WordPress/WooCommerce website.

Communication flow:

WC Manager
    ↓
WooCommerce REST API
    ↓
WordPress / WooCommerce

For media uploads:

WC Manager
    ↓
WordPress REST API
    ↓
WordPress Media Library

Do NOT assume WC Manager and BajiStyle are the same application.

## Directory Structure

[existing directory structure]

## Key Features

[existing features]

## Configuration

[existing configuration]

## WC Connection

[existing WC connection settings]

## Important Notes

[existing notes]

## Product Video System

Product videos are uploaded from WC Manager to the WordPress Media Library.

Important files:

- `public/ajax/upload_video.php`
- `public/product_edit.php`
- `public/assets/js/product_edit.js`
- `public/ajax/product_save.php`
- `includes/WooCommerceClient.php`

WordPress/BajiStyle side:

- `inc/product-video.php`
- `assets/js/admin/product-video.js`
- `woocommerce/single-product.php`

Important WordPress meta:

- `_bajistyle_product_video_id`
- `_product_video_url`

Expected frontend format:

- 9:16 vertical video
- Story-style product video display

When debugging product video:
1. Check WC Manager upload endpoint.
2. Check PHP upload limits.
3. Check PHP-FPM.
4. Check WordPress REST API authentication.
5. Check Media Library attachment creation.
6. Check attachment ID.
7. Check product meta.
8. Check frontend rendering.

Do not modify the implementation before identifying which layer is failing.

## Current Server Environment

Server:
- Ubuntu
- Nginx
- PHP-FPM 8.3
- PHP-FPM user: `www-data`

PHP-FPM configuration:

`/etc/php/8.3/fpm/php.ini`

PHP-FPM pool:

`/etc/php/8.3/fpm/pool.d/www.conf`

After changing PHP-FPM configuration:

```bash
sudo systemctl reload php8.3-fpm