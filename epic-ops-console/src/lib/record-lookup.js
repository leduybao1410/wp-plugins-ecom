const ORDER_ALPHABET = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
const ORDER_CODE_RE = new RegExp(`^(?:EPIC-)?[${ORDER_ALPHABET}]{6}$`, "i");
const TRACKING_RE = /^[A-Za-z0-9-]{4,50}$/;
const TRACKING_NUMERIC_MIN = 8;

/**
 * @param {string} raw
 * @returns {{ kind: "order" | "shipment" | null, value: string }}
 */
export function detectRecordCode(raw) {
  const value = String(raw ?? "").trim();
  if (!value) return { kind: null, value: "" };
  if (/^EPIC-/i.test(value)) {
    if (ORDER_CODE_RE.test(value)) {
      const body = value.replace(/^EPIC-/i, "").toUpperCase();
      return { kind: "order", value: `EPIC-${body}` };
    }
    return { kind: null, value };
  }
  if (ORDER_CODE_RE.test(value)) {
    return { kind: "order", value: `EPIC-${value.toUpperCase()}` };
  }
  const looksLikeTracking = !value.includes(" ") && TRACKING_RE.test(value) && /\d/.test(value)
    && (/[A-Za-z-]/.test(value) || value.length >= TRACKING_NUMERIC_MIN);
  if (looksLikeTracking) return { kind: "shipment", value: value.toUpperCase() };
  return { kind: null, value };
}

/**
 * @param {{ kind: "order" | "shipment" | null, value: string, id?: number }} result
 * @returns {string}
 */
export function buildLookupHref(result) {
  if (!result || !result.kind || !result.id) return "";
  const module = result.kind === "order" ? "orders" : "shipments";
  const params = new URLSearchParams({ search: result.value, record: String(result.id) });
  return `/${module}?${params.toString()}`;
}

/**
 * @param {"order" | "shipment"} kind
 * @param {string} requested
 * @param {Record<string, unknown>[]} items
 * @returns {{ kind: "order" | "shipment", id: number, label: string, sublabel: string, href: string }[]}
 */
export function mapLookupItems(kind, requested, items) {
  const results = [];
  for (const item of items ?? []) {
    const id = Number(item.id);
    if (!Number.isInteger(id) || id <= 0) continue;
    const value = kind === "order" ? String(item.number ?? requested) : String(item.tracking ?? requested);
    const sublabel = kind === "order" ? String(item.customer ?? "") : [item.customer, item.shipment_status].filter(Boolean).join(" · ");
    results.push({ kind, id, label: `${kind === "order" ? "Đơn hàng" : "Vận đơn"} ${value}`, sublabel, href: buildLookupHref({ kind, value, id }) });
  }
  return results;
}
