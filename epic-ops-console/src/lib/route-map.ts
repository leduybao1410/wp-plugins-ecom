/**
 * Maps the browser paths the ported components still call (`/api/dashboard/*`)
 * onto the WordPress routes the adapter registers. Mirrors the allowlist that
 * used to live in the Next.js BFF, including its method-dependent coupon
 * create route.
 */

const RESOURCES = new Set([
  "orders", "shipments", "products", "customers", "content", "leads", "reviews",
  "wholesale-orders", "costs", "distributors", "coupons", "newsletter", "ledger",
]);

export function mapToWordPress(relativePath: string, method = "GET"): string {
  const verb = method.toUpperCase();
  const segments = relativePath.split("/").filter(Boolean);
  const [first, second, third, fourth] = segments;
  if (!first) throw new Error("Unknown dashboard route");

  if (first === "overview" || first === "health" || first === "audit") return first;
  if (first === "product-change") return `products/change/${second ?? ""}`;
  if (first === "orders" && (second === "preview" || second === "apply")) return `orders/${second}`;
  if (first === "finance" && second === "export") return "finance/export";
  if (first === "support") return segments.join("/");
  if (first === "faqs") return segments.join("/");
  if (first === "address") return segments.join("/");
  if (first === "shipping") return segments.join("/");
  if (first === "wholesale" && second === "settings") return "wholesale/settings";
  if (first === "newsletter" && second === "campaigns") return segments.join("/");
  if (first === "mail-campaigns") return segments.join("/");
  if (first === "coupons" && second === "redemptions") return "coupons/redemptions";
  if (first === "coupons" && !second && verb === "POST") return "coupons";
  if (first === "shipments" && second && /^\d+$/.test(second) && third === "action") return `shipments/${second}/action`;
  if (first === "products" && second && /^\d+$/.test(second) && third === "variations") return segments.join("/");
  if (RESOURCES.has(first) && (!second || /^\d+$/.test(second)) && !third && !fourth) return `records/${segments.join("/")}`;

  throw new Error(`Unknown dashboard route: ${relativePath}`);
}

export const EXPORT_ROUTES = new Set(["finance/export", "support/export"]);
