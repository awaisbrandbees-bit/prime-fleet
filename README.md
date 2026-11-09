# Prime Fleet

White-label WordPress plugin for **private drive / fleet booking**, dispatch, and payments.

Forked from the Sletci (`sle-vip-booking`) engine. **Sletci launch plugin stays separate and untouched.**

| | |
|--|--|
| Version | `1.0.0-beta.2` |
| Folder | `prime-fleet/` |
| Shortcodes | `[prime_fleet_booking]` · `[prime_fleet_lookup]` · `[prime_fleet_offer]` · `[prime_fleet_home]` |
| REST | `/wp-json/prime-fleet/v1` |
| Options | `prime_fleet_*` |
| DB tables | `{prefix}prime_fleet_*` |
| Mark | **PF** |
| Ref prefix | **PF-** |

## Install

1. Copy or symlink `prime-fleet` into `wp-content/plugins/`.
2. Activate **Prime Fleet**.
3. Add `[prime_fleet_booking]` to a page.
4. Configure **Prime Fleet → Settings**.

## Relation to Sletci

| | `sle-vip-booking` | `prime-fleet` |
|--|-------------------|---------------|
| Brand | SLE VIP | Prime Fleet |
| Purpose | Client launch | Generic product |

See `../GENERIC_PRODUCT_REBRAND_PLAN.md`.
