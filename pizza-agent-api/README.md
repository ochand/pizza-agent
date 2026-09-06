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

## Testing

```bash
php artisan test
```

`tests/Feature/OrderControllerTest.php` covers order creation, the API key check, and validation (including the delivery-requires-address rule). Tests run against an in-memory SQLite database (`phpunit.xml`), so they don't touch `database/database.sqlite`.

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
