# EPIC Admin Dashboard API — release 0.2.3

Version 0.2.3 completes the direct-order edit path: `fill_direct_order()` also stores `_epic_ward_name`, and `GET /records/orders/{id}` now returns `province_id`, `ward_id` and `ward_name` (from `_epic_vtp_province_id` / `_epic_ward_id` / `_epic_ward_name`) so the dashboard can prefill the structured province/ward picker when reopening a direct order.

Version 0.2.2 fixes direct (phone/Zalo) COD order creation on HPOS stores. The order address is now written with the CRUD setters (`set_billing_address` / `set_shipping_address`) instead of the legacy `set_address()` postmeta path, the selected ward name is stored in `address_2` (the supplementary address is folded into the street line), and creation is verified against the persisted order: if `WC_Order::save()` swallows a save-hook exception and leaves an empty order, the order is rolled back and a real error is returned (logged to the `epic-admin-dashboard` WooCommerce log source) instead of a false success.

Version 0.2.1 adds `cost_breakdown` to `GET /records/shipments/{id}`: the itemized ViettelPost shipping charge (main freight, fuel surcharge, VAT, total, COD fee, COD collected, weight, service, payment type, last-mile courier). It reads `_vtp_cost_breakdown` written by `epic-viettelpost-shipping` 0.1.9's webhook; when absent it backfills once from ViettelPost's push history (`Epic_VTP_Client::get_push_history()`), caches it and seeds the journey timeline. Read-only and best-effort (null on failure).

Version 0.2.0 exposes the WooCommerce order's native customer note (`customer_note`) on `GET /records/orders/{id}` and `GET /records/shipments/{id}`, so the dashboard can show the storefront's delivery note ("Ghi chú giao hàng") in the order Vận chuyển section and the shipment detail. Read-only; empty when the customer left no note.

Version 0.1.9 fixed coupon collection reads and keeps single-coupon reads/updates on the ID-specific route. Coupon list requests now reach the collection handler instead of failing with “Coupon not found.”

The private ops BFF remains the only cross-host client of `epic-admin/v1`. Browser requests from other origins are rejected and this namespace does not emit CORS permission. Other storefront namespaces are unchanged.

Opaque sessions and authorized PKCE grants are bound to the WordPress password and a per-user revocation epoch. Password resets/changes and WordPress destroy-all-sessions revoke ops access. The nonce-protected Settings → EPIC Admin Dashboard button revokes the current administrator's ops sessions. Existing tokens from earlier versions require sign-in again.

Schema migration preserves business records and adds `auth_version` to sessions/grants plus `epic_admin_limits`. The migration marker is recorded only after the required columns/table exist. Atomic minute limits are 10 per source / 60 global for grant creation and 30 per source / 120 global for exchange. Source fingerprints require the existing client secret; storage failures deny authentication. Expired security records are cleaned hourly.

Private API responses are no-store. Initial HSTS lasts 300 seconds on the HTTPS admin host. Login/wp-admin enforce framing, object and base restrictions; the stricter WordPress script policy is initially report-only because core/plugin inline scripts require compatibility work. MFA enrollment is an account configuration step and is not claimed by this release.

Standalone behavior test: `php tests/security.test.php`. Real WordPress/MySQL test: run `tests/integration.php` only in the explicitly guarded disposable `epic-security-release-wp` environment. Production packages exclude the tests directory.

The authoritative workspace memory is `agents/plugins-rag.md`; a dated snapshot is included under `docs/plugins-rag.md` for release review.
