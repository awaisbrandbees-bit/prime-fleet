# Prime Fleet — Security checklist (S3)

## Production

1. **OpenWA off** — Settings → WhatsApp → Stub or Meta only. Do not leave OpenWA on staging/production.
2. **Secrets in wp-config** — Prefer `PRIME_FLEET_STRIPE_*`, `PRIME_FLEET_CAPTCHA_SECRET`, `PRIME_FLEET_WA_ACCESS_TOKEN`, `PRIME_FLEET_OPENWA_API_KEY` (dev only). Avoid long-lived secrets only in the DB.
3. **CAPTCHA** — Optional; when enabled, submit is fail-closed if misconfigured.
4. **Rate limits** — Settings → Security. Abuse 429s and honeypot hits land in **Logs**.

## Visibility (S3)

| Event | Where |
|-------|--------|
| REST rate-limit 429 | `system_logs` context `rate_limit` |
| Booking honeypot | `system_logs` context `honeypot` |
| Payment re-notify spam | Cooldown (default 120s) on issue/regenerate notify |

## Out of scope

WAF, login throttling, Redis limiter (S4) — add only if load demands it.
