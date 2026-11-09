# Prime Fleet hooks & filters

Extension points for integrators. Prefer hooks over editing plugin core.

---

## Payment registry

### `prime_fleet_register_payment_methods`

Fired once during plugin boot with the payments `Registry` instance.

```php
add_action( 'prime_fleet_register_payment_methods', function ( $registry ) {
	$registry->register( new My_Gateway_Method() );
} );
```

Custom methods implement `Prime_Fleet\Payments\Contracts\Payment_Method_Interface` (or extend `Abstract_Method`). A second provider can register **without** changing booking core.

---

## Payment lifecycle

| Hook | When | Args |
|------|------|------|
| `prime_fleet_payment_issued` | After pay link created / re-sent | `( array $payment, array $booking )` |
| `prime_fleet_payment_paid` | Authoritative paid (webhook or Mark paid), before notify | `( int $booking_id, array $payment, bool $already )` |
| `prime_fleet_payment_status_changed` | Booking payment status moves | `( int $booking_id, string $new, string $previous )` |

Statuses: `unpaid` · `link_sent` · `pending_verification` · `paid` · `refunded` (+ internal `expired` on payment rows).

---

## Booking / pricing (existing)

| Hook | Notes |
|------|-------|
| `prime_fleet_booking_created` | After booking insert |
| `prime_fleet_booking_status_changed` | Confirm / cancel / etc. |
| `prime_fleet_quote_calculated` | After REST quote |
| `prime_fleet_fare_calculated` | Filter on fare engine output |

---

## Constants (secrets)

| Constant | Purpose |
|----------|---------|
| `PRIME_FLEET_STRIPE_SECRET_KEY` | Stripe secret (preferred over DB) |
| `PRIME_FLEET_STRIPE_WEBHOOK_SECRET` | Webhook signing secret |
| `PRIME_FLEET_OPENWA_API_KEY` | Temporary OpenWA test driver (not production Meta) |
