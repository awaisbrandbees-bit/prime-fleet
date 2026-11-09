# Prime Fleet REST API

**Namespace:** `prime-fleet/v1`  
**Base URL:** `{site}/wp-json/prime-fleet/v1`  
**Plugin:** `prime-fleet` (M4+)

Errors use a consistent JSON shape:

```json
{
  "code": "prime_fleet_…",
  "message": "Human-readable message",
  "data": { "status": 400 }
}
```

Rate-limited responses return **429** with `Retry-After` when applicable.

---

## Public routes (no auth)

| Method | Path | Rate limit | Notes |
|--------|------|------------|-------|
| GET | `/config` | 120 / min / IP | Compiled guest config; includes `payment_step`, `payment_methods` |
| POST | `/quote` | 60 / min | Server fare calc |
| GET | `/availability` | 90 / min | Occupancy check |
| POST | `/coupons/validate` | 40 / min | Coupon helper |
| POST | `/booking-token` | — | One-time submit token |
| POST | `/bookings` | — | Create booking → `PF-` reference |
| POST | `/lookup` | 30 / min | Status by reference + email (includes `payment_status`) |
| GET | `/offers/{token}` | 60 / min | Slot offer |
| POST | `/offers/{token}` | 20 / min | Accept offer |
| GET | `/pay/{token}` | 60 / min | Guest payment summary |
| POST | `/pay/{token}/ack` | 20 / min | Guest “I’ve paid” (instructions only) → `pending_verification` |
| POST | `/webhooks/stripe` | 180 / min | Stripe signature required (`Stripe-Signature` header) |

### GET `/pay/{token}`

Returns a guest-safe summary:

```json
{
  "reference": "PF-XXXXXX",
  "amount": 150,
  "currency": "USD",
  "payment_status": "link_sent",
  "payment_state": "open",
  "method_key": "stripe",
  "method_label": "Card (Stripe)",
  "guest_url": "https://…/?prime_fleet_pay=…",
  "can_ack": false
}
```

`payment_state`: `open` · `paid` · `refunded` · `expired` · (ack path uses booking `pending_verification`).

### POST `/pay/{token}/ack`

Only valid for **instructions** method while the link is open. Does **not** mark paid — staff still uses **Mark paid**.

### POST `/webhooks/stripe`

- No cookie / Application Password auth.
- Verify `Stripe-Signature` with `PRIME_FLEET_STRIPE_WEBHOOK_SECRET` (or settings).
- Idempotent via `payment_events.idempotency_key`.
- Events handled: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `charge.refunded`.

---

## Authenticated routes (staff)

Require a WordPress user with **`prime_fleet_dispatch`** or **`prime_fleet_manage`** (cookie session or Application Password).

Unauthenticated → **401**. Logged in without cap → **403**.

| Method | Path | Notes |
|--------|------|-------|
| GET | `/bookings/{id}/payment` | Payment snapshot + recent `payment_events` |
| POST | `/bookings/{id}/payment/issue` | Issue / re-send pay link |

### POST `/bookings/{id}/payment/issue` body

```json
{
  "method_key": "stripe",
  "custom_url": "",
  "notify": true,
  "force_new": false
}
```

---

## Auth hardening notes

- There is **no** public booking list/search REST route.
- Public create / lookup / pay routes are rate-limited.
- Secrets (Stripe secret / webhook secret) belong in `wp-config.php` constants, not front-end config.
- Guest pay page also works via `/?prime_fleet_pay={token}` (HTML); REST is for SPA / integrations.

---

## Config payment flags

`GET /config` includes:

- `payment_step` — `true` when Stripe Checkout is ready (`Stripe_Gateway::is_ready()`). Wizard still defaults to **post-confirm** pay; flag is for future use.
- `payment_methods` — enabled methods with `supports_hosted` / `ready`.
