# Pizza Voice Ordering Agent

A minimal working demo of a voice agent that takes a pizza order over the phone and
saves it to a database. A [Retell AI](https://docs.retellai.com/) single-prompt agent
handles the call and, once the caller confirms, calls a single Laravel + SQLite
endpoint running on an AWS EC2 free-tier instance.

**Status:** live end-to-end. A real inbound phone call round-trips a full order →
`201` + confirmation number → row in the deployed SQLite database. See
[`PLAN.md`](PLAN.md) for the full plan and build log.

## Architecture

```
 Caller ──► Retell AI Agent (single prompt, menu baked into the prompt)
                  │
                  │  one custom function: create_order
                  ▼
       POST https://34-244-32-152.sslip.io/api/orders  ──►  Laravel (EC2)  ──►  SQLite file
                  │
                  ◄── JSON { confirmation_number }  spoken back to the caller
```

One integration point: the agent calls `create_order` exactly once, when the caller
confirms. No post-call webhook, no menu-lookup endpoint, no admin panel — see
[`PLAN.md`](PLAN.md) §7 for stretch goals.

## Repository layout

| Path | What it is |
|---|---|
| [`PLAN.md`](PLAN.md) | The full project plan, scope, and status against each step. |
| [`pizza-agent-api/`](pizza-agent-api/README.md) | Laravel backend — the `POST /api/orders` endpoint, validation, shared-secret auth, tests. Includes local setup and EC2 operations commands. |
| [`retell/`](retell/README.md) | Retell agent spec — system prompt with the baked-in menu ([`agent-prompt.md`](retell/agent-prompt.md)) and the `create_order` custom function config ([`create_order.tool.json`](retell/create_order.tool.json)), plus the dashboard walkthrough. |

## Stack

- **Voice:** Retell AI single-prompt agent + one custom function tool
- **Backend:** Laravel (PHP 8.4+), SQLite (a single file — no database server)
- **Infra:** one AWS EC2 `t3.micro` (Ubuntu 24.04), Nginx, Let's Encrypt cert via
  Certbot (`sslip.io` hostname, since Let's Encrypt won't issue for AWS's
  `*.compute.amazonaws.com` names)

## Quick start (local API)

```bash
cd pizza-agent-api
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

Set `RETELL_API_SECRET` in `.env` — the `/api/orders` endpoint requires it as the
`X-Api-Key` header. Full API reference, curl examples, and the production Postman
guide are in [`pizza-agent-api/README.md`](pizza-agent-api/README.md).

## Demo flow

1. Call the Retell number.
2. The agent recites the menu, takes the order, reads it back with the total.
3. On confirmation, the agent calls `create_order` → Laravel validates and inserts a
   row → returns a confirmation number.
4. The agent reads the confirmation number back; the call ends.
5. The row is in the `orders` table of the EC2 instance's `database.sqlite`.

> **Note:** the production URL tracks the EC2 instance's current public IP (no Elastic
> IP is attached). If the instance is stopped and restarted the IP changes, which
> breaks the hostname, Nginx `server_name`, the TLS cert, and `APP_URL` — see
> [`PLAN.md`](PLAN.md) §5 and [`pizza-agent-api/README.md`](pizza-agent-api/README.md).
