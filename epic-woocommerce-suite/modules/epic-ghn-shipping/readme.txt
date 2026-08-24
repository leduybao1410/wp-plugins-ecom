=== EPIC GHN Shipping Manager ===
Contributors: epicroastery
Tags: woocommerce, ghn, giao hang nhanh, shipping, vietnam
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 8.9
Stable tag: 0.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

GHN (Giao Hàng Nhanh) shipment booking, cancellation, label printing, status tracking, and multi-order bundling for WooCommerce orders.

== Description ==

Adds:

* **WooCommerce → Settings → GHN Shipping** — GHN Token/Shop ID, sandbox/production toggle, pickup ("from") address, and shipment defaults (package dimensions, fallback item weight).
* A **GHN Shipment** meta box on every order edit screen:
  * Orders with no GHN shipment yet: shows the resolved (or manually pickable) province/district/ward, computed parcel weight, and a **Ship via GHN** button.
  * Orders already shipped (including ones auto-booked by the storefront, which write the same `_ghn_order_code` meta this plugin reads): tracking code, live status, **Refresh status**, **Print label**, and **Cancel shipment**.
  * Orders shipped as part of a bundle: shows which sibling orders share the parcel, with links.
* **Bundling** — select 2+ unshipped **prepaid** orders on the Orders list and choose **Bundle & ship via GHN** from Bulk actions (COD orders can't be bundled — see 0.4.0 below). This opens a review screen that:
  * Blocks confirmation (with a clear per-order diff) if the selected orders don't share the same recipient phone and GHN address, unless staff check a logged manual override.
  * Aggregates line items, weight, and items subtotal across every selected order.
  * Calls GHN's fee endpoint **once** for the combined parcel — never a sum of each order's individual fee.
  * Lets staff override the package dimensions before booking.
  * On confirm, books one GHN shipment and writes the tracking code + bundle ID onto every order in the bundle, with a shared order note. Each order's own shipping total is left untouched — the real combined fee is tracked on the bundle record (`wp_epic_ghn_bundles`) for reconciliation; nothing is collected on delivery, since every order in a bundle is already paid.
* Three columns on the Orders list:
  * **COD** — a "Yes"/"No" chip per order, from the exact same strict `payment_method` check every booking decision in this plugin uses (see 0.3.0 below).
  * **Shipment** — a colored status pill (Created / Pending pickup / Delivering / Done / Cancelled / etc., from each order's `_ghn_shipment_status`) for every order with a GHN shipment, or a muted "—" for orders not yet booked. Hover a pill for the tracking code and raw GHN status.
  * **Action** — for an unshipped order, a one-click **Create Shipment** button right on the row, booking it immediately with no page navigation (same underlying logic as the order screen's own "Ship via GHN" button). For an already-shipped order, a **Print label** button that opens the GHN label in a new tab (same as the order screen's own "Print label" button) — no need to open the order just to reprint a label.
* A **Create GHN shipment(s)** bulk action on the Orders list — select any number of unshipped orders (up to 25 per run) and book each one's own separate GHN shipment immediately, right from the list. Unlike bundling, nothing is combined — every order gets its own parcel and tracking code, using the exact same COD/prepaid and weight/address logic as the single-order "Ship via GHN" button. A summary notice reports how many booked and lists any that failed (with the reason) after the page reloads.

* **Post-merger ("sáp nhập") address conversion** — GHN's own delivery-zone codes still use the pre-2025-merger province/district/ward structure. Order address auto-resolution now also tries the *new* 2-tier layout (no district) and converts it back automatically when confident. The Settings screen's pickup address and the order meta box's manual override both gained a new-format province/ward picker with a **Convert to pre-merger address** button for the ambiguous cases that need a staff pick instead of a guess.

Shipment booking is always staff-initiated — nothing books automatically. The storefront only ever creates the WooCommerce order (COD or SePay-paid); a staff member then books the actual GHN shipment from here, either per-order (the meta box's "Ship via GHN" button or the Orders list row's "Create Shipment" button) or in bulk. This plugin never re-books an order that already has a `_ghn_order_code`, so a shipment can't accidentally be booked twice.

**Not in this release:** the GHN Shipments dashboard screen, webhook-driven status sync, cancelling a bundle as a whole (cancelling one order that's part of a bundle still shows a warning and is blocked, same as before — bundle-aware cancellation is planned), and a per-order COD split for bundling (which is why COD orders can't be bundled at all yet — see 0.4.0).

== Installation ==

1. Plugins → Add New → Upload Plugin → choose `epic-ghn-shipping.zip` → Install Now → Activate.
2. Go to WooCommerce → Settings → GHN Shipping. Enter your GHN Token and Shop ID (start on Sandbox), then fill in your pickup address.
3. Open any WooCommerce order without a tracking code — the GHN Shipment box in the sidebar lets you book it. Or, from the Orders list itself, click **Create Shipment** in the Action column on that order's row.
4. To ship several orders at once without combining them: select them on the Orders list and choose **Create GHN shipment(s)** from Bulk actions.
5. To bundle multiple *prepaid* orders into one physical parcel: select 2+ unshipped orders on the Orders list, choose **Bundle & ship via GHN** from Bulk actions, review, and confirm.

== Changelog ==

= 0.10.0 =
* New: **Free shipping minimum order amount** field added to WooCommerce → Settings → GHN Shipping (under a new "Free shipping promotion" section). This does not enable free shipping by itself — the storefront's own checkout code (`lib/cart.ts` / `lib/woocommerce.ts` on the website side) is what actually waives the shipping fee, by reading this value through WooCommerce's Settings REST API at order time, cached for ~2 minutes, falling back to ₫500,000 if the setting can't be read for any reason. Changing the number here (e.g. 500000 → 300000) takes effect on the next order after the cache expires — no site deploy needed. This field is deliberately not a WooCommerce coupon or shipping method, since this store's checkout is headless and never loads WooCommerce's cart/coupon engine (see 0.9.0 and earlier — orders are created directly via the REST API).

= 0.9.0 =
* New: fires a plugin-agnostic `epic_ghn_shipment_booked( $order, $tracking_code, $eta )` action right after a shipment books successfully — both from the single-order path (`Epic_GHN_Ajax::book_single_order()`, backing the meta box button, the Orders-list row action, and the "Create GHN shipment(s)" bulk action) and from the bundle path (`Epic_GHN_Bundle::confirm()`, fired once per order in the bundle). Nothing in this plugin listens to it — it exists so a separate plugin (`epic-order-emails`, ships alongside this one) can send the customer a "your order has shipped" email with the tracking code, without a hard dependency in either direction. No behavior changes if `epic-order-emails` isn't installed; this is strictly additive.

= 0.8.0 =
* New: post-2025-merger ("sáp nhập") address support. GHN's own API (re-verified against api.ghn.vn/home/docs on 2026-08-20) still only speaks the pre-merger province/district/ward structure, but a customer's order — or a store's own pickup address — may well be entered in the new (post-merger), 2-tier format instead. This release bridges that gap via a new `class-legacy-address.php`, using a bundled old⇄new administrative mapping (data from vietmap-company/vietnam_administrative_address, VietMap Administrative Data License):
  * `Epic_GHN_Address_Resolver::resolve()` now tries the order's address as new-format (state=province, city=ward, no district) when the existing old-format match fails, converting it back to GHN's still-old names before resolving — auto-filling with a "please double-check" notice when confident.
  * The order meta box's manual override picker and the Settings screen's pickup ("from") address both gained a second, new-format province/ward picker plus a **Convert to pre-merger address** button, so staff convert instead of retyping the pre-merger address from scratch.
  * Conversion is best-effort, not guesswork dressed up as certainty: one new-format ward can trace back to more than one pre-merger district (old and new ward boundaries didn't nest cleanly during the reform) — a single-district case auto-converts, an ambiguous one asks staff to pick from the actual candidate areas GHN's data was assembled from.
  * Known follow-up: conversion works ward-by-ward. A future release could also let staff bulk-convert every already-placed order with an unresolved new-format address in one pass, rather than only converting one at a time as each order is opened.
  * Ward fields (old-format and new-format alike) are now a searchable text field with a live, ranked suggestion list — not a plain dropdown (a district can have dozens of wards, and a new-format province can have 100+; Hà Nội alone has 126 post-merger) and not a native <datalist> either, since browsers filter that by raw substring against the literal text and can't match "Dien Bien" against "Phường Điện Biên". Suggestions filter and re-sort on every keystroke (accent-insensitive, prefix matches first), with full keyboard navigation. Only an exact match — picked from the list or typed exactly — is accepted into the field actually submitted, so a half-typed search can't silently submit the wrong ward.

= 0.7.1 =
* Fixed: 0.7.0's PHP-based removal of the **Origin** column (unset()-ing the `origin` key on the column filters) didn't actually hide it on a live site test — confirmed the column key was right (`id="origin"`, `class="column-origin"`), so WooCommerce must be adding it through some other path this plugin isn't hooking. Replaced with a CSS rule (`.column-origin { display: none !important; }`) that hides it regardless of which filter or screen (legacy or HPOS) added it — the PHP removal is left in place too, harmlessly, in case it does apply on some WooCommerce versions.

= 0.7.0 =
* Removed WooCommerce's built-in **Origin** column (its Order Attribution feature, WC 8.5+ — traffic source per order: Organic, Direct, Referral, a UTM campaign, etc.) from the Orders list. `class-orders-list.php` now strips the `origin` column key from both the legacy and HPOS column filters at a very late priority, so it's gone regardless of when WooCommerce's own code adds it.

= 0.6.0 =
* The Orders list's **Action** column now does double duty: an already-shipped order shows a **Print label** button instead of the plain "—" it used to show, opening the GHN label (A5) in a new tab — the exact same `epic_ghn_print_label` AJAX action and `Epic_GHN_Client::gen_print_token()`/`print_url()` as the order meta box's own "Print label" button, just reachable without opening the order. Unshipped orders are unchanged (still the "Create Shipment" button, or "Configure GHN" if the plugin isn't set up yet).

= 0.5.0 =
* New **COD** column on the Orders list — a "Yes"/"No" chip per order from `Epic_GHN_Client::is_cod_order()`, the same strict payment-method check every booking decision already used.
* New **Action** column on the Orders list — a **Create Shipment** button on any unbooked order's row, booking that one order immediately without leaving the list (calls the same `epic_ghn_ship_order` AJAX action, and so the same `Epic_GHN_Ajax::book_single_order()`, as the order screen's own "Ship via GHN" button and the bulk action). Shows "Configure GHN" instead of a button when the plugin's Token/Shop ID aren't set yet; shows "—" for orders already shipped.
* COD, Shipment, and Action are now inserted as one contiguous block (previously Shipment was added on its own) — the column-registration and per-column rendering in `class-orders-list.php` were generalized to a single `add_columns()` / `render_column()` pair rather than one-off methods per column, so a future fourth column is a small addition, not a re-plumb.

= 0.4.0 =
* Bundling now refuses any COD order — see 0.3.0's "Known follow-up" below for why (bundling's combined COD amount had no per-order split, so a bundle mixing a prepaid order with COD orders would collect the wrong amount from whoever the courier actually meets). `class-bundle.php`'s `load_orders()` now drops any COD order from the selection with a clear reason on the review screen ("COD orders can't be bundled right now…"), `confirm()` refuses to proceed at all if one somehow slipped through, and every bundle now books as prepaid (`cod_amount` 0) — nothing is collected on delivery. Ship a COD order individually instead (its own "Ship via GHN" button, or the new bulk action below).
* New **Shipment** column on the Orders list showing each order's GHN status as a colored pill, bucketed from the raw GHN status code into Created / Pending pickup / Delivering / Done / Cancelled / Delivery issue / Returning / Returned (unbooked orders show a plain "—"). Registered for both the legacy and HPOS orders screens.
* New **Create GHN shipment(s)** bulk action — book a separate GHN shipment for each of up to 25 selected orders in one click, reusing the single-order booking logic (`Epic_GHN_Ajax::book_single_order()`, extracted from `ship_order()` this release so both paths can't drift apart). Capped per run and given a best-effort `set_time_limit(0)` since it's a synchronous loop of GHN API calls with no background-job queue behind it.

= 0.3.0 =
* Fixed: the single-order "Ship via GHN" button always booked the shipment as COD for the full order total, even for orders already paid online (SePay bank transfer) — the same customer would then be asked to pay again at the door. COD vs. prepaid is now decided strictly from the order's `payment_method` ('cod' → COD, collect the full total on delivery; 'sepay' → prepaid, `cod_amount` 0), matching the logic the storefront's own SePay webhook already used when it auto-books a shipment. Any other/unrecognized payment method still defaults to COD.
* The order meta box's unbooked state now shows a "Payment" line — payment method plus what booking will do (COD amount, or "already paid — no COD") — before staff click Ship via GHN.
* The order note written after booking now says whether the shipment went out as COD or prepaid, and records the amount/payment method.
* Known follow-up: the bulk **Bundle & ship via GHN** flow (`class-bundle.php`) still computes its combined COD amount the old way (sums every bundled order's subtotal, regardless of payment method) and was not changed in this release — bundling an already-paid SePay order together with COD orders will overcharge COD on the SePay order's share. Avoid bundling a SePay-paid order with COD orders until this is addressed.

= 0.2.0 =
* Bundling: orders list bulk action, review/confirm screen, `wp_epic_ghn_bundles` record-keeping, and combined-fee booking (see Description).
* Order meta box now shows linked sibling orders when a shipment is part of a bundle.

= 0.1.0 =
* Initial release: settings screen, GHN API client, order meta box (ship/cancel/print/refresh).
