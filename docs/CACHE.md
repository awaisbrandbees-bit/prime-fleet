# Prime Fleet — Cache & performance (SiteGround / full-page cache)

Use this when configuring **SiteGround SuperCacher**, LiteSpeed, Cloudflare, or any full-page HTML cache for production / staging.

## Cache policy

| Surface | Cache HTML? | Why |
|---------|-------------|-----|
| Homepage (`/` with `[prime_fleet_home]`) | **Yes** | Static marketing; plugin CSS/JS only when shortcode present |
| Marketing / About / Contact | **Yes** | No booking state |
| `/book-now/` (`[prime_fleet_booking]`) | **No / exclude** | Guest wizard + REST; must stay fresh |
| `/booking-status/` (`[prime_fleet_lookup]`) | **No / exclude** | PII lookup |
| `/?prime_fleet_pay=*` | **No / exclude** | Tokenized pay page (`nocache_headers` in plugin) |
| `/?prime_fleet_offer=*` | **No / exclude** | Tokenized slot offer |
| `/wp-json/prime-fleet/v1/*` or `index.php?rest_route=/prime-fleet/v1/*` | **Never as HTML** | REST only; honor `Cache-Control` from plugin |

## SiteGround SuperCacher (typical)

1. **Dynamic cache → Exclude URLs** (examples):
   - `/book-now*`
   - `/booking-status*`
   - `*prime_fleet_pay*`
   - `*prime_fleet_offer*`
   - `*rest_route=/prime-fleet*`
   - `/wp-json/prime-fleet*`
2. After changing exclusions, **Flush cache**.
3. Sticky CTA CSS is tiny and site-wide when enabled — safe to cache on marketing pages.

## Asset scoping (plugin behaviour)

| Handle / surface | When loaded |
|------------------|-------------|
| `prime-fleet-home` CSS/JS | Only pages containing `[prime_fleet_home]` |
| `prime-fleet` CSS/JS | Booking shortcode / wizard |
| `prime-fleet-lookup` / offer | Lookup / offer surfaces |
| Pay page CSS | Pay token pages only |
| `prime-fleet-sticky-cta` | Front-end when sticky enabled; **not** on pay/offer URLs |

Homepage should **not** load booking wizard JS.

## Images & fonts

- Homepage placeholders live under `assets/frontend/img/` (swap with client brand assets when ready).
- Hero/fleet images include `width` / `height` attributes to limit CLS.
- Fonts: Google Cormorant Garamond + Outfit (same family as booking UI).

## Lighthouse smoke (mobile)

Check **Home** and **Book Now** after deploy:

- [ ] No unused booking wizard JS on home  
- [ ] No large layout shift from sticky bar (reserved `--sle-sticky-h`)  
- [ ] Images have dimensions  
- [ ] No plugin white-screen / console errors we introduced  

Record scores or screenshots in the M5 verification pack if required by the client.
