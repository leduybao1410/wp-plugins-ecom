# Plan — Single-plugin (EPIC WooCommerce Suite) integration + secret masking

Target site: `https://admin.epicroastery.coffee` (WordPress + WooCommerce, HPOS).
Source repo: `wordpress-plugins` → `github.com/leduybao1410/wp-plugins-ecom`.
Authoritative suite: `wordpress-plugins/epic-woocommerce-suite/` (11 modules today).

---

## 1. Current state

### 1.1 EPIC plugins active on the live site (15/15 active, standalone)

| Plugin slug | Site ver | Local ver | In suite today? |
|---|---|---|---|
| epic-account-linking | 1.0.0 | 1.0.0 | yes |
| epic-advanced-coupons | 1.2.0 | 1.2.0 | yes |
| epic-discord-notify | 1.0.0 | 1.0.0 | **no** |
| epic-distributor-profit | 1.1.0 | 1.1.0 | **no** |
| epic-ghn-shipping | 0.10.1 | 0.10.1 | yes |
| epic-image-optimize | 1.0.0 | 1.0.0 | **no** |
| epic-news-product-link | 1.0.0 | 1.0.0 | yes |
| epic-newsletter-subscription | 1.1.0 | 1.2.0 | yes (site outdated) |
| epic-order-codes | 1.1.0 | 1.1.0 | yes |
| epic-order-emails | 1.0.1 | 1.0.1 | yes |
| epic-payment-store | 1.0.1 | 1.0.1 | yes |
| epic-product-cost | 1.0.0 | 1.1.0 | **no** (site outdated) |
| epic-product-reviews | 1.1.0 | 1.1.0 | yes |
| epic-wholesale-inquiries | 1.1.0 | 1.1.0 | yes |
| epic-wholesale-orders | 1.0.0 | 1.0.0 | **no** |

Not installed on the site: `epic-first-order-coupon` (already in suite), `epic-zalo-login`, `epic-woocommerce-suite`.

### 1.2 Suite gap

The suite covers **10 of the 15** installed plugins. To be a true single plugin it must also bundle:

1. `epic-discord-notify` — no custom tables, no activation work.
2. `epic-distributor-profit` — `Epic_Distributor_Profit_Store::install()` (`includes/class-store.php:34`), 3 dbDelta files.
3. `epic-image-optimize` — no custom tables, no activation work.
4. `epic-product-cost` — `Epic_Product_Cost_Store::install()` (`includes/class-store.php:36`); ships a Node `mcp-server/` folder that must be excluded from the WP zip.
5. `epic-wholesale-orders` — no custom tables, no activation work.

Optional: `epic-zalo-login` (not installed, keep standalone), `epic-first-order-coupon` (already bundled).

### 1.3 Safety checks already verified

- **No class-name collisions across plugins** (0 cross-plugin duplicates) — each plugin uses its own `Epic_*` prefix, so loading all modules in one process is safe.
- Module copies in `epic-woocommerce-suite/modules/` are byte-identical to the standalone plugins.
- The suite already conflict-detects a still-active standalone copy via `standalone` basename + `sentinel` constant and skips it with an admin notice.

### 1.4 Housekeeping issues found

- `epic-woocommerce-suite/` at the **repo root** is a stale partial copy (10 modules, missing account-linking, missing most files). Delete it or clearly mark it deprecated to avoid deploying the wrong tree.
- `epic-woocommerce-suite.zip` is stale (Aug 24, 10 modules). Must be rebuilt after consolidation.
- Two odd zip files at `wordpress-plugins/ziNTt1S1` and `zioFMbOF` are un-extensioned zips (plugin source only) and are **not** ignored by `*.zip`; remove or rename.

---

## 2. Workstream A — Bundle the remaining modules

### A1. Copy the 5 missing plugins into the suite

```
wordpress-plugins/epic-woocommerce-suite/modules/
  epic-discord-notify/
  epic-distributor-profit/
  epic-image-optimize/
  epic-product-cost/          # exclude mcp-server/node_modules from the WP zip
  epic-wholesale-orders/
```

Each keeps its own folder + main file (same layout as the existing 11 modules).

### A2. Register them in `epic_suite_modules()`

Add 5 entries to `epic-woocommerce-suite.php` (existing `main` / `standalone` / `sentinel` / `label` shape). Sentinel constants (confirmed in each plugin's main file):

- `epic-distributor-profit.php` → `EPIC_DISTRIBUTOR_PROFIT_VERSION`
- `epic-product-cost.php` → `EPIC_PRODUCT_COST_VERSION`
- `epic-wholesale-orders.php` → `EPIC_WHOLESALE_ORDERS_VERSION`
- `epic-discord-notify.php` → `EPIC_DISCORD_NOTIFY_VERSION`
- `epic-image-optimize.php` → `EPIC_IMAGE_OPTIMIZE_VERSION`

### A3. Extend `epic_suite_activate()`

Add the two install calls (the other three need none):

```php
if ( class_exists( 'Epic_Distributor_Profit_Store' ) ) {
    Epic_Distributor_Profit_Store::install();
}
if ( class_exists( 'Epic_Product_Cost_Store' ) ) {
    Epic_Product_Cost_Store::install();
}
```

Both class names are confirmed (`Epic_Distributor_Profit_Store`, `Epic_Product_Cost_Store`).

### A4. Bump the suite

`Version: 1.1.0` / `EPIC_SUITE_VERSION` in the header + constant, update `readme.txt` bundled-module list (currently says "ten").

---

## 3. Workstream B — Deploy to the site

Order matters, because the suite skips a module whose standalone copy is active.

1. **Back up**: DB + `wp-content/plugins` (hosting panel or Backup plugin). Non-negotiable.
2. Upload + activate `epic-woocommerce-suite` (zip via Plugins → Add New → Upload).
   - Expect the "standalone copies still active, bundled copies skipped" admin notice (the 11 currently-bundled modules).
3. Deactivate the 15 standalone EPIC plugins one by one.
4. Refresh — the admin notice disappears once nothing is skipped.
5. Run a smoke test (see §5).
6. Only after green: delete the 15 standalone plugin folders (keep the zips in the repo as history).
7. Keep non-EPIC plugins as-is (Akismet, JWT Auth, WooCommerce, WP Mail SMTP, Jetpack, Elementor-related, backup).

Ship the two version catch-ups in the same release: `epic-newsletter-subscription` 1.2.0, `epic-product-cost` 1.1.0.

### Data / settings continuity

No migration needed: every module reads the **same `wp_options` keys** and the **same custom tables** it did standalone (the module code is unchanged). Deactivating a standalone plugin does not delete its options or tables. `register_activation_hook` calls are keyed to the module's own file, so they never fire under the suite — `epic_suite_activate()` runs the idempotent `dbDelta` installs instead (A3 extends this to the two new table-backed modules).

---

## 4. Workstream C — Hide secret keys (currently plain text)

### 4.1 Findings

Eight shared secrets are rendered as `<input type="text" value="…">` on wp-admin screens — the full secret is visible on screen **and** in page source:

| # | Plugin | Option | Render (file:line) |
|---|---|---|---|
| 1 | epic-wholesale-inquiries | `epic_wholesale_shared_secret` | `includes/class-settings.php:161` |
| 2 | epic-wholesale-orders | `epic_wholesale_orders_shared_secret` | `includes/class-settings.php:458` |
| 3 | epic-payment-store | `epic_payment_shared_secret` | `includes/class-settings.php:69` |
| 4 | epic-account-linking | `epic_account_linking_shared_secret` | `includes/class-settings.php:68` |
| 5 | epic-advanced-coupons | `epic_coupon_shared_secret` | `includes/class-quote-settings.php:74` |
| 6 | epic-order-codes | `epic_order_codes_shared_secret` | `includes/class-settings.php:68` |
| 7 | epic-product-reviews | `epic_product_reviews_shared_secret` | `includes/class-settings.php:176` |
| 8 | epic-newsletter-subscription | `epic_newsletter_shared_secret` | `includes/class-settings.php:165` |

Each exists twice: the standalone plugin **and** `epic-woocommerce-suite/modules/<same>/…` (7 of the 8 are already in the suite; `epic-wholesale-orders` is newly added in Workstream A).

Already `type="password"` but **still leaking via the `value` attribute** (WC's `password` field prints the stored value into the HTML): `epic-zalo-login` (`class-settings.php:122`), `epic-ghn-shipping` (`class-settings.php:92`), `epic-discord-notify` (`class-settings.php:94`).

One secret has no UI at all and is confirmed safe: `epic_order_codes_key` (auto-generated, optional `EPIC_ORDER_CODES_KEY` wp-config override).

### 4.2 Required pattern (do it properly — masking alone is not enough)

`type="password"` hides it visually but WordPress/WC still writes the stored value into the `value` attribute, so anyone viewing source still reads it. Apply all three parts:

1. Render `type="password"` with an **empty** `value`. If the option is already set, show a placeholder such as `•••••••••• (saved)` and a hint "Leave blank to keep the current secret."
2. Add a **sanitize/save guard** so an empty submit does not wipe the stored secret, e.g.:
   ```php
   'sanitize_callback' => function ( $value ) {
       $value = sanitize_text_field( $value );
       return '' === $value ? get_option( self::OPTION_KEY, '' ) : $value;
   },
   ```
   (Keep the existing `register_setting` group/name.)
3. Keep `autocomplete="off"` and add `autocomplete="new-password"` on the input so browsers do not autofill.

Optional hardening: a "Reveal" toggle button (JS) for admins who genuinely need to copy the value, plus a "Regenerate" action.

### 4.3 Also worth doing (defence in depth)

- The 8 secrets are stored unencrypted in `wp_options` (autoloaded) and read with plain `get_option`. At minimum, consider a `pre_get_option`/wrapper that stores them with a `wp-config` pepper, or move to `wp-config` constants. Out of scope for v1; track separately.
- The Next.js app's `.env` holds the matching 8 `EPIC_*_SHARED_SECRET` values and is **not** tracked in git (verified) — treat it as live key material and rotate if it was ever shared.

---

## 5. Post-deploy smoke test

Run on the live site after activating the suite:

| Area | Check |
|---|---|
| Admin | Every EPIC submenu page still loads (Wholesale Inquiries, Wholesale Orders, Payment Store, Product Cost, Distributor Profit, Image Optimize, GHN, Emails, Reviews). |
| Settings | Shared-secret fields now show empty password inputs with "saved" hint; re-saving with blank keeps the value (REST calls keep working). |
| Storefront | Home, shop, product page, cart, checkout, account sign-in/orders. |
| REST | `epic/v1/coupon/quote`, `epic-order-codes/v1/lookup`, `epic-newsletter/v1/subscribe`, `epic-reviews/v1/reviews`, `epic-payment/v1/*`, `epic-account-linking/v1/*`, `epic-wholesale/v1/inquiry`, `epic-wholesale-orders/v1/*` all answer (no 500). |
| Emails | Place a test order; confirm the EPIC order emails still send. |
| GHN | Create a test GHN shipment + print label. |
| Cron | `epic_payment_purge_expired` scheduled (suite activation hook sets it). |
| Logs | WooCommerce → Status → Logs and the PHP error log are clean. |

## 6. Rollback

1. Deactivate `epic-woocommerce-suite`.
2. Restore the backed-up 15 standalone plugin folders (or re-upload the zips).
3. Reactivate them — options/tables were never touched, so the site returns to its exact prior state.

---

## 7. Suggested sequencing

1. **A** — bundle the 5 modules + extend activation (local, testable on staging/staging clone).
2. **C** — apply the secret masking pattern to the 8 fields (standalone + suite copies) in the same release.
3. Rebuild `epic-woocommerce-suite.zip` (exclude `node_modules`, `mcp-server/node_modules`, `.DS_Store`, `*.zip`).
4. **B** — deploy to production, then delete the standalone folders.
5. Keep the suite as the only EPIC plugin going forward: new work lands in a module, never a new standalone plugin.
