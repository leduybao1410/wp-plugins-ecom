# Plan: wiring epic-advanced-coupons into the real (headless) checkout

Status: BUILT (2026-08-22) — plugin v1.2.0 + website changes, both delivered. NOT YET SMOKE-TESTED against a real WooCommerce site (this environment has no live WordPress/WooCommerce to run against) — see "Build notes" below for exactly what to verify before trusting this in production.
Scope: all 4 requested feature groups — code entry + native discount amount, first-order/allowlist/schedule restrictions, Buy X Get Y, and auto-apply.

## Build notes (read this first)

What actually got built matches §3–§6 below with one refinement: the quote endpoint's response splits each applied coupon's discount into `nativeDiscountAmount` (goes through `coupon_lines`, WooCommerce computes it for real) and `bxgyDiscountAmount` (goes through an explicit negative `fee_lines` entry) rather than one combined number — collapsing them would have left the website with no way to tell WooCommerce how much of a combined discount belongs to which mechanism. `lib/coupon.ts` on the website side has `couponLinesFromQuote()`/`feeLinesFromQuote()` helpers that build the right request shape from this split automatically.

Also built but not in the original file-by-file list: a `test-bxgy-autoapply.php` standalone harness (no WordPress needed) that regression-tests the refactored Buy-X-Get-Y and auto-apply math directly — 7 checks, all passing. It does NOT cover the REST endpoint itself (that needs real `WC_Coupon`/`WP_REST_Request` objects this harness can't stub meaningfully) or the in-order `coupon_lines` application.

**Before trusting this in production, smoke-test on staging:**
1. Activate plugin v1.2.0, set a shared secret at WooCommerce → Coupon Quote API, set `EPIC_COUPON_SHARED_SECRET` on the website.
2. Create one coupon of each kind (percent, fixed_cart, first-order-only, allowlist, schedule, Buy X Get Y, auto-apply) and confirm `/api/coupon/quote` returns the right preview for each as you build a cart on the checkout page.
3. Place one real COD order with a coupon applied — confirm the order in wp-admin shows the coupon (Marketing → Coupons → Redemptions should log it) and the fee line (for Buy X Get Y) or coupon discount (native types) matches what the customer was quoted, i.e. WooCommerce's own independently-computed `coupon_lines` discount equals what `calculate_native_discount()` in `class-rest-quote.php` predicted. This is the one piece of native-WooCommerce behavior this environment couldn't verify directly (see §3's rejected-Option-A note — no live WooCommerce to run against here).
4. Place one first-order-only order as a given email, then try a second order with the same email — confirm it's rejected. This is the regression test for the session-email bug fix in §2.
5. SePay path: generate a QR with a coupon applied, confirm the frozen discount survives to the real order once the webhook fires.

## 1. Why this is a real integration, not a wire-up

`epic-advanced-coupons` is fully built, activation-bug-fixed, and verified in isolation (see `PLAN.md`, `PLAN_REDEMPTION_LOG.md`). But it was written the way every WooCommerce coupon plugin is written: hooked into `WC_Cart` and the native checkout form. This site's actual checkout (`website/src/app/[lang]/checkout/page.tsx` → `/api/checkout` or `/api/sepay/checkout` + `/api/sepay/webhook`) never instantiates `WC_Cart` and never runs the native checkout form — it's custom Next.js code that talks to WooCommerce purely as a REST datastore, the same fact already recorded for the free-shipping-threshold work. Two of this plugin's six pieces (Buy X Get Y, auto-apply) are, right now, dead code on the real site: their hooks (`woocommerce_cart_calculate_fees`, `woocommerce_before_calculate_totals`) simply never fire. The other three pieces (native discount amount, the three restrictions) *can* fire — WooCommerce's REST order-creation flow can apply a coupon via `coupon_lines`, which routes through `WC_Discounts`, which does fire the `woocommerce_coupon_is_valid` filter this plugin already hooks — but nothing in the checkout sends `coupon_lines` today, and (see §2) the restriction checks would silently no-op even if it did.

Redemption reporting needs no changes — `class-redemption-log.php` syncs from whatever coupon line items an order actually has, however they got there.

## 2. Bug found while planning this: restrictions read the wrong customer

`Epic_Adv_Coupons_Restrictions::get_session_email()` / `get_session_phone()` read `WC()->customer->get_billing_email()/get_billing_phone()` — the **PHP session customer**. That's correct for a normal browser checkout (the session cookie carries billing details as the customer types them into the native form). It is wrong for a REST `coupon_lines` application: there is no browser session behind a server-to-server `POST /wc/v3/orders` call from Next.js, so `WC()->customer` is empty, and:

- First-order-only: `if ( $email && self::has_prior_order( $email ) )` — empty `$email` short-circuits, so this restriction **silently never blocks anyone** headless.
- Allowlist: `if ( $allowlist && ( $email || $phone ) )` — same silent no-op.
- Schedule: unaffected (no session dependency) — this one already works correctly headless.

Fix: `WC_Discounts::is_coupon_valid()` passes itself as the filter's third argument — `apply_filters( 'woocommerce_coupon_is_valid', $valid, $coupon, $discounts )` — and `$discounts->get_object()` returns whichever `WC_Cart` or `WC_Order` it's validating against. Change the hook registration from priority args `10, 2` to `10, 3`, and in `validate_on_cart()`, branch: if the bound object is a `WC_Order`, read `$order->get_billing_email()` / `get_billing_phone()` directly (the order's actual submitted billing info); otherwise keep the existing session lookup for admin/native-checkout use. Small, surgical, no new file.

## 3. Architecture decision: how the site asks "what does this coupon do"

Two options considered.

**Option A (rejected): spin up an ephemeral `WC_Cart` per REST request.** Feed it items, call `apply_coupon()`, `calculate_totals()`, let the existing BxGy/auto-apply hooks fire untouched. Attractive because zero plugin logic changes — but `WC_Cart` is designed around a persistent, session-cookie-backed singleton (`WC()->cart`); WooCommerce's own official headless answer to this (the Store API) requires a `Cart-Token` session to work correctly for exactly this reason. A one-shot, cookie-less `new WC_Cart()` per request is a known-fragile pattern (tax class lookups, `is_checkout()`-gated filters elsewhere in the stack, object cache pollution) and would need real spike time to trust in production.

**Option B (recommended): a small new REST endpoint that answers the question directly**, reusing the plugin's own math but refactored to not require a real cart object. Matches how the free-shipping threshold was already done — real logic, explicit, testable — rather than fighting WooCommerce's cart machinery headlessly.

New endpoint, registered by the plugin: `POST /wp-json/epic/v1/coupon/quote`

Request:
```json
{
  "code": "WELCOME10",            // omit to just ask "any auto-apply coupons qualify?"
  "email": "a@b.com",             // optional, needed for first-order-only/allowlist
  "phone": "0901234567",
  "items": [
    { "product_id": 123, "variation_id": 456, "quantity": 2, "unit_price": 145000 }
  ]
}
```

`unit_price` is trusted from the caller (Next.js), same trust boundary already used for GHN's `insuranceValue: subtotal` — this is a server-to-server call authenticated the same way order creation already is (see §5), never reachable from the browser directly.

Response:
```json
{
  "valid": true,
  "message": null,
  "code": "WELCOME10",
  "discountType": "percent",        // WooCommerce native type, or "bxgy" for a Buy-X-Get-Y coupon
  "discountAmount": 29000,          // VND, always the final number to subtract
  "freeShipping": false,            // native "Allow free shipping" checkbox, passed through
  "autoApplied": false              // true when this coupon was found via the auto-apply lookup, not typed
}
```

Implementation work inside the plugin:
- Refactor `Epic_Adv_Coupons_Bxgy::calculate_discount( $cart, $coupon )` to `calculate_discount_for_items( array $items, $coupon )`, where `$items` is a plain array of `{product_id, variation_id, quantity, unit_price}` — it never actually needed a real `WC_Cart`, only `get_cart()`'s array shape and `has_term()`. `apply_fees()` (the real cart hook, kept for any future native-checkout use, e.g. wp-admin manual orders) becomes a thin wrapper that builds that array from `$cart->get_cart()` and calls the same method.
- Refactor `Epic_Adv_Coupons_Auto_Apply::qualifies( $cart, $coupon )` the same way — an items-array + subtotal version, with the existing `WC_Cart`-based method becoming a wrapper.
- Add `Epic_Adv_Coupons_Restrictions::check( $coupon, $email, $phone )` — a public wrapper around the existing `get_violation_message()` — so the quote endpoint can run the exact same first-order/allowlist/schedule logic without touching sessions or filters at all.
- New `class-rest-quote.php` registers the route, resolves the coupon by code (or, if no code given, calls the auto-apply lookup), calls the three refactored checks, and calls WooCommerce's own native discount math (`WC_Coupon::get_discount_amount()`, which is cart-agnostic and safe to call directly) for the percent/fixed-cart/fixed-product case.
- Auth: the endpoint isn't public — require the same shared-secret header pattern `epic-payment-store` already uses (`X-Epic-Secret`), new setting under WooCommerce → Settings → Advanced Coupons, new `EPIC_COUPON_SHARED_SECRET` env var on the website. Reachable from the internet but useless without the secret, and it only ever *reads* — no state changes.

## 4. Next.js changes

New files:
- `src/lib/coupon.ts` — thin fetch wrapper to the quote endpoint, same shape as `pendingOrders.ts`/`woocommerce.ts`. Exports `quoteCoupon(input)` and `CouponQuoteError`.
- `src/app/api/coupon/quote/route.ts` — public-facing Next.js route the checkout page calls (keeps `EPIC_COUPON_SHARED_SECRET` server-side, never sent to the browser). Recomputes from `resolveCartLines()` server-side rather than trusting client prices — same defensive pattern as `/api/checkout`.

Changed files:
- `src/lib/woocommerce.ts` — `CreateWcOrderInput` gets `couponLines?: { code: string }[]` and `feeLines?: { name: string; total: string }[]`; `createOrder()` threads them into the WC REST body (`coupon_lines`, `fee_lines`).
- `src/app/api/checkout/route.ts` (COD) — accept an optional `couponCode` in the request body, re-run `quoteCoupon()` server-side immediately before `createOrder()` (never trust a client-echoed discount), subtract `discountAmount` from `total`, pass `couponLines`/`feeLines` through based on `discountType`.
- `src/app/api/sepay/checkout/route.ts` — same quote call, but the result is **frozen into the pending order** (SePay's QR amount is fixed at generation time) rather than recomputed at order-creation time.
- `src/lib/pendingOrders.ts` — `PendingOrder` gains `coupon?: { code, discountType, discountAmount, autoApplied }`.
- `src/app/api/sepay/webhook/route.ts` — passes `pending.coupon` straight through to `createOrder()` as `couponLines`/`feeLines`, unchanged from what was quoted/frozen at checkout. This intentionally does **not** re-validate the coupon against live state (usage limit could have been hit by someone else in the meantime) — same accepted tradeoff the code already makes for `subtotal`/`shippingFee` being frozen too.
- `src/app/[lang]/checkout/page.tsx` / `CartSidebar.tsx` — coupon code input + "Apply" button in the order summary; on mount and on cart change, silently call the quote endpoint with no code to check for auto-apply eligibility and show a "🎉 discount applied automatically" banner if one qualifies; show a "Discount (CODE): −₫X" line; debounce re-quoting when the cart changes after a code is applied (must catch "coupon no longer valid because a line was removed").

## 5. Open questions — need your call before I start building

1. **Free-shipping threshold vs. discount**: should the ₫500k free-shipping check use the pre-discount subtotal (current `cartSubtotal()`) or the post-discount amount? I'd default to pre-discount (simplest, matches "subtotal" as already defined everywhere else) unless you want the discount to also affect shipping eligibility.
2. **Tax**: I haven't checked whether the store has WooCommerce tax enabled. If it does, `WC_Coupon::get_discount_amount()`'s tax-inclusive/exclusive handling needs a quick check against how `unit_price` is defined in `resolveCartLines()`. If prices are simple VND with no tax (my assumption from the existing code), this is a non-issue.
3. **Shared secret naming**: reusing the `epic-payment-store` pattern (new setting page + env var) rather than the existing WC consumer key/secret, since this is a new, narrower-scoped credential. OK to proceed that way?
4. **BxGy UI**: do you want the free/discounted item called out as its own line in the order summary (e.g. "+1 free 250g Robusta"), or is a single "Buy X Get Y discount: −₫X" fee-style line enough for launch? Affects how much the quote response needs to expose.

## 6. Build order

1. Plugin: fix the restrictions session bug (§2) — small, independent, worth shipping even before the rest.
2. Plugin: refactor BxGy/auto-apply to items-array methods; add the `Epic_Adv_Coupons_Restrictions::check()` wrapper.
3. Plugin: new `epic/v1/coupon/quote` endpoint + shared-secret setting.
4. Website: `lib/coupon.ts` + `/api/coupon/quote` route.
5. Website: `lib/woocommerce.ts` coupon/fee line support.
6. Website: COD route wiring, then SePay checkout + webhook wiring (frozen-at-quote-time).
7. Website: checkout UI (code field, auto-apply banner, summary line).
8. Test plan (§7).

## 7. Testing plan

- PHP: extend the existing harness style (used for the activation-bug fix) with unit tests against the refactored `calculate_discount_for_items()`/`qualifies()` — feed a plain items array, assert discount math, no WordPress bootstrap needed for the pure-math parts.
- Manual, per feature, against a staging coupon of each type: percent, fixed_cart, first-order-only (place one order as an email, confirm a second order with the same email is rejected — this is the regression test for §2's fix), allowlist, schedule (or fake the clock), BxGy, auto-apply (add/remove items to cross the threshold, confirm the banner appears/disappears and the customer's own removal sticks for the session — note: "session" here means the Next.js page's in-memory cart state, not a PHP session, since there is none).
- Confirm the Redemptions report (Marketing → Coupons → Redemptions) picks up a real headless order with zero code changes — this is the check that coupon_lines/fee_lines are actually landing on the order the way WooCommerce expects.
- SePay path specifically: confirm the discount frozen at QR-generation time is what actually gets applied by the webhook, including the amount-mismatch guard (`pending.amount` must already reflect the discount).
