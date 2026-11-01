"use client";

import { apiFetch } from "@/lib/api";

import { useCallback, useEffect, useMemo, useState } from "react";
import type { FormEvent } from "react";
import { validateManualOrderDraft } from "@/lib/business-workflows.js";
import { ProvinceSelect, WardSelect, type AddressOption } from "@/components/address-select";
import { Search, Trash2, X } from "lucide-react";

type RecordRow = Record<string, unknown>;

type LineItem = {
  kind: "catalog" | "custom";
  product_id: number;
  variation_id: number;
  name: string;
  sku: string;
  quantity: string;
  unit_price: string;
  catalog_price: string;
  override_reason: string;
  package_size_g?: string;
  recipe?: string;
};

type FeeLine = { name: string; amount: string };

type Fulfillment = "courier" | "self";

export type ManualOrderFormProps = {
  mode: "create" | "edit";
  order?: RecordRow | null;
  onSaved: (orderId?: number) => void;
  onCancel: () => void;
  onError: (message: string) => void;
};

const currency = (value: unknown, code = "VND") => {
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) return "—";
  try { return new Intl.NumberFormat("vi-VN", { style: "currency", currency: code, maximumFractionDigits: code === "VND" ? 0 : 2 }).format(numeric); }
  catch { return `${numeric.toLocaleString("vi-VN")} ${code}`; }
};

function addressOf(record: RecordRow | null | undefined, type: "billing" | "shipping"): RecordRow {
  const value = record?.[type];
  return value && typeof value === "object" ? (value as RecordRow) : {};
}

export function ManualOrderForm({ mode, order, onSaved, onCancel, onError }: ManualOrderFormProps) {
  const billing = addressOf(order, "billing");
  const shipping = addressOf(order, "shipping");
  const initialAddress = { ...shipping, ...billing };
  const orderItems = useMemo(() => (Array.isArray(order?.items) ? (order?.items as RecordRow[]) : []), [order]);

  const [firstName, setFirstName] = useState(String(initialAddress.first_name ?? ""));
  const [lastName, setLastName] = useState(String(initialAddress.last_name ?? ""));
  const [phone, setPhone] = useState(String(initialAddress.phone ?? ""));
  const [email, setEmail] = useState(String(initialAddress.email ?? ""));
  const [address1, setAddress1] = useState(String(initialAddress.address_1 ?? ""));
  const [address2, setAddress2] = useState(String(initialAddress.address_2 ?? ""));
  const [postcode, setPostcode] = useState(String(initialAddress.postcode ?? ""));
  const [provinceId, setProvinceId] = useState(order?.province_id ? String(order.province_id) : "");
  const [provinceName, setProvinceName] = useState(String(initialAddress.city ?? initialAddress.state ?? ""));
  const [wardId, setWardId] = useState(order?.ward_id ? String(order.ward_id) : "");
  const [wardName, setWardName] = useState(String(order?.ward_name ?? initialAddress.ward_name ?? ""));
  const [fulfillment, setFulfillment] = useState<Fulfillment>(String(order?.fulfillment ?? "") === "self" ? "self" : "courier");
  const [shippingFee, setShippingFee] = useState(String(order?.shipping_fee ?? ""));
  const [items, setItems] = useState<LineItem[]>(() => orderItems.map((item) => ({
    kind: item.kind === "custom" ? "custom" : "catalog",
    product_id: Number(item.product_id ?? 0),
    variation_id: Number(item.variation_id ?? 0),
    name: String(item.name ?? "Sản phẩm"),
    sku: String(item.sku ?? ""),
    quantity: String(item.quantity ?? 1),
    unit_price: String(item.unit_price ?? ""),
    catalog_price: String(item.unit_price ?? ""),
    override_reason: String(item.override_reason ?? ""),
    package_size_g: String(item.package_size_g ?? "250"),
    recipe: String(item.recipe ?? ""),
  })));
  const [couponText, setCouponText] = useState(Array.isArray(order?.coupon_codes) ? (order?.coupon_codes as unknown[]).map(String).join(", ") : "");
  const [discountEnabled, setDiscountEnabled] = useState(Boolean(order?.manual_discount));
  const [discountType, setDiscountType] = useState<"percent" | "fixed">((order?.manual_discount as RecordRow | undefined)?.type === "percent" ? "percent" : "fixed");
  const [discountValue, setDiscountValue] = useState(String((order?.manual_discount as RecordRow | undefined)?.value ?? ""));
  const [discountReason, setDiscountReason] = useState(String((order?.manual_discount as RecordRow | undefined)?.reason ?? ""));
  const [feeLines, setFeeLines] = useState<FeeLine[]>(Array.isArray(order?.fee_lines) ? (order.fee_lines as RecordRow[]).map((fee) => ({ name: String(fee.name ?? ""), amount: String(fee.amount ?? "") })) : []);
  const [note, setNote] = useState("");

  const [productSearch, setProductSearch] = useState("");
  const [productResults, setProductResults] = useState<RecordRow[]>([]);
  const [searching, setSearching] = useState(false);
  const [pendingVariation, setPendingVariation] = useState<{ product: RecordRow; variations: RecordRow[] } | null>(null);

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [preview, setPreview] = useState<RecordRow | null>(null);
  const [quote, setQuote] = useState<RecordRow | null>(null);
  const [estimating, setEstimating] = useState(false);

  // Edit mode: resolve the province id from the saved province name so the ward
  // picker can load and `_epic_vtp_province_id` is preserved on save. Create
  // mode sets the id directly from the picker.
  useEffect(() => {
    if (provinceId || !provinceName) return;
    let active = true;
    void (async () => {
      try {
        const response = await apiFetch("/api/dashboard/address/provinces");
        const payload = await response.json();
        if (active && response.ok) {
          const match = ((payload.items ?? []) as AddressOption[]).find((option) => option.name === provinceName);
          if (match) setProvinceId(match.id);
        }
      } catch { /* Province data is optional; staff can retry by reopening the form. */ }
    })();
    return () => { active = false; };
  }, [provinceId, provinceName]);

  async function searchProducts(event?: FormEvent) {
    event?.preventDefault();
    setSearching(true); setError("");
    try {
      const response = await apiFetch(`/api/dashboard/products?search=${encodeURIComponent(productSearch.trim())}&per_page=20`);
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không tìm được sản phẩm");
      setProductResults((payload.items ?? []) as RecordRow[]);
    } catch (e) { setError(e instanceof Error ? e.message : "Không tìm được sản phẩm"); }
    finally { setSearching(false); }
  }

  function addLine(product: RecordRow, variation?: RecordRow) {
    const price = String((variation?.price ?? variation?.regular_price ?? product.price ?? "") || "");
    setItems((current) => [...current, {
      kind: "catalog",
      product_id: Number(product.id),
      variation_id: variation ? Number(variation.id) : 0,
      name: variation ? `${String(product.name)} — ${String(variation.name ?? variation.id)}` : String(product.name ?? "Sản phẩm"),
      sku: String(variation?.sku ?? product.sku ?? ""),
      quantity: "1",
      unit_price: price,
      catalog_price: price,
      override_reason: "",
    }]);
  }

  function addCustomLine() {
    setItems((current) => [...current, {
      kind: "custom", product_id: 0, variation_id: 0, name: "Pha trộn tùy chỉnh", sku: "",
      quantity: "1", unit_price: "", catalog_price: "", override_reason: "", package_size_g: "250", recipe: "",
    }]);
  }

  async function chooseProduct(product: RecordRow) {
    if (String(product.type) === "variable") {
      setSearching(true); setError("");
      try {
        const response = await apiFetch(`/api/dashboard/products/${Number(product.id)}/variations?per_page=100`);
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.error || "Không tải được biến thể");
        const variations = (payload.items ?? []) as RecordRow[];
        if (!variations.length) throw new Error("Sản phẩm chưa có biến thể khả dụng.");
        setPendingVariation({ product, variations });
      } catch (e) { setError(e instanceof Error ? e.message : "Không tải được biến thể"); }
      finally { setSearching(false); }
      return;
    }
    addLine(product);
  }

  function updateItem(index: number, key: keyof LineItem, value: string) {
    setItems((current) => current.map((item, position) => (position === index ? { ...item, [key]: value } : item)));
  }

  /**
   * Asks the adapter for the ViettelPost fee of the current draft. The total
   * already includes the fuel surcharge and VAT; staff see the components, the
   * customer only ever sees the total.
   */
  async function estimateShipping() {
    if (!provinceId) { setError("Chọn tỉnh/thành trước khi ước tính phí vận chuyển."); return; }
    if (!address1.trim()) { setError("Nhập địa chỉ giao hàng trước khi ước tính phí vận chuyển."); return; }
    if (!items.length) { setError("Thêm sản phẩm trước khi ước tính phí vận chuyển."); return; }
    setEstimating(true); setError(""); setQuote(null);
    try {
      const response = await apiFetch("/api/dashboard/shipping/quote", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          customer: { province_id: provinceId, province_name: provinceName, ward_name: wardName, address_1: address1, address_2: address2 },
          items: items.map((item) => item.kind === "custom"
            ? { kind: "custom", package_size_g: Number(item.package_size_g), quantity: Number(item.quantity), unit_price: item.unit_price === "" ? null : Number(item.unit_price) }
            : { kind: "catalog", product_id: item.product_id, variation_id: item.variation_id, quantity: Number(item.quantity), unit_price: item.unit_price === "" ? null : Number(item.unit_price) }),
        }),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không ước tính được phí vận chuyển");
      setQuote(payload as RecordRow);
      if (Number(payload.total) > 0) setShippingFee(String(Math.round(Number(payload.total))));
    } catch (e) { setError(e instanceof Error ? e.message : "Không ước tính được phí vận chuyển"); }
    finally { setEstimating(false); }
  }

  function buildDraft() {
    const normalized = validateManualOrderDraft({
      customer: {
        first_name: firstName, last_name: lastName, phone, email, address_1: address1, address_2: address2,
        province_id: provinceId, province_name: provinceName, ward_id: wardId, ward_name: wardName, postcode,
      },
      fulfillment,
      shipping_fee: shippingFee === "" ? 0 : Number(shippingFee),
      items: items.map((item) => item.kind === "custom" ? {
        kind: "custom", name: item.name, package_size_g: Number(item.package_size_g), recipe: item.recipe ?? "",
        quantity: Number(item.quantity), unit_price: item.unit_price === "" ? null : Number(item.unit_price),
      } : {
        kind: "catalog", product_id: item.product_id, variation_id: item.variation_id, quantity: Number(item.quantity),
        unit_price: item.unit_price === "" ? null : Number(item.unit_price), override_reason: item.override_reason,
      }),
      coupon_codes: couponText.split(",").map((code) => code.trim()).filter(Boolean),
      manual_discount: discountEnabled ? { enabled: true, type: discountType, value: Number(discountValue || 0), reason: discountReason } : null,
      fee_lines: feeLines.map((fee) => ({ name: fee.name, amount: Number(fee.amount || 0) })),
      note,
    });
    return {
      action: mode === "edit" ? "update" : "create",
      ...(mode === "edit" ? { order_id: Number(order?.id ?? 0), expected_revision: String(order?.revision ?? "") } : {}),
      ...normalized,
    };
  }

  const requestPreview = useCallback(async () => {
    setBusy(true); setError(""); setPreview(null);
    try {
      const response = await apiFetch("/api/dashboard/orders/preview", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(buildDraft()) });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không xem trước được đơn hàng");
      setPreview(payload);
    } catch (e) { setError(e instanceof Error ? e.message : "Không xem trước được đơn hàng"); }
    finally { setBusy(false); }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [firstName, lastName, phone, email, address1, address2, provinceId, provinceName, wardId, wardName, postcode, fulfillment, shippingFee, items, couponText, discountEnabled, discountType, discountValue, discountReason, feeLines, note, mode, order]);

  async function applyPreview() {
    if (typeof preview?.preview_token !== "string") return;
    if (!window.confirm(mode === "edit" ? "Áp dụng các thay đổi đã xem cho đơn hàng này?" : "Tạo đơn trực tiếp (COD) từ các thông tin đã xem?")) return;
    setBusy(true); setError("");
    try {
      const response = await apiFetch("/api/dashboard/orders/apply", { method: "POST", headers: { "Content-Type": "application/json", "Idempotency-Key": crypto.randomUUID() }, body: JSON.stringify({ preview_token: preview.preview_token }) });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không lưu được đơn hàng");
      onSaved(Number(payload.id));
    } catch (e) { onError(e instanceof Error ? e.message : "Không lưu được đơn hàng"); }
    finally { setBusy(false); }
  }

  const totals = (preview?.totals ?? {}) as RecordRow;

  return <div className="manual-order-form" style={{ display: "grid", gap: 14 }}>
    <div className="detail-header">
      <h2 style={{ margin: 0, fontSize: 18 }}>{mode === "edit" ? `Sửa đơn trực tiếp #${String(order?.number ?? order?.id ?? "")}` : "Tạo đơn trực tiếp (điện thoại/Zalo)"}</h2>
      <button className="icon-button" type="button" aria-label="Đóng" onClick={onCancel}><X size={18} /></button>
    </div>

    {error && <div className="error-box" role="alert">{error}</div>}

    <fieldset className="detail-section" style={{ display: "grid", gap: 8 }}>
      <legend>Khách hàng</legend>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(180px, 1fr))", gap: 8 }}>
        <label>Họ<input className="search-input" value={lastName} onChange={(e) => setLastName(e.target.value)} /></label>
        <label>Tên<input className="search-input" value={firstName} onChange={(e) => setFirstName(e.target.value)} /></label>
        <label>Điện thoại *<input className="search-input" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} /></label>
        <label>Email (tùy chọn)<input className="search-input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} /></label>
      </div>
      <small className="muted">Có email sẽ tự tạo hoặc liên kết tài khoản khách hàng. Không có email thì đơn là khách vãng lai và không gửi email xác nhận.</small>
      <label>Tỉnh/Thành phố *<ProvinceSelect initialName={provinceName} placeholder="Nhập để tìm tỉnh/thành…" onChange={(id, name) => { setProvinceId(id); setProvinceName(name); setWardId(""); setWardName(""); }} /></label>
      <label>Phường/Xã<WardSelect key={provinceId} provinceId={provinceId} initialName={wardName} placeholder={provinceId ? "Nhập để tìm phường/xã…" : "Chọn tỉnh/thành trước"} onChange={(id, name) => { setWardId(id); setWardName(name); }} /></label>
      <label>Địa chỉ *<input className="search-input" value={address1} onChange={(e) => setAddress1(e.target.value)} /></label>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(180px, 1fr))", gap: 8 }}>
        <label>Địa chỉ bổ sung<input className="search-input" value={address2} onChange={(e) => setAddress2(e.target.value)} /></label>
        <label>Mã bưu chính<input className="search-input" value={postcode} onChange={(e) => setPostcode(e.target.value)} /></label>
      </div>
    </fieldset>

    <fieldset className="detail-section" style={{ display: "grid", gap: 8 }}>
      <legend>Thanh toán &amp; giao hàng</legend>
      <p className="muted" style={{ margin: 0 }}>Thanh toán khi nhận hàng (COD). Đơn không được xác nhận thanh toán từ dashboard.</p>
      <div style={{ display: "flex", gap: 16, flexWrap: "wrap" }}>
        <label><input type="radio" name="fulfillment" checked={fulfillment === "courier"} onChange={() => setFulfillment("courier")} /> Giao qua đối tác (ViettelPost)</label>
        <label><input type="radio" name="fulfillment" checked={fulfillment === "self"} onChange={() => setFulfillment("self")} /> Nhân viên giao trực tiếp</label>
      </div>
      <label>Phí vận chuyển thu khách
        <div style={{ display: "flex", gap: 8 }}>
          <input className="search-input" inputMode="numeric" value={fulfillment === "self" ? "0" : shippingFee} disabled={fulfillment === "self"} onChange={(e) => { setShippingFee(e.target.value); setQuote(null); }} />
          <button className="quiet-button" type="button" disabled={estimating || fulfillment === "self"} onClick={() => void estimateShipping()}>{estimating ? "Đang tính…" : "Ước tính"}</button>
        </div>
        <small className="muted">Đối tác giao hàng sẽ được đặt sau ở trang Vận chuyển. Tổng phí đã gồm phụ phí xăng dầu và VAT.</small>
        {quote && <small className="muted" style={{ display: "block", marginTop: 4 }}>
          ViettelPost · {String(quote.service_name || quote.service || "")} · {Number(quote.weight_g || 0).toLocaleString("vi-VN")} g
          {quote.itemized ? ` · Cước chính ${currency(quote.base)} + xăng dầu ${currency(quote.fuel)} + VAT ${currency(quote.vat)}` : " · chưa có chi tiết phụ phí"}
          {" = "}<strong>{currency(quote.total)}</strong>
        </small>}
      </label>
    </fieldset>

    <fieldset className="detail-section" style={{ display: "grid", gap: 8 }}>
      <legend>Sản phẩm trong đơn *</legend>
      <form onSubmit={searchProducts} style={{ display: "flex", gap: 8 }}>
        <input className="search-input" value={productSearch} onChange={(e) => setProductSearch(e.target.value)} placeholder="Tìm sản phẩm theo tên hoặc SKU…" />
        <button className="quiet-button" type="submit" disabled={searching}><Search size={15} aria-hidden="true" /> Tìm</button>
      </form>
      {productResults.length > 0 && <div className="card" style={{ padding: 8, display: "grid", gap: 4 }}>{productResults.map((product) => <button key={String(product.id)} type="button" className="quiet-button" style={{ justifyContent: "space-between", textAlign: "left" }} onClick={() => void chooseProduct(product)}><span>{String(product.name)} <small className="muted">SKU {String(product.sku || "—")} · {String(product.type)}</small></span><span className="muted">{currency(product.price)}</span></button>)}</div>}
      <button className="quiet-button" type="button" onClick={addCustomLine}>+ Thêm sản phẩm tùy chỉnh</button>
      {pendingVariation && <div className="card" style={{ padding: 10, display: "grid", gap: 6 }}><strong>Chọn biến thể cho {String(pendingVariation.product.name)}</strong>{pendingVariation.variations.map((variation) => <div key={String(variation.id)} className="alert-row"><span>{String(variation.name ?? `#${variation.id}`)} <small className="muted">SKU {String(variation.sku || "—")}</small></span><span style={{ display: "flex", gap: 8, alignItems: "center" }}><span className="muted">{currency(variation.price ?? variation.regular_price)}</span><button className="quiet-button" type="button" onClick={() => { addLine(pendingVariation.product, variation); setPendingVariation(null); }}>Thêm</button></span></div>)}<button className="quiet-button" type="button" onClick={() => setPendingVariation(null)}>Đóng</button></div>}
      {items.length === 0 && <p className="muted">Chưa có sản phẩm. Tìm và thêm sản phẩm ở trên.</p>}
      {items.map((item, index) => <div key={`${item.kind}-${item.product_id}-${item.variation_id}-${index}`} className="alert-row" style={{ display: "grid", gap: 6 }}>
        <div style={{ display: "flex", justifyContent: "space-between", gap: 8 }}><span><strong>{item.kind === "custom" ? "Sản phẩm tùy chỉnh" : item.name}</strong>{item.kind === "catalog" && <small className="muted" style={{ display: "block" }}>SKU {item.sku || "—"} · Giá niêm yết {currency(item.catalog_price)}</small>}</span><button className="icon-button" type="button" aria-label={`Xóa ${item.name}`} onClick={() => setItems((current) => current.filter((_, position) => position !== index))}><Trash2 size={15} /></button></div>
        {item.kind === "custom" && <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))", gap: 8 }}>
          <label>Tên sản phẩm<input className="search-input" value={item.name} onChange={(e) => updateItem(index, "name", e.target.value)} maxLength={120} /></label>
          <label>Khối lượng mỗi gói<select className="search-input" value={item.package_size_g} onChange={(e) => updateItem(index, "package_size_g", e.target.value)}><option value="250">250 g</option><option value="500">500 g</option><option value="1000">1 kg</option></select></label>
          <label>Tỷ lệ pha (tùy chọn)<input className="search-input" value={item.recipe} onChange={(e) => updateItem(index, "recipe", e.target.value)} placeholder="VD: 60% Arabica, 40% Robusta" maxLength={500} /></label>
        </div>}
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(120px, 1fr))", gap: 8 }}>
          <label>{item.kind === "custom" ? "Số gói" : "Số lượng"}<input className="search-input" inputMode="numeric" value={item.quantity} onChange={(e) => updateItem(index, "quantity", e.target.value)} /></label>
          <label>{item.kind === "custom" ? "Giá mỗi gói" : "Đơn giá"}<input className="search-input" inputMode="decimal" value={item.unit_price} onChange={(e) => updateItem(index, "unit_price", e.target.value)} /></label>
          {item.kind === "catalog" && <label>Lý do sửa giá<input className="search-input" value={item.override_reason} onChange={(e) => updateItem(index, "override_reason", e.target.value)} /></label>}
        </div>
      </div>)}
    </fieldset>

    <fieldset className="detail-section" style={{ display: "grid", gap: 8 }}>
      <legend>Giảm giá &amp; phụ phí</legend>
      <label>Mã giảm giá (phân tách bằng dấu phẩy)<input className="search-input" value={couponText} onChange={(e) => setCouponText(e.target.value)} placeholder="VD: SPRING20" /></label>
      <label><input type="checkbox" checked={discountEnabled} onChange={(e) => setDiscountEnabled(e.target.checked)} /> Giảm giá thủ công</label>
      {discountEnabled && <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(140px, 1fr))", gap: 8 }}>
        <label>Loại<select className="search-input" value={discountType} onChange={(e) => setDiscountType(e.target.value === "percent" ? "percent" : "fixed")}><option value="fixed">Số tiền</option><option value="percent">Phần trăm</option></select></label>
        <label>Giá trị<input className="search-input" inputMode="decimal" value={discountValue} onChange={(e) => setDiscountValue(e.target.value)} /></label>
        <label>Lý do *<input className="search-input" value={discountReason} onChange={(e) => setDiscountReason(e.target.value)} /></label>
      </div>}
      {feeLines.map((fee, index) => <div key={`fee-${index}`} style={{ display: "grid", gridTemplateColumns: "2fr 1fr auto", gap: 8, alignItems: "end" }}>
        <label>Phụ phí<input className="search-input" value={fee.name} onChange={(e) => setFeeLines((current) => current.map((entry, position) => position === index ? { ...entry, name: e.target.value } : entry))} /></label>
        <label>Số tiền<input className="search-input" inputMode="decimal" value={fee.amount} onChange={(e) => setFeeLines((current) => current.map((entry, position) => position === index ? { ...entry, amount: e.target.value } : entry))} /></label>
        <button className="icon-button" type="button" aria-label="Xóa phụ phí" onClick={() => setFeeLines((current) => current.filter((_, position) => position !== index))}><Trash2 size={15} /></button>
      </div>)}
      <button className="quiet-button" type="button" onClick={() => setFeeLines((current) => [...current, { name: "", amount: "" }])}>+ Thêm phụ phí</button>
      <label>Ghi chú nội bộ<textarea className="search-input" style={{ minHeight: 70, padding: 10 }} value={note} onChange={(e) => setNote(e.target.value)} /></label>
    </fieldset>

    <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
      <button className="quiet-button" type="button" disabled={busy} onClick={() => void requestPreview()}>Xem trước đơn</button>
      <button className="quiet-button" type="button" disabled={busy} onClick={onCancel}>Hủy</button>
    </div>

    {preview && <section className="preview-panel" aria-label="Xem trước đơn hàng">
      <h4>{mode === "edit" ? "Đơn hàng sau khi sửa" : "Đơn hàng sẽ tạo"}</h4>
      {Array.isArray(preview.warnings) && preview.warnings.map((warning, index) => <p className="warning-box" role="note" key={index}>{String(warning)}</p>)}
      <div className="preview-diff">
        {[["Tạm tính", "subtotal"], ["Giảm giá mã", "coupon_discount"], ["Giảm giá thủ công", "manual_discount"], ["Phí vận chuyển", "shipping"], ["Phụ phí", "fees"], ["Tổng cộng", "total"]].map(([label, key]) => <div className="preview-change" key={key}><strong>{label}</strong><div style={{ gridColumn: "2 / -1" }}><span>{currency(totals[key], String(totals.currency ?? "VND"))}</span></div></div>)}
      </div>
      <button className="action-button" type="button" disabled={busy || typeof preview.preview_token !== "string"} onClick={() => void applyPreview()}>{mode === "edit" ? "Áp dụng thay đổi đã xem" : "Tạo đơn COD"}</button>
    </section>}
  </div>;
}
