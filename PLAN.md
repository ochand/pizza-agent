# Pizza Voice Ordering Agent — Simple Test/Demo Plan

## Status

**§4 (Laravel Application) and §5 (EC2 deployment) are done and verified — §8 steps 1–2 complete.** Lives in `pizza-agent-api/` (see its [README](pizza-agent-api/README.md) for local setup/test instructions). Deployed and live at **`https://34-244-32-152.sslip.io`** (Let's Encrypt cert via Certbot, auto-renews). Verified from the public internet: valid order → `201` + confirmation number; wrong `X-Api-Key` → `401`; rows confirmed in the deployed `database.sqlite`.

**§8 step 3 nearly done.** Retell single-prompt agent (`Tony's Pizza — order taker`, `agent_04be53…11d`) is built from the spec in [`retell/`](retell/README.md) — system prompt with baked-in menu ([`retell/agent-prompt.md`](retell/agent-prompt.md)) and the `create_order` custom function ([`retell/create_order.tool.json`](retell/create_order.tool.json)) with **"Payload: args only"** ON + `X-Api-Key` header, pointed at `https://34-244-32-152.sslip.io/api/orders`. **Verified: simulator order round-trips → `201` + confirmation number, row lands in the live EC2 `database.sqlite`.** Phone number attached; a real inbound call reaches the agent and it converses. **One fix outstanding:** the agent had no Begin Message so it stayed silent until the caller spoke (dead-air on pickup) — set the Begin Message + **re-publish** (see `retell/README.md` §1.4 / §5), then it greets first.

**Next: set Begin Message + republish, then §8 step 4 (one real phone call end-to-end, confirm the row).**

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

Spec + step-by-step in [`retell/README.md`](retell/README.md). Repo-side artifacts are done; the rest is dashboard work needing a Retell account.

- [x] Create a Retell account + API key.
- [x] Use a **Single Prompt Agent** (skip Conversation Flow — more setup than needed for a demo). — built: `Tony's Pizza — order taker`, `agent_04be53…11d`.
- [x] Write one system prompt that includes the menu directly (3 pizzas, 2 sizes, 6 extra toppings, fixed prices) — no `get_menu` endpoint, the LLM recites the prompt. — [`retell/agent-prompt.md`](retell/agent-prompt.md).
- [x] Prompt rules: don't invent items/prices, always read back the full order + total before finishing, collect name, phone, and pickup vs. delivery (+ address if delivery). — in the prompt.
- [x] Define **one custom function tool**: `create_order` → `POST /api/orders`, called once the caller confirms. — [`retell/create_order.tool.json`](retell/create_order.tool.json). Must run with **"Payload: args only"** ON so the body matches `/api/orders`.
- [x] Add a shared-secret header (`X-Api-Key`) to the tool config so Laravel can reject unauthenticated calls. — documented; value comes from the server's `RETELL_API_SECRET`.
- [x] Build the above in the dashboard, then test in Retell's built-in simulator until a full order round-trips correctly. — done: simulator order → `201` + confirmation number, row in the live `database.sqlite`.
- [~] Attach a test phone number to place a real call. — number attached, inbound call reaches the agent; pending Begin Message + republish so it greets first (`retell/README.md` §5), then the real end-to-end order call (§8 step 4).

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
- [x] Route sits behind HTTPS (required — see §5) — live at `https://34-244-32-152.sslip.io`.
- [x] Shared-secret header check (skip full HMAC signature verification for the demo) — `X-Api-Key` header checked against `RETELL_API_SECRET`.
- [x] Basic Laravel validation on input; that's sufficient for a test build.

**Tests:** `tests/Feature/OrderControllerTest.php` — 4 passing (create, wrong API key → 401, delivery without address → 422, missing required fields → 422). Run with `php artisan test`.

## 5. AWS EC2 Free-Tier Setup

- [x] Launch one `t2.micro`/`t3.micro`, Ubuntu 22.04/24.04. — `t3.micro`, Ubuntu 24.04, `eu-west-1a`, instance `i-0b2ed14484cb7a678`, public IP `34.244.32.152`.
- [x] Security group: allow 22 (SSH, your IP only), 80, 443. — `sg-06f3f1f24acb94663`.
- [x] Install PHP 8.3 + extensions (including `php-sqlite3`), Nginx, Composer directly on the box — no separate database server to install, configure, or tune (SQLite avoids the RAM-tuning concern a MySQL server would raise on a 1GB instance). — installed **PHP 8.4** instead via the `ondrej/php` PPA: Ubuntu 24.04's default PHP 8.3 was too old for the Laravel/Symfony versions pinned in `composer.lock` (they require PHP ≥8.4.1).
- [x] `git clone` the app, `composer install`, ensure `database/database.sqlite` exists and is writable by the web server user (`touch database/database.sqlite`), `.env` set to `DB_CONNECTION=sqlite`, `php artisan migrate --force`. — deployed to `/var/www/pizza-agent/pizza-agent-api` (not under `/home/ubuntu`, whose `750` permissions blocked Nginx's `www-data` user from traversing it).
- [x] Point a domain/subdomain at the instance's public IP, get a free cert with Certbot — **required**, Retell needs real HTTPS to reach the webhook. — used `34-244-32-152.sslip.io` (free IP-in-hostname DNS, no registrar needed) since Let's Encrypt's policy explicitly forbids issuing certs for AWS's own `*.compute.amazonaws.com` hostnames. Cert live, auto-renews.
- [x] That's it for infra — skip Elastic IP, backups, monitoring, queue workers for a demo; the instance's default public IP is fine as long as you don't reboot mid-demo. — no Elastic IP attached, so the `sslip.io` hostname (and the `.env` `APP_URL`) will need updating if the instance is ever stopped/restarted.

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
2. ~~Deploy to EC2, confirm HTTPS endpoint is reachable from the public internet.~~ **Done.** Live at `https://34-244-32-152.sslip.io`.
3. **In progress →** Build the Retell agent + `create_order` tool pointed at `https://34-244-32-152.sslip.io/api/orders`; test in the simulator. Spec committed under [`retell/`](retell/README.md); dashboard build + simulator run remain.
4. Place one real test call end-to-end, verify the row lands in the SQLite file.
