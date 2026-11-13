=== Prime Fleet ===
Contributors: primefleet
Tags: booking, transportation, chauffeur, fleet, payments
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0-beta.26
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

VIP ground transportation booking, dispatch, and payments for WordPress — white-label ready.

== Description ==

Prime Fleet is a WordPress plugin for private fleet / VIP transfer operators:

* Guest booking wizard (services, zones, schedule, vehicles, add-ons)
* Staff operations console (bookings, dispatch, run sheet, reports)
* Email / WhatsApp messaging (stub, OpenWA for local tests, Meta when ready)
* Payments (instructions, custom link, Stripe, and more)
* Security: CAPTCHA, honeypot, rate limits, abuse logs
* Translation-ready (Text Domain: `prime-fleet`, Domain Path: `/languages`)

== Installation ==

1. Upload the `prime-fleet` folder to `/wp-content/plugins/`.
2. Activate **Prime Fleet** through the Plugins screen.
3. Open **Prime Fleet** in the admin menu and review Settings.
4. Add shortcodes `[prime_fleet_booking]` and `[prime_fleet_lookup]` to pages.

== Frequently Asked Questions ==

= Does it support WordPress.org language packs? =

Yes. Text Domain `prime-fleet` and Domain Path `/languages`. Ship `prime-fleet.pot` with the plugin; translators use GlotPress for UI chrome. Catalog rows (services/zones) use the DB + optional `prime_fleet_catalog_i18n` option.

= Is OpenWA for production? =

No. Use Stub or Meta Cloud in production. OpenWA is local/dev only.

== Changelog ==

= 1.0.0-beta.26 =
* Mark no-show, i18n bags, security S1–S3 visibility, RTL hooks, catalog locale map, admin docs
