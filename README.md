# SKIBIDI

A small personal-care and skincare e-commerce store written in plain PHP and MySQL. Customers browse products, search and filter, post reviews, build a cart, and check out with cash on delivery; an admin panel manages products and orders. Every page lives under `/app/`.

This project is **built deliberately insecure** — it is a lab target for practising web-application attacks (SQL injection, brute force, stored XSS) in a controlled environment. Do not deploy it in production or point real traffic at it.

## Setup — run it locally

You need PHP (with `pdo_mysql`) and MySQL running on your machine.

1. Get the code:
   ```sh
   git clone <your-repo-url> && cd skibidi-php
   ```
2. Create the database and tables. The seed data below is optional:
   ```sh
   mysql -u root < sql/schema.sql
   ```
3. Optionally fill the `password_hash` columns (needed only after the SQL-injection fix is applied):
   ```sh
   php sql/seed_hashes.php
   ```
4. Start the built-in server from the project root (the doc-root is the project root, not `app/`):
   ```sh
   php -S 127.0.0.1:8080 -t .
   ```
5. Open http://localhost:8080/app/index.php and log in with one of the accounts below.

Database settings live at the top of `app/config.php` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`); change them to match your local MySQL if needed.

## Accounts

| Email                 | Password     | Role  |
|-----------------------|--------------|-------|
| admin@skibidi.test    | admin123     | admin |
| maya@skibidi.test     | password123  | buyer |
| jay@skibidi.test      | letmein      | buyer |
| kai@skibidi.test      | qwerty123    | buyer |
| tone@skibidi.test     | sunshine     | buyer |

Every password is a real rockyou.txt entry, on purpose.

## Vulnerabilities

1. **SQL injection** — the login form, product search, the category filter, and the product page interpolate user input straight into SQL strings. Try payloads like `' OR '1'='1` in the search box.
2. **No brute-force protection** — the login endpoint accepts unlimited attempts and reports invalid credentials with an identical message, so it can be scripted and dictionary-attacked freely.
3. **Stored XSS** — product reviews are rendered without output encoding and no Content-Security-Policy is sent, so a review containing `<script>` executes for every visitor who opens the product page.

## Already hardened here

Session cookies are marked `HttpOnly` and `SameSite=Strict`, sessions regenerate on login, the admin area is gated server-side by a role read from the database, order totals are recomputed from stored prices (never from the client), stock changes are transactional, and registration always creates a `buyer` account. All of that is intentional — the three vulnerabilities above are the only ones in scope.
