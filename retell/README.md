# Retell AI Agent — Setup (PLAN.md §3 / build order step 3)

This directory holds everything needed to build the voice side of the demo: a Retell
**Single Prompt Agent** with one custom function, `create_order`, pointed at the live
Laravel endpoint.

| File | What it is |
|---|---|
| [`agent-prompt.md`](agent-prompt.md) | The system prompt to paste into the agent (menu is baked in). |
| [`create_order.tool.json`](create_order.tool.json) | Config + JSON-schema for the `create_order` custom function. |

**Target endpoint:** `POST https://34-244-32-152.sslip.io/api/orders`
(verified reachable; wrong `X-Api-Key` → `401`). If requests start failing to
resolve, the EC2 IP changed — see `../PLAN.md` and `../pizza-agent-api/README.md`.

The Retell dashboard is the source of truth once this is built; keep the two files
above updated to match whatever is live.

---

## 0. Prerequisites

1. **Retell account + API key** — sign up at <https://dashboard.retellai.com>, then
   *Settings → API Keys*. (The key is only needed if you script things via the REST
   API; the dashboard walkthrough below doesn't need it.)
2. **The server's shared secret.** The `create_order` function must send
   `X-Api-Key:` with the exact value of `RETELL_API_SECRET` in the server's `.env`.
   Retrieve it:
   ```bash
   ssh -i ~/.ssh/pizza-agent-key.pem ubuntu@34.244.32.152 \
     'grep RETELL_API_SECRET /var/www/pizza-agent/pizza-agent-api/.env'
   ```
   Referred to below as `<SECRET>`.

---

## 1. Create the agent

1. Dashboard → **Agents** → **+ New Agent** → **Single Prompt** (not Conversation Flow).
2. Name it `Tony's Pizza — order taker`.
3. **Prompt:** paste the fenced block from [`agent-prompt.md`](agent-prompt.md).
4. **Begin Message:** `Thanks for calling Tony's Pizza! What can I get started for you?`
   Do **not** leave this empty — an empty Begin Message makes the agent wait for the
   caller to speak first, which sounds like a dead line on a real call (see §5).
5. **Voice:** pick any natural English voice (e.g. `11labs-Adrian`). Not critical for the demo.
6. **LLM:** GPT-4o or Claude — any current flagship model is fine; the task is small.
7. Leave STT / interruption / filler settings at defaults.
8. **Publish** the agent. Phone calls always serve the last *published* version — an
   unpublished edit works in the simulator but not on a real call.

---

## 2. Add the `create_order` custom function

Agent → **Functions** → **+ Add** → **Custom Function**. Fill it in from
[`create_order.tool.json`](create_order.tool.json):

| Setting | Value |
|---|---|
| **Name** | `create_order` |
| **Description** | (the `description` string from the JSON file) |
| **URL** | `https://34-244-32-152.sslip.io/api/orders` |
| **Method** | `POST` |
| **Payload: args only** | **ON** — so Retell posts the bare arguments object, which is exactly what `/api/orders` validates. Without this, Retell wraps the body in `{ name, call, args }` and every field fails validation. |
| **Timeout** | `15000` ms |
| **Retries (`max_retry`)** | `0` — the endpoint is not idempotent; a retry would create a duplicate order. |
| **Speak during execution** | ON — message e.g. "Okay, placing that order now." |
| **Speak after execution** | ON |
| **Custom headers** | key `X-Api-Key`, value `<SECRET>` from step 0. |
| **Query parameters** | key `retell_call_id`, value `{{call_id}}` — Laravel validates query input too, so this lands in the `retell_call_id` column. Optional; the column is nullable. |
| **Parameters** | Switch the editor to JSON and paste the `parameters` object from `create_order.tool.json`. |
| **Response variables** | map `confirmation_number` → `confirmation_number` so the prompt can read it back. |

> Retell also auto-adds `X-Retell-Signature` (HMAC of the body). The demo endpoint
> only checks the shared secret and ignores the signature — fine for this pass, noted
> as a stretch goal in `../PLAN.md` §7.

---

## 3. Test in the simulator

Agent → **Test** (web call / simulator). Run these before touching a phone number:

1. **Happy path, pickup**
   "A large pepperoni and a medium margherita for pickup."
   → Agent collects name + phone, reads back:
   `1 × Large Pepperoni (17), 1 × Medium Margherita (11), total 28 dollars`,
   asks to confirm → on "yes", calls `create_order` once → reads a confirmation number.

2. **Delivery + extra toppings**
   "Two large veggie supremes, one with extra cheese and jalapenos, delivered to 12 Oak Street."
   → line 1: Large Veggie Supreme + extra cheese + jalapenos = 18 + 1.50 + 1.50 = `21`
   → line 2: Large Veggie Supreme = `18`; total `39`.
   → `fulfillment_type: "delivery"`, `address: "12 Oak Street"`.

3. **Off-menu item** — "Do you have garlic bread?" → agent says no, offers a pizza,
   never invents a price.

4. **Change after read-back** — confirm the order, then "actually make that a large" →
   agent re-reads the full order + new total and asks again before calling the function.

5. **Auth sanity** — temporarily set the `X-Api-Key` header to a wrong value, run
   order #1, confirm the function call comes back `401` and the agent apologises
   instead of inventing a confirmation number. **Restore the correct value after.**

**Verify each success server-side:**
```bash
ssh -i ~/.ssh/pizza-agent-key.pem ubuntu@34.244.32.152 \
  'sqlite3 /var/www/pizza-agent/pizza-agent-api/database/database.sqlite "SELECT id, customer_name, fulfillment_type, total, retell_call_id FROM orders ORDER BY id DESC LIMIT 5;"'
```
The confirmation number the agent read back is the row `id`.

---

## 4. Attach a phone number

Dashboard → **Phone Numbers** → buy a Retell number (or import a Twilio one) →
set **Inbound Call Agent** to this agent. That number is what you call for the
step-4 end-to-end test in `../PLAN.md` §8.

---

## 5. Troubleshooting: real call connects but you hear nothing

The web simulator can pass while a phone call is silent. Diagnose from
**Call History** → open the call:

| What the log shows | Cause | Fix |
|---|---|---|
| Reached the agent, `User_hangup`, "No conversation happened", and the **recording is silent both ways** | Agent is waiting for the caller to speak first (empty **Begin Message**). | Set the Begin Message (§1.4), **Publish**, call again. Confirmed: once the caller spoke, the agent replied normally. |
| Recording has the **agent talking** but you heard silence on the phone | One-way audio / media path — usually a Twilio number imported with a hand-rolled voice webhook, or an international calling leg. | Reconnect the number via **Phone Numbers → Connect Twilio**; test with a call from a US mobile to isolate the carrier leg. |
| `dial_no_answer` / call never reaches the agent | Number's **Inbound Call Agent** not set, or agent not published. | Set inbound agent (§4); **Publish** the agent (§1.8). |
| Agent answers an answering machine and monologues | No voicemail detection. | Enable voicemail detection in agent settings. |

---

## Done criteria for step 3

- [x] Single-prompt agent exists with the prompt from `agent-prompt.md`.
- [x] `create_order` function configured, "args only" ON, `X-Api-Key` header set.
- [x] Simulator: full order round-trips → `201` + confirmation number, row in the live `database.sqlite`.
- [x] Phone number attached, inbound agent set; a real call reaches the agent (it replies once the caller speaks).
- [ ] Begin Message set + agent **Re-published** so the agent greets first (fixes the silent-call issue — see §5).
- [ ] Full order placed over a **real phone call**, row confirmed in `database.sqlite` (= `../PLAN.md` §8 step 4).
