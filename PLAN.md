# Pizza Voice Ordering Agent — Simple Test/Demo Plan

## Status

**§4 (Laravel Application) is implemented and verified — §8 step 1 done.** Lives in `pizza-agent-api/` (see its [README](pizza-agent-api/README.md) for setup/test instructions). Runs on SQLite, `POST /api/orders` validates and stores an order behind a shared-secret header, 4 feature tests pass, and it's been smoke-tested end-to-end with curl against a real SQLite-backed dev server (valid order → `201` + confirmation number; wrong API key → `401`).

**Next: §8 step 2 — deploy `pizza-agent-api/` to an EC2 free-tier instance (§5) and confirm the HTTPS endpoint is reachable from the public internet.** §3 (Retell agent) has not been started yet.

## 1. Goal

Smallest working version of: a voice agent ([Retell AI](https://docs.retellai.com/)) that takes a pizza order and saves it via a Laravel + SQLite API on a single AWS EC2 free-tier instance. Scoped to be buildable and demo-able quickly, not production-hardened.

## 2. Architecture (one integration point)

```
 Caller ──► Retell AI Agent (single prompt, menu baked into the prompt)
                  │
                  │  one custom function: create_order
                  ▼
           POST https://your-domain/api/orders   ──►  Laravel (EC2)  ──►  SQLite file (same EC2 box)
                  │
                  ◄── JSON { confirmation_number } spoken back to caller
```

Just one webhook to build: the agent calls it once, when the caller confirms the order. No post-call webhook, no menu-lookup endpoint, no admin panel for this pass — see §7 for stretch goals if time allows.

## 3. Retell AI Agent Setup

- [ ] Create a Retell account + API key.
- [ ] Use a **Single Prompt Agent** (skip Conversation Flow — more setup than needed for a demo).
- [ ] Write one system prompt that includes the menu directly (e.g. 3 pizzas, 2 sizes, a few toppings, fixed prices) — no need for a `get_menu` endpoint, the LLM just recites what's in the prompt.
- [ ] Prompt rules: don't invent items/prices, always read back the full order + total before finishing, collect name, phone, and pickup vs. delivery (+ address if delivery).
- [ ] Define **one custom function tool**: `create_order` → `POST /api/orders`, called once the caller confirms.
- [ ] Add a shared-secret header (e.g. `X-API-KEY`) to the tool config so Laravel can reject unauthenticated calls.
- [ ] Test in Retell's built-in simulator until a full order round-trips correctly.
- [ ] Attach a test phone number to place a real call.

## 4. Laravel Application

### 4.1 Scaffolding
- [x] `composer create-project laravel/laravel pizza-agent-api`
- [x] Configure `.env` for SQLite (default Laravel setup — no server to configure).

### 4.2 Database — one table
- [x] `orders`: id, customer_name, phone, fulfillment_type (pickup/delivery), address (nullable), items (JSON — array of `{name, size, qty, price}`), total, retell_call_id (nullable), created_at. — `database/migrations/..._create_orders_table.php`, `app/Models/Order.php`.
- [x] Skip separate `menu_items`/`customers`/`order_items`/`call_logs` tables for this pass — one table with a JSON `items` column is enough to prove the flow works.

### 4.3 Routes / Controller
- [x] `routes/api.php`: `POST /api/orders` → `OrderController@store`.
- [x] `FormRequest` validation: required name/phone, items non-empty, total is numeric. — `app/Http/Requests/StoreOrderRequest.php`.
- [x] Controller: validate → create the row → return `{ "confirmation_number": <id> }` as JSON (fast — no external calls, the caller is waiting on the line). — `app/Http/Controllers/OrderController.php`.
- [x] Middleware: check the shared-secret header from §3 on this route. — `app/Http/Middleware/VerifyRetellSecret.php`, aliased `retell.secret`.

### 4.4 Security (minimum viable)
- [ ] Route sits behind HTTPS (required — see §5) — pending EC2 deploy.
- [x] Shared-secret header check (skip full HMAC signature verification for the demo) — `X-Api-Key` header checked against `RETELL_API_SECRET`.
- [x] Basic Laravel validation on input; that's sufficient for a test build.

**Tests:** `tests/Feature/OrderControllerTest.php` — 4 passing (create, wrong API key → 401, delivery without address → 422, missing required fields → 422). Run with `php artisan test`.

## 5. AWS EC2 Free-Tier Setup

- [ ] Launch one `t2.micro`/`t3.micro`, Ubuntu 22.04/24.04.
- [ ] Security group: allow 22 (SSH, your IP only), 80, 443.
- [ ] Install PHP 8.3 + extensions (including `php-sqlite3`), Nginx, Composer directly on the box — no separate database server to install, configure, or tune (SQLite avoids the RAM-tuning concern a MySQL server would raise on a 1GB instance).
- [ ] `git clone` the app, `composer install`, ensure `database/database.sqlite` exists and is writable by the web server user (`touch database/database.sqlite`), `.env` set to `DB_CONNECTION=sqlite`, `php artisan migrate --force`.
- [ ] Point a domain/subdomain at the instance's public IP, get a free cert with Certbot — **required**, Retell needs real HTTPS to reach the webhook.
- [ ] That's it for infra — skip Elastic IP, backups, monitoring, queue workers for a demo; the instance's default public IP is fine as long as you don't reboot mid-demo.

## 6. End-to-End Flow to Demo

1. Call the Retell number.
2. Agent recites the (prompt-baked) menu, takes the order, confirms it + total out loud.
3. On confirmation, agent calls `create_order` → Laravel validates + inserts a row → returns a confirmation number.
4. Agent reads the confirmation number back, call ends.
5. Check the `orders` table in the EC2 instance's `database.sqlite` file — the row is there.

## 7. Stretch Goals (only if time remains)

- Post-call webhook (`call_ended`) to store the transcript/recording URL against the order.
- `get_menu` tool backed by a real `menu_items` table instead of a hard-coded prompt.
- Split `orders`/`order_items` into normalized tables.
- Simple authenticated admin page to view incoming orders.
- HMAC signature verification instead of a shared secret.

## 8. Suggested Build Order

1. ~~Laravel `orders` migration + controller, tested locally with curl/Postman.~~ **Done.**
2. **Next →** Deploy to EC2, confirm HTTPS endpoint is reachable from the public internet.
3. Build the Retell agent + `create_order` tool pointed at the deployed URL; test in the simulator.
4. Place one real test call end-to-end, verify the row lands in the SQLite file.
