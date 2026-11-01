import { test } from "node:test";
import assert from "node:assert/strict";
import { mapToWordPress, EXPORT_ROUTES } from "../src/lib/route-map.ts";

const cases = [
  ["overview", "overview"],
  ["health", "health"],
  ["audit", "audit"],
  ["orders", "records/orders"],
  ["orders/835", "records/orders/835"],
  ["leads/12", "records/leads/12"],
  ["shipments", "records/shipments"],
  ["customers", "records/customers"],
  ["content", "records/content"],
  ["reviews", "records/reviews"],
  ["wholesale-orders", "records/wholesale-orders"],
  ["costs", "records/costs"],
  ["distributors", "records/distributors"],
  ["coupons", "records/coupons"],
  ["coupons/42", "records/coupons/42"],
  ["coupons/redemptions", "coupons/redemptions"],
  ["newsletter", "records/newsletter"],
  ["newsletter/campaigns", "newsletter/campaigns"],
  ["ledger", "records/ledger"],
  ["products/807", "records/products/807"],
  ["products/807/variations", "products/807/variations"],
  ["products/807/variations/816", "products/807/variations/816"],
  ["product-change/preview", "products/change/preview"],
  ["product-change/apply", "products/change/apply"],
  ["orders/preview", "orders/preview"],
  ["orders/apply", "orders/apply"],
  ["shipments/835/action", "shipments/835/action"],
  ["wholesale/settings", "wholesale/settings"],
  ["newsletter/campaigns/7", "newsletter/campaigns/7"],
  ["newsletter/campaigns/7/send", "newsletter/campaigns/7/send"],
  ["finance/export", "finance/export"],
  ["address/provinces", "address/provinces"],
  ["address/wards", "address/wards"],
  ["shipping/quote", "shipping/quote"],
  ["support/conversations", "support/conversations"],
  ["support/conversations/9", "support/conversations/9"],
  ["support/conversations/9/read", "support/conversations/9/read"],
  ["support/conversations/9/notes", "support/conversations/9/notes"],
  ["support/summary", "support/summary"],
  ["support/health", "support/health"],
  ["support/export", "support/export"],
];

for (const [input, expected] of cases) {
  test(`maps ${input}`, () => assert.equal(mapToWordPress(input), expected));
}

test("rejects unknown routes", () => {
  assert.throws(() => mapToWordPress("admin/secret"), /Unknown dashboard route/);
  assert.throws(() => mapToWordPress("orders/835/extra/route"), /Unknown dashboard route/);
  assert.throws(() => mapToWordPress(""), /Unknown dashboard route/);
});

test("marks the base64 export routes", () => {
  assert.ok(EXPORT_ROUTES.has("finance/export"));
  assert.ok(EXPORT_ROUTES.has("support/export"));
  assert.equal(EXPORT_ROUTES.has("orders"), false);
});

test("coupon create is method dependent", () => {
  assert.equal(mapToWordPress("coupons", "POST"), "coupons");
  assert.equal(mapToWordPress("coupons", "GET"), "records/coupons");
  assert.equal(mapToWordPress("coupons/42", "PATCH"), "records/coupons/42");
});
