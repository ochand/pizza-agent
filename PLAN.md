# Pizza Voice Ordering Agent — Simple Test/Demo Plan

## 1. Goal

Smallest working version of: a voice agent ([Retell AI](https://docs.retellai.com/)) that takes a pizza order and saves it via a Laravel + MySQL API on a single AWS EC2 free-tier instance. Scoped to be buildable and demo-able quickly, not production-hardened.

## 2. Architecture (one integration point)

```
 Caller ──► Retell AI Agent (single prompt, menu baked into the prompt)
                  │
                  │  one custom function: create_order
                  ▼
           POST https://your-domain/api/orders   ──►  Laravel (EC2)  ──►  MySQL (same EC2 box)
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
- [ ] `composer create-project laravel/laravel pizza-agent-api`
- [ ] Configure `.env` for MySQL.

### 4.2 Database — one table
- [ ] `orders`: id, customer_name, phone, fulfillment_type (pickup/delivery), address (nullable), items (JSON — array of `{name, size, qty, price}`), total, retell_call_id (nullable), created_at.
- [ ] Skip separate `menu_items`/`customers`/`order_items`/`call_logs` tables for this pass — one table with a JSON `items` column is enough to prove the flow works.

### 4.3 Routes / Controller
- [ ] `routes/api.php`: `POST /api/orders` → `OrderController@store`.
- [ ] `FormRequest` validation: required name/phone, items non-empty, total is numeric.
- [ ] Controller: validate → create the row → return `{ "confirmation_number": <id> }` as JSON (fast — no external calls, the caller is waiting on the line).
- [ ] Middleware: check the shared-secret header from §3 on this route.

### 4.4 Security (minimum viable)
- [ ] Route sits behind HTTPS (required — see §5).
- [ ] Shared-secret header check (skip full HMAC signature verification for the demo).
- [ ] Basic Laravel validation on input; that's sufficient for a test build.

## 5. AWS EC2 Free-Tier Setup

- [ ] Launch one `t2.micro`/`t3.micro`, Ubuntu 22.04/24.04.
- [ ] Security group: allow 22 (SSH, your IP only), 80, 443.
- [ ] Install PHP 8.3 + extensions, Nginx, MySQL, Composer directly on the box (co-locate MySQL — simplest for a single free-tier instance).
- [ ] `git clone` the app, `composer install`, `.env` with DB creds, `php artisan migrate --force`.
- [ ] Point a domain/subdomain at the instance's public IP, get a free cert with Certbot — **required**, Retell needs real HTTPS to reach the webhook.
- [ ] That's it for infra — skip Elastic IP, backups, monitoring, queue workers for a demo; the instance's default public IP is fine as long as you don't reboot mid-demo.

## 6. End-to-End Flow to Demo

1. Call the Retell number.
2. Agent recites the (prompt-baked) menu, takes the order, confirms it + total out loud.
3. On confirmation, agent calls `create_order` → Laravel validates + inserts a row → returns a confirmation number.
4. Agent reads the confirmation number back, call ends.
5. Check the `orders` table on the EC2 MySQL instance — the row is there.

## 7. Stretch Goals (only if time remains)

- Post-call webhook (`call_ended`) to store the transcript/recording URL against the order.
- `get_menu` tool backed by a real `menu_items` table instead of a hard-coded prompt.
- Split `orders`/`order_items` into normalized tables.
- Simple authenticated admin page to view incoming orders.
- HMAC signature verification instead of a shared secret.

## 8. Suggested Build Order

1. Laravel `orders` migration + controller, tested locally with curl/Postman.
2. Deploy to EC2, confirm HTTPS endpoint is reachable from the public internet.
3. Build the Retell agent + `create_order` tool pointed at the deployed URL; test in the simulator.
4. Place one real test call end-to-end, verify the row lands in MySQL.
