# Pizza Agent API

Laravel backend for the [pizza voice ordering agent](../PLAN.md). Exposes a single endpoint that a [Retell AI](https://docs.retellai.com/) voice agent calls (via a custom function tool) mid-call to save a confirmed pizza order.

## Stack

- Laravel, PHP 8.3+
- SQLite (a single `database/database.sqlite` file — no database server to run)

## Requirements

- PHP 8.3+ with the `sqlite3` extension
- Composer

## Local Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

Set a value for `RETELL_API_SECRET` in `.env` — this is the shared secret the `/api/orders` endpoint expects on every request (see [Authentication](#authentication)).

## Running Locally

```bash
php artisan serve
```

The API is now available at `http://127.0.0.1:8000`.

## API

### `POST /api/orders`

Creates an order. This is the endpoint a Retell custom function tool calls once the caller confirms their order.

**Headers**

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-Api-Key` | must match `RETELL_API_SECRET` from `.env`, or the request is rejected with `401` |

**Body**

```json
{
  "customer_name": "Jane Doe",
  "phone": "5551234567",
  "fulfillment_type": "delivery",
  "address": "123 Main St",
  "items": [
    { "name": "Pepperoni", "size": "Large", "qty": 1, "price": 14.99 }
  ],
  "total": 14.99,
  "retell_call_id": "call_123"
}
```

`address` is required when `fulfillment_type` is `delivery`, optional for `pickup`. `retell_call_id` is optional.

**Response** — `201 Created`

```json
{ "confirmation_number": 1 }
```

Validation failures return `422` with Laravel's standard `{ "errors": { ... } }` shape; a missing/incorrect `X-Api-Key` returns `401`.

### Try it with curl

```bash
curl -i -X POST http://127.0.0.1:8000/api/orders \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: <value of RETELL_API_SECRET>" \
  -d '{
    "customer_name": "Jane Doe",
    "phone": "5551234567",
    "fulfillment_type": "pickup",
    "items": [{"name": "Margherita", "size": "Medium", "qty": 1, "price": 12.50}],
    "total": 12.50
  }'
```

Inspect the stored row directly:

```bash
sqlite3 database/database.sqlite "SELECT * FROM orders;"
```

## Testing the Production API with Postman

The app is deployed on EC2 at **`https://34-244-32-152.sslip.io`** (see [`PLAN.md`](../PLAN.md) §5 for deployment details). `sslip.io` is a free DNS service that encodes the IP in the hostname and resolves automatically — no registrar needed — used because Let's Encrypt refuses to issue certs for AWS's own `*.compute.amazonaws.com` hostnames. The cert is a real Let's Encrypt cert, so no "disable SSL verification" setting is needed in Postman.

1. Create a new request:
   - Method: `POST`
   - URL: `https://34-244-32-152.sslip.io/api/orders`
2. **Headers** tab:
   | Key | Value |
   |---|---|
   | `Content-Type` | `application/json` |
   | `X-Api-Key` | the production value of `RETELL_API_SECRET` |
3. **Body** tab → select `raw` → type `JSON` → paste:
   ```json
   {
     "customer_name": "Jane Doe",
     "phone": "5551234567",
     "fulfillment_type": "pickup",
     "items": [{"name": "Margherita", "size": "Medium", "qty": 1, "price": 12.50}],
     "total": 12.50
   }
   ```
4. Click **Send**. Expect `201 Created` with `{ "confirmation_number": <id> }`.

To confirm the auth check works, duplicate the request, change `X-Api-Key` to any wrong value (or remove the header) and send — expect `401 Unauthorized`.

Tip: save the two requests above as a small Postman collection with a collection variable (e.g. `{{base_url}}` = `https://34-244-32-152.sslip.io`) so you're not retyping the host each time.

**Note:** this hostname tracks the EC2 instance's current public IP. If the instance is ever stopped and restarted, the IP (and therefore this URL) changes — check `PLAN.md` for the current one if requests start failing to resolve.

## Testing

```bash
php artisan test
```

`tests/Feature/OrderControllerTest.php` covers order creation, the API key check, and validation (including the delivery-requires-address rule). Tests run against an in-memory SQLite database (`phpunit.xml`), so they don't touch `database/database.sqlite`.

## Operations: Common Commands

Reference for managing the app and its EC2 host by hand. Assumes the SSH key is at `~/.ssh/pizza-agent-key.pem` and the AWS CLI is configured (region `eu-west-1`).

### SSH into the server

```bash
ssh -i ~/.ssh/pizza-agent-key.pem ubuntu@34.244.32.152
```

The app lives at `/var/www/pizza-agent/pizza-agent-api` on the box.

### App commands (run on the server, from the app directory)

```bash
# Pull and apply the latest code
cd /var/www/pizza-agent/pizza-agent-api
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear && php artisan config:cache
php artisan route:cache

# Query the live database
sqlite3 database/database.sqlite "SELECT * FROM orders;"

# Tail logs
tail -f storage/logs/laravel.log
sudo tail -f /var/log/nginx/error.log

# Restart services after a config/PHP change
sudo systemctl restart nginx php8.4-fpm
sudo systemctl status nginx php8.4-fpm
```

### AWS CLI commands (run from your local machine)

```bash
# Instance status / public IP
aws ec2 describe-instances --instance-ids i-0b2ed14484cb7a678 \
  --query 'Reservations[0].Instances[0].{State:State.Name,PublicIp:PublicIpAddress}' --output table

# Start / stop the instance (note: public IP changes on restart — see caveat below)
aws ec2 start-instances --instance-ids i-0b2ed14484cb7a678
aws ec2 stop-instances --instance-ids i-0b2ed14484cb7a678

# Review security group rules (SSH/HTTP/HTTPS)
aws ec2 describe-security-groups --group-ids sg-06f3f1f24acb94663 \
  --query 'SecurityGroups[0].IpPermissions'
```

**Caveat:** no Elastic IP is attached (skipped for this demo — see `PLAN.md` §5), so stopping/starting the instance assigns a **new** public IP. That breaks the `34-244-32-152.sslip.io` hostname, the Nginx `server_name`, the Let's Encrypt cert, and `APP_URL` in `.env` — all of which are currently pinned to `34.244.32.152`. If you stop the instance, plan on redoing the Certbot step (§5) with the new IP's `sslip.io` hostname afterward.

### Certbot / TLS

```bash
# On the server: check certificate status and renewal
sudo certbot certificates
sudo certbot renew --dry-run
```

## Project Layout

| Path | Purpose |
|---|---|
| `app/Http/Controllers/OrderController.php` | Validates and stores an order, returns the confirmation number |
| `app/Http/Requests/StoreOrderRequest.php` | Validation rules for the order payload |
| `app/Http/Middleware/VerifyRetellSecret.php` | Checks the `X-Api-Key` header (aliased as `retell.secret` in `bootstrap/app.php`) |
| `app/Models/Order.php` | `Order` model — `items` cast to array (JSON column), `total` cast to decimal |
| `database/migrations/..._create_orders_table.php` | The single `orders` table (see `PLAN.md` §4.2 for why it's one table) |
| `routes/api.php` | Registers `POST /api/orders` |

See [`../PLAN.md`](../PLAN.md) for the full project plan, including the Retell agent setup and EC2 deployment steps that build on this API.
