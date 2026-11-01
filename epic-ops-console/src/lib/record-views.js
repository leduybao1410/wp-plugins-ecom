export const recordViews = Object.freeze({
  orders: { columns: ["number", "customer", "date", "total", "payment_method", "source", "shipment_status", "status"] },
  shipments: { columns: ["number", "customer", "date", "tracking", "shipment_status", "status"] },
  products: { columns: ["name", "type", "sku", "price", "stock", "stock_quantity", "status"] },
  customers: { columns: ["name", "email", "orders", "spent"] },
  leads: { columns: ["name", "email", "phone", "company", "source", "created_at"] },
  content: { columns: ["title", "type", "status", "modified"] },
  "wholesale-orders": { columns: ["title", "customer", "status", "total", "date"] },
  coupons: { columns: ["code", "discount_type", "amount", "status", "date"] },
  newsletter: { columns: ["name", "email", "source", "email_status", "created_at"] },
  reviews: { columns: ["author", "product_name", "rating", "content", "status", "date"] },
  costs: { columns: ["product_name", "unit_cost", "currency", "updated_at"] },
  distributors: { columns: ["name", "email", "phone", "status"] },
  ledger: { columns: ["date", "distributor_name", "amount", "currency", "order_id"] },
});
const COLUMN_PREFERENCE_KEY = "epic-admin-columns";

/** @param {string} resource @param {Record<string, unknown> | undefined} row @returns {string[]} */
export function columnsForResource(resource, row) {
  const columns = recordViews[resource]?.columns ?? [];
  return columns.filter((key) => row && Object.hasOwn(row, key));
}

/** @param {Storage | null} storage @param {string} resource @param {string[]} defaults @returns {string[]} */
export function readVisibleColumns(storage, resource, defaults) {
  try {
    const saved = JSON.parse(storage?.getItem(COLUMN_PREFERENCE_KEY) ?? "{}");
    const columns = saved?.[resource];
    if (!Array.isArray(columns)) return defaults;
    const valid = defaults.filter((column) => columns.includes(column));
    return valid.length ? valid : defaults;
  } catch {
    return defaults;
  }
}

/** @param {Storage | null} storage @param {string} resource @param {string[]} columns */
export function writeVisibleColumns(storage, resource, columns) {
  try {
    const saved = JSON.parse(storage?.getItem(COLUMN_PREFERENCE_KEY) ?? "{}");
    storage?.setItem(COLUMN_PREFERENCE_KEY, JSON.stringify({ ...saved, [resource]: columns }));
  } catch {
    // Column preferences are optional and never block list use.
  }
}
