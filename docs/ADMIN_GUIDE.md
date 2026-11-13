# Prime Fleet Admin Guide

**Product:** Prime Fleet (WordPress plugin)  
**Audience:** Operators / site admins  
**Admin entry:** WP Admin → **Prime Fleet**  
**Guest booking:** shortcode `[prime_fleet_booking]` (or your book page)  
**Lookup:** `[prime_fleet_lookup]`  
**Pay links:** guest pay URL from Payments panel  

Technical refs: [REST.md](REST.md) · [HOOKS.md](HOOKS.md) · [CACHE.md](CACHE.md) · [SECURITY.md](SECURITY.md)

---

## 1. Console overview

| Tab | Use for |
|-----|---------|
| Overview | KPIs / snapshot |
| Bookings | Calendar/list, detail, confirm, pay, offers, **Mark completed / Mark no-show** |
| Dispatch | Day board — assign chauffeurs / vehicles |
| Run sheet | Day ops sheet |
| Services / Locations / Pricing / Vehicles / Chauffeurs / Add-ons | Catalog |
| Payments | Method registry |
| Settings | General, booking rules, guest copy, CAPTCHA, rate limits, WhatsApp, website CTA |
| Messages | Email / WhatsApp templates (DB content — edit in admin) |
| Reports / Logs | Ops + abuse visibility |

---

## 2. Bookings

1. Open a **Pending** booking → review fare/occupancy → **Confirm booking**.  
2. **Confirmed** → **Mark completed** or **Mark no-show** (full fare kept; reminders cleared).  
3. Payment: choose method → **Send payment link** (re-send respects cooldown).  
4. Slot offers when the window conflicts.  
5. Amendments allowed on confirmed / completed / no-show.

---

## 3. Catalog & translations

- **UI chrome** (buttons, errors) → gettext / language packs (`/languages`, Text Domain `prime-fleet`).  
- **Services, zones, vehicles, add-ons** → DB rows. Optional locale overrides live in option `prime_fleet_catalog_i18n` (see structure below). After changes call `Prime_Fleet\Services\Config_Compiler::invalidate()` or re-save Settings.  
- **Message templates** → edit under **Messages** (not GlotPress). Seeded once at install; ops owns copy.

```php
// Example: Urdu name for service #12
update_option( 'prime_fleet_catalog_i18n', array(
  'ur' => array(
    'service' => array(
      '12' => array(
        'name' => 'ایئرپورٹ VIP',
        'description' => 'ملاقات اور سلام',
      ),
    ),
  ),
) );
\Prime_Fleet\Services\Config_Compiler::invalidate();
```

Filter guest locale: `prime_fleet_catalog_locale`.

---

## 4. WhatsApp

- **Stub** — logs to outbox (safe default).  
- **OpenWA** — local/dev only (`WP_DEBUG` / allow constant).  
- **Meta Cloud** — when credentials exist (token in wp-config).

---

## 5. Security & Logs

See [SECURITY.md](SECURITY.md). Check **Logs** for `rate_limit` / `honeypot` when under spam.

---

## 6. RTL

When WordPress locale is RTL, booking and admin shells set `dir="rtl"`. Mirror CSS lives in booking/admin stylesheets under `[dir="rtl"]`.
