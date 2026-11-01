const WHOLESALE_STATUSES = new Set(["pending", "approved", "done", "unapproved"]);
const RECIPIENT_FILTERS = new Set(["all", "vi", "en"]);
const COUPON_REDEMPTION_STATUSES = new Set(["active", "removed", "all"]);
const DIRECT_FULFILLMENTS = new Set(["courier", "self"]);
const MANUAL_DISCOUNT_TYPES = new Set(["percent", "fixed"]);

function text(value) { return typeof value === "string" ? value.trim() : ""; }

function validCalendarDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const [year, month, day] = value.split("-").map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));
  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
}

export function normalizeCouponRedemptionFilters(input = {}) {
  const from = typeof input.from === "string" ? input.from : "";
  const to = typeof input.to === "string" ? input.to : "";
  if (from && !validCalendarDate(from) || to && !validCalendarDate(to)) throw new Error("Choose valid dates for the redemption report.");
  if (from && to) {
    const days = (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000 + 1;
    if (days <= 0 || days > 366) throw new Error("Choose a date range of at most 366 days.");
  }
  const status = typeof input.status === "string" ? input.status : "all";
  if (!COUPON_REDEMPTION_STATUSES.has(status)) throw new Error("Choose a supported redemption status.");
  const page = Number(input.page ?? 1);
  if (!Number.isInteger(page) || page < 1 || page > 1000000) throw new Error("Choose a valid report page.");
  return {
    ...(from ? { date_from: from } : {}),
    ...(to ? { date_to: to } : {}),
    ...(status !== "all" ? { status } : {}),
    page,
  };
}

export function validateCouponDraft(input) {
  const code = typeof input.code === "string" ? input.code.trim() : "";
  const type = typeof input.discount_type === "string" ? input.discount_type : "";
  const amount = Number(input.amount);
  const status = typeof input.status === "string" ? input.status : "draft";
  if (!code || code.length > 64 || [...code].some((character) => character.charCodeAt(0) < 32)) throw new Error("Enter a coupon code of up to 64 characters.");
  if (!["percent", "fixed_cart", "fixed_product"].includes(type)) throw new Error("Choose a supported coupon discount type.");
  if (!Number.isFinite(amount) || amount < 0) throw new Error("Coupon amount must be non-negative.");
  if (type === "percent" && amount > 100) throw new Error("A percentage discount cannot exceed 100.");
  if (!["draft", "publish"].includes(status)) throw new Error("Coupons can only be saved as drafts or published.");
  if (status === "publish" && input.confirm_publish !== true) throw new Error("Review and confirm before publishing this coupon.");
  return { code, discount_type: type, amount, status };
}

export function validateCouponDraftForSave(input, confirmed = false) {
  return validateCouponDraft({ ...input, confirm_publish: input.status !== "publish" || confirmed });
}

export function couponDraftFromRecord(coupon) {
  return Object.fromEntries(Object.entries(coupon).filter(([key]) => key !== "id" && key !== "revision"));
}

export function validateLeadDeletion(input) {
  if (typeof input.expected_revision !== "string" || !input.expected_revision.trim()) throw new Error("Reload the lead before removing it.");
  if (input.confirm_delete !== true) throw new Error("Confirm the reviewed lead removal.");
  return { expected_revision: input.expected_revision, confirm_delete: true };
}

export function validateWholesaleOrderChange(input) {
  if (Object.hasOwn(input, "payment_status")) throw new Error("Payment status cannot be changed from the dashboard.");
  if (Object.keys(input).some((key) => !["order_status", "cancel_reason"].includes(key))) throw new Error("Only wholesale order status and unapproval reason can be changed here.");
  const status = input.order_status;
  if (typeof status !== "string" || !WHOLESALE_STATUSES.has(status)) throw new Error("Choose a supported wholesale order status.");
  const result = { order_status: status };
  if (status === "unapproved") {
    const reason = typeof input.cancel_reason === "string" ? input.cancel_reason.trim() : "";
    if (!reason || reason.length > 1000) throw new Error("A reason of up to 1,000 characters is required to unapprove this order.");
    result.cancel_reason = reason;
  } else if (typeof input.cancel_reason === "string" && input.cancel_reason.trim()) {
    result.cancel_reason = input.cancel_reason.trim().slice(0, 1000);
  }
  return result;
}

export function validateNewsletterCampaign(input, { sending = false } = {}) {
  if (sending) {
    if (input.confirm_send !== true) throw new Error("Confirm the campaign review before sending.");
    return { confirm_send: true };
  }
  const subject = typeof input.subject_vi === "string" ? input.subject_vi.trim() : "";
  const body = typeof input.body_vi === "string" ? input.body_vi.trim() : "";
  const recipient = typeof input.recipient_filter === "string" ? input.recipient_filter : "all";
  if (!subject || !body.replace(/<[^>]*>/g, "").trim()) throw new Error("Vietnamese subject and body are required.");
  if (!RECIPIENT_FILTERS.has(recipient)) throw new Error("Choose a supported recipient group.");
  return { subject_vi: subject, body_vi: body, recipient_filter: recipient };
}

export function validateManualOrderDraft(input = {}) {
  const customer = input.customer && typeof input.customer === "object" ? input.customer : {};
  const firstName = text(customer.first_name);
  const lastName = text(customer.last_name);
  const phone = text(customer.phone);
  const email = text(customer.email);
  const address1 = text(customer.address_1);
  const provinceName = text(customer.province_name ?? customer.city);
  if (!`${firstName} ${lastName}`.trim()) throw new Error("Enter the customer name.");
  if (!/^[0-9+().\-\s]{8,20}$/.test(phone)) throw new Error("Enter a valid customer phone number.");
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error("Enter a valid email address or leave it blank.");
  if (!address1) throw new Error("Enter the street address.");
  if (!provinceName) throw new Error("Choose the province/city.");
  const fulfillment = DIRECT_FULFILLMENTS.has(input.fulfillment) ? input.fulfillment : "courier";
  let shippingFee = Number(input.shipping_fee ?? 0);
  if (!Number.isFinite(shippingFee) || shippingFee < 0 || shippingFee > 100000000) throw new Error("Shipping fee must be between 0 and 100,000,000.");
  if (fulfillment === "self") shippingFee = 0;
  const rawItems = Array.isArray(input.items) ? input.items : [];
  if (!rawItems.length || rawItems.length > 50) throw new Error("Add between 1 and 50 products to the order.");
  const items = rawItems.map((raw) => {
    if (raw?.kind === "custom") {
      const name = text(raw.name);
      const recipe = text(raw.recipe);
      const packageSize = Math.trunc(Number(raw.package_size_g));
      const quantity = Math.trunc(Number(raw.quantity));
      const unitPrice = Number(raw.unit_price);
      if (!name || name.length > 120) throw new Error("Enter a custom product name of up to 120 characters.");
      if (![250, 500, 1000].includes(packageSize)) throw new Error("Choose a supported custom package size.");
      if (!Number.isInteger(quantity) || quantity < 1 || quantity > 10000) throw new Error("Each line needs a quantity between 1 and 10,000.");
      if (raw.unit_price === "" || raw.unit_price == null || !Number.isFinite(unitPrice) || unitPrice < 0 || unitPrice > 1000000000) throw new Error("Enter a custom product price between 0 and 1,000,000,000.");
      if (recipe.length > 500) throw new Error("The custom blend recipe must be under 500 characters.");
      return { kind: "custom", name, package_size_g: packageSize, recipe, quantity, unit_price: unitPrice };
    }
    const productId = Math.trunc(Number(raw?.product_id));
    const variationId = Math.trunc(Number(raw?.variation_id ?? 0));
    const quantity = Math.trunc(Number(raw?.quantity));
    if (!Number.isInteger(productId) || productId < 1) throw new Error("Each line needs a product.");
    if (!Number.isInteger(quantity) || quantity < 1 || quantity > 10000) throw new Error("Each line needs a quantity between 1 and 10,000.");
    const hasPrice = raw?.unit_price !== undefined && raw?.unit_price !== null && raw?.unit_price !== "";
    const unitPrice = hasPrice ? Number(raw.unit_price) : null;
    if (hasPrice && (!Number.isFinite(unitPrice) || unitPrice < 0 || unitPrice > 1000000000)) throw new Error("Line prices must be between 0 and 1,000,000,000.");
    return { kind: "catalog", product_id: productId, variation_id: variationId > 0 ? variationId : 0, quantity, unit_price: unitPrice, override_reason: text(raw?.override_reason).slice(0, 500) };
  });
  const couponCodes = (Array.isArray(input.coupon_codes) ? input.coupon_codes : []).map((code) => text(code)).filter(Boolean).slice(0, 10);
  let manualDiscount = null;
  if (input.manual_discount && input.manual_discount.enabled) {
    const type = MANUAL_DISCOUNT_TYPES.has(input.manual_discount.type) ? input.manual_discount.type : "fixed";
    const value = Number(input.manual_discount.value ?? 0);
    const reason = text(input.manual_discount.reason);
    if (!Number.isFinite(value) || value < 0 || (type === "percent" && value > 100)) throw new Error("Enter a discount between 0 and 100 percent, or a non-negative amount.");
    if (!reason) throw new Error("Provide a reason for the manual discount.");
    manualDiscount = { type, value, reason: reason.slice(0, 500) };
  }
  const feeLines = (Array.isArray(input.fee_lines) ? input.fee_lines : []).slice(0, 20).map((line) => {
    const name = text(line?.name);
    const amount = Number(line?.amount ?? 0);
    if (!name || !Number.isFinite(amount) || amount < 0 || amount > 100000000) throw new Error("Each fee needs a name and a non-negative amount.");
    return { name, amount };
  });
  return {
    customer: {
      first_name: firstName, last_name: lastName, phone, email, address_1: address1, address_2: text(customer.address_2),
      province_id: Math.trunc(Number(customer.province_id ?? 0)) || 0, province_name: provinceName,
      ward_id: Math.trunc(Number(customer.ward_id ?? 0)) || 0, ward_name: text(customer.ward_name), postcode: text(customer.postcode),
    },
    fulfillment, shipping_fee: shippingFee, items, coupon_codes: couponCodes,
    manual_discount: manualDiscount, fee_lines: feeLines, note: text(input.note).slice(0, 4000),
  };
}
