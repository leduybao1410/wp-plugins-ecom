"use client";

import { apiFetch } from "@/lib/api";

import { DatePicker } from "./date-picker";

import { useCallback, useEffect, useState } from "react";
import { useSearchParams } from "@/lib/router";
import { couponDraftFromRecord, validateCouponDraftForSave } from "@/lib/business-workflows.js";

type AdvancedRules = {
  first_order_only: boolean; allowlist: string; schedule_days: string[]; schedule_start: string; schedule_end: string;
  bxgy: { enabled: boolean; trigger_type: "product" | "category"; trigger_id: number; trigger_qty: number; reward_type: "product" | "category"; reward_id: number; reward_qty: number; discount_type: "free" | "percent" | "fixed"; discount_value: number; max_repeats: number };
  auto_apply_enabled: boolean; auto_apply_category: number;
};
type Coupon = { id: number; code: string; description: string; discount_type: string; amount: string | number; status: string; individual_use: boolean; usage_limit: number; usage_limit_per_user: number; limit_usage_to_x_items: number; free_shipping: boolean; minimum_amount: string | number; maximum_amount: string | number; email_restrictions: string[]; product_ids: number[]; excluded_product_ids: number[]; date_expires: string; advanced_rules: AdvancedRules | null; revision: string };
type CouponForm = Omit<Coupon, "id" | "revision">;
type ListResult = { items?: Array<{ id: number; code: string; title: string; discount_type: string; amount: number | string; status: string; usage_count: number; modified: string }>; total?: number; advanced_rules_enabled?: boolean };
const days = [["mon", "Thứ Hai"], ["tue", "Thứ Ba"], ["wed", "Thứ Tư"], ["thu", "Thứ Năm"], ["fri", "Thứ Sáu"], ["sat", "Thứ Bảy"], ["sun", "Chủ Nhật"]] as const;
const emptyRules: AdvancedRules = { first_order_only: false, allowlist: "", schedule_days: [], schedule_start: "", schedule_end: "", bxgy: { enabled: false, trigger_type: "product", trigger_id: 0, trigger_qty: 1, reward_type: "product", reward_id: 0, reward_qty: 1, discount_type: "free", discount_value: 0, max_repeats: 1 }, auto_apply_enabled: false, auto_apply_category: 0 };
const emptyForm: CouponForm = { code: "", description: "", discount_type: "fixed_cart", amount: "", status: "draft", individual_use: false, usage_limit: 0, usage_limit_per_user: 0, limit_usage_to_x_items: 0, free_shipping: false, minimum_amount: "", maximum_amount: "", email_restrictions: [], product_ids: [], excluded_product_ids: [], date_expires: "", advanced_rules: null };
const pageSize = 20;

export function CouponManager() {
  const params = useSearchParams();
  const [search, setSearch] = useState(params.get("search") ?? "");
  const [status, setStatus] = useState(params.get("status") ?? "");
  const [page, setPage] = useState(Number(params.get("page") ?? "1") || 1);
  const [query, setQuery] = useState({ search: params.get("search") ?? "", status: params.get("status") ?? "", page: Number(params.get("page") ?? "1") || 1 });
  const [result, setResult] = useState<ListResult | null>(null);
  const [selected, setSelected] = useState<Coupon | null>(null);
  const [form, setForm] = useState<CouponForm>(emptyForm);
  const [editorOpen, setEditorOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const load = useCallback(async () => {
    setBusy(true); setError("");
    try {
      const filters = new URLSearchParams({ page: String(query.page), per_page: String(pageSize) });
      if (query.search.trim()) filters.set("search", query.search.trim());
      if (query.status) filters.set("status", query.status);
      const url = new URL(window.location.href);
      for (const key of ["page", "per_page", "search", "status"]) url.searchParams.delete(key);
      filters.forEach((value, key) => url.searchParams.set(key, value));
      window.history.replaceState(null, "", `${url.pathname}${url.search}`);
      const response = await apiFetch(`/api/dashboard/coupons?${filters}`);
      const data: ListResult & { error?: string } = await response.json();
      if (!response.ok) throw new Error(data.error || "Không tải được mã giảm giá");
      setResult(data);
    } catch (e) { setError(e instanceof Error ? e.message : "Không tải được mã giảm giá"); }
    finally { setBusy(false); }
  }, [query]);
  useEffect(() => { const timer = window.setTimeout(() => { void load(); }, 0); return () => window.clearTimeout(timer); }, [load]);

  function setFromCoupon(coupon: Coupon) {
    setSelected(coupon);
    setEditorOpen(true);
    setForm({ ...(couponDraftFromRecord(coupon) as CouponForm), email_restrictions: coupon.email_restrictions ?? [], product_ids: coupon.product_ids ?? [], excluded_product_ids: coupon.excluded_product_ids ?? [] });
    setNotice(""); setError("");
  }

  async function edit(id: number) {
    setBusy(true); setError(""); setNotice("");
    try {
      const response = await apiFetch(`/api/dashboard/coupons/${id}`);
      const data: Coupon & { error?: string } = await response.json();
      if (!response.ok) throw new Error(data.error || "Không tải được coupon");
      setFromCoupon(data);
    } catch (e) { setError(e instanceof Error ? e.message : "Không tải được coupon"); }
    finally { setBusy(false); }
  }

  function create() {
    setSelected(null); setEditorOpen(true); setForm({ ...emptyForm, advanced_rules: result?.advanced_rules_enabled ? structuredClone(emptyRules) : null }); setError(""); setNotice("");
  }

  async function save(event: React.FormEvent) {
    event.preventDefault(); setError(""); setNotice("");
    const confirmed = form.status !== "publish" || window.confirm(`Xác nhận xuất bản coupon “${form.code}”? Coupon sẽ được áp dụng tại nơi bán có hỗ trợ mã này.`);
    if (!confirmed) return;
    let validated: ReturnType<typeof validateCouponDraftForSave>;
    try { validated = validateCouponDraftForSave(form, confirmed); }
    catch (e) { setError(e instanceof Error ? e.message : "Kiểm tra lại thông tin coupon"); return; }
    const { advanced_rules, ...nativeFields } = form;
    const body: Record<string, unknown> = {
      ...nativeFields, ...validated,
      email_restrictions: form.email_restrictions,
      product_ids: form.product_ids,
      excluded_product_ids: form.excluded_product_ids,
      confirm_publish: validated.status === "publish",
      ...(advanced_rules ? { advanced_rules } : {}),
      ...(selected ? { expected_revision: selected.revision } : {}),
    };
    setBusy(true);
    try {
      const response = await apiFetch(selected ? `/api/dashboard/coupons/${selected.id}` : "/api/dashboard/coupons", {
        method: selected ? "PATCH" : "POST",
        headers: { "Content-Type": "application/json", ...(!selected ? { "Idempotency-Key": crypto.randomUUID() } : {}) },
        body: JSON.stringify(body),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không lưu được coupon");
      setFromCoupon(payload.coupon as Coupon);
      setNotice(selected ? "Đã lưu coupon." : "Đã tạo bản nháp coupon. Hãy rà soát trước khi xuất bản.");
      await load();
    } catch (e) { setError(e instanceof Error ? e.message : "Không lưu được coupon"); }
    finally { setBusy(false); }
  }

  const setField = <K extends keyof CouponForm>(key: K, value: CouponForm[K]) => setForm((old) => ({ ...old, [key]: value }));
  const setRules = (update: Partial<AdvancedRules>) => setForm((old) => old.advanced_rules ? { ...old, advanced_rules: { ...old.advanced_rules, ...update } } : old);
  const updateBxgy = (update: Partial<AdvancedRules["bxgy"]>) => setForm((old) => old.advanced_rules ? { ...old, advanced_rules: { ...old.advanced_rules, bxgy: { ...old.advanced_rules.bxgy, ...update } } } : old);

  return <>
    <div className="page-heading"><div><div className="eyebrow">EPIC / Tiếp thị</div><h1>Mã giảm giá</h1><p>Quản lý coupon WooCommerce và các quy tắc mở rộng của EPIC Advanced Coupons.</p></div><button type="button" className="action-button" onClick={create}>Tạo mã nháp</button></div>
    {notice && <div className="success-box" role="status">{notice}</div>}{error && <div className="error-box" role="alert">{error}</div>}
    <section className="card"><form className="toolbar" onSubmit={(event) => { event.preventDefault(); setPage(1); setQuery({ search, status, page: 1 }); }}><label className="sr-only" htmlFor="coupon-search">Tìm mã coupon</label><input id="coupon-search" className="search-input" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Tìm mã coupon…" /><label className="sr-only" htmlFor="coupon-status">Trạng thái</label><select id="coupon-status" className="search-input" value={status} onChange={(event) => setStatus(event.target.value)}><option value="">Tất cả trạng thái</option><option value="publish">Đang xuất bản</option><option value="draft">Bản nháp</option></select><button className="quiet-button" type="submit" disabled={busy}>Tìm kiếm</button><span className="muted">{busy ? "Đang tải…" : `${(result?.total ?? 0).toLocaleString("vi-VN")} mã`}</span></form>
      <div className="table-wrap"><table><thead><tr><th>Mã coupon</th><th>Loại</th><th>Mức giảm</th><th>Lượt dùng</th><th>Trạng thái</th><th>Cập nhật</th><th /></tr></thead><tbody>{result?.items?.map((coupon) => <tr key={coupon.id}><td><strong>{coupon.code || coupon.title}</strong></td><td>{discountLabel(coupon.discount_type)}</td><td>{coupon.discount_type === "percent" ? `${coupon.amount}%` : `${Number(coupon.amount).toLocaleString("vi-VN")} ₫`}</td><td>{coupon.usage_count.toLocaleString("vi-VN")}</td><td><span className="status" data-status={coupon.status === "publish" ? "approved" : "draft"}>{coupon.status === "publish" ? "Đang xuất bản" : "Bản nháp"}</span></td><td>{new Intl.DateTimeFormat("vi-VN", { dateStyle: "medium", timeZone: "Asia/Ho_Chi_Minh" }).format(new Date(coupon.modified))}</td><td><button className="quiet-button" type="button" disabled={busy} onClick={() => void edit(coupon.id)}>Chỉnh sửa</button></td></tr>)}{!busy && !result?.items?.length && <tr><td colSpan={7} className="empty">Chưa có mã coupon phù hợp.</td></tr>}</tbody></table></div>
      <div className="toolbar"><span className="muted">Trang {page}</span><div style={{ display: "flex", gap: 8 }}><button className="quiet-button" type="button" disabled={busy || page <= 1} onClick={() => { const next = page - 1; setPage(next); setQuery({ ...query, page: next }); }}>Trước</button><button className="quiet-button" type="button" disabled={busy || (result?.total != null && page * pageSize >= result.total)} onClick={() => { const next = page + 1; setPage(next); setQuery({ ...query, page: next }); }}>Tiếp</button></div></div>
    </section>
    {editorOpen && <section className="card section-card coupon-editor"><div className="toolbar"><h2 style={{ margin: 0 }}>{selected ? `Coupon: ${selected.code}` : "Tạo coupon bản nháp"}</h2><button className="quiet-button" type="button" onClick={() => { setSelected(null); setEditorOpen(false); }}>Đóng biểu mẫu</button></div>
      <form onSubmit={(event) => void save(event)} className="coupon-form">
        <label htmlFor="coupon-code">Mã coupon</label><input id="coupon-code" className="search-input" value={form.code} onChange={(event) => setField("code", event.target.value)} maxLength={64} required />
        <label htmlFor="coupon-description">Mô tả nội bộ</label><textarea id="coupon-description" className="search-input" style={{ minHeight: 72, padding: 10 }} value={form.description} onChange={(event) => setField("description", event.target.value)} />
        <label htmlFor="coupon-type">Loại giảm giá</label><select id="coupon-type" className="search-input" value={form.discount_type} onChange={(event) => setField("discount_type", event.target.value)}><option value="fixed_cart">Giảm cố định trên đơn</option><option value="fixed_product">Giảm cố định trên sản phẩm</option><option value="percent">Giảm theo phần trăm</option></select>
        <label htmlFor="coupon-amount">Mức giảm</label><input id="coupon-amount" className="search-input" inputMode="decimal" value={form.amount} onChange={(event) => setField("amount", event.target.value)} required />
        <label htmlFor="coupon-status-edit">Trạng thái</label><select id="coupon-status-edit" className="search-input" value={form.status} disabled={!selected} onChange={(event) => setField("status", event.target.value)}><option value="draft">Bản nháp</option><option value="publish">Đang xuất bản</option></select>
        <label htmlFor="coupon-expiry">Ngày hết hạn</label><DatePicker id="coupon-expiry" label="Ngày hết hạn" value={form.date_expires} onChange={(value) => setField("date_expires", value)} />
        <label htmlFor="coupon-minimum">Đơn tối thiểu (₫)</label><input id="coupon-minimum" className="search-input" inputMode="decimal" value={form.minimum_amount} onChange={(event) => setField("minimum_amount", event.target.value)} />
        <label htmlFor="coupon-maximum">Đơn tối đa (₫)</label><input id="coupon-maximum" className="search-input" inputMode="decimal" value={form.maximum_amount} onChange={(event) => setField("maximum_amount", event.target.value)} />
        <label htmlFor="coupon-usage-limit">Giới hạn tổng lượt dùng</label><input id="coupon-usage-limit" className="search-input" inputMode="numeric" value={form.usage_limit} onChange={(event) => setField("usage_limit", Number(event.target.value) || 0)} />
        <label htmlFor="coupon-user-limit">Lượt tối đa mỗi người</label><input id="coupon-user-limit" className="search-input" inputMode="numeric" value={form.usage_limit_per_user} onChange={(event) => setField("usage_limit_per_user", Number(event.target.value) || 0)} />
        <label htmlFor="coupon-item-limit">Sản phẩm giảm giá tối đa</label><input id="coupon-item-limit" className="search-input" inputMode="numeric" value={form.limit_usage_to_x_items} onChange={(event) => setField("limit_usage_to_x_items", Number(event.target.value) || 0)} />
        <label htmlFor="coupon-products">Chỉ áp dụng cho ID sản phẩm (phân cách dấu phẩy)</label><input id="coupon-products" className="search-input" value={form.product_ids.join(", ")} onChange={(event) => setField("product_ids", parseIds(event.target.value))} />
        <label htmlFor="coupon-excluded">Loại trừ ID sản phẩm</label><input id="coupon-excluded" className="search-input" value={form.excluded_product_ids.join(", ")} onChange={(event) => setField("excluded_product_ids", parseIds(event.target.value))} />
        <label htmlFor="coupon-emails">Email được phép (mỗi dòng một email)</label><textarea id="coupon-emails" className="search-input" style={{ minHeight: 72, padding: 10 }} value={form.email_restrictions.join("\n")} onChange={(event) => setField("email_restrictions", event.target.value.split(/[\n,]+/).map((email) => email.trim()).filter(Boolean))} />
        <label className="coupon-check"><input type="checkbox" checked={form.individual_use} onChange={(event) => setField("individual_use", event.target.checked)} /> Không kết hợp với coupon khác</label>
        <label className="coupon-check"><input type="checkbox" checked={form.free_shipping} onChange={(event) => setField("free_shipping", event.target.checked)} /> Cho phép miễn phí vận chuyển</label>
        {form.advanced_rules && <div className="coupon-advanced"><h3>Quy tắc mở rộng</h3>
          <label className="coupon-check"><input type="checkbox" checked={form.advanced_rules.first_order_only} onChange={(event) => setRules({ first_order_only: event.target.checked })} /> Chỉ khách mua lần đầu</label>
          <label htmlFor="coupon-allowlist">Danh sách email, wildcard hoặc số điện thoại được phép</label><textarea id="coupon-allowlist" className="search-input" style={{ minHeight: 76, padding: 10 }} value={form.advanced_rules.allowlist} onChange={(event) => setRules({ allowlist: event.target.value })} />
          <label>Ngày có hiệu lực</label><div className="coupon-days">{days.map(([key, label]) => <label key={key}><input type="checkbox" checked={form.advanced_rules?.schedule_days.includes(key) ?? false} onChange={(event) => setRules({ schedule_days: event.target.checked ? [...(form.advanced_rules?.schedule_days ?? []), key] : (form.advanced_rules?.schedule_days ?? []).filter((day) => day !== key) })} />{label}</label>)}</div>
          <label htmlFor="coupon-start">Bắt đầu (giờ địa phương WordPress)</label><input id="coupon-start" type="time" className="search-input" value={form.advanced_rules.schedule_start} onChange={(event) => setRules({ schedule_start: event.target.value })} />
          <label htmlFor="coupon-end">Kết thúc</label><input id="coupon-end" type="time" className="search-input" value={form.advanced_rules.schedule_end} onChange={(event) => setRules({ schedule_end: event.target.value })} />
          <label className="coupon-check"><input type="checkbox" checked={form.advanced_rules.bxgy.enabled} onChange={(event) => updateBxgy({ enabled: event.target.checked })} /> Bật mua X tặng Y / ưu đãi theo nhóm</label>
          <div className="coupon-rule-grid"><RuleTarget title="Sản phẩm hoặc danh mục cần mua" targetType={form.advanced_rules.bxgy.trigger_type} targetId={form.advanced_rules.bxgy.trigger_id} onType={(value) => updateBxgy({ trigger_type: value })} onId={(value) => updateBxgy({ trigger_id: value })} /><label htmlFor="coupon-trigger-qty">Số lượng cần mua</label><input id="coupon-trigger-qty" className="search-input" inputMode="numeric" value={form.advanced_rules.bxgy.trigger_qty} onChange={(event) => updateBxgy({ trigger_qty: Number(event.target.value) || 1 })} />
            <RuleTarget title="Sản phẩm hoặc danh mục được tặng" targetType={form.advanced_rules.bxgy.reward_type} targetId={form.advanced_rules.bxgy.reward_id} onType={(value) => updateBxgy({ reward_type: value })} onId={(value) => updateBxgy({ reward_id: value })} /><label htmlFor="coupon-reward-qty">Số lượng được tặng</label><input id="coupon-reward-qty" className="search-input" inputMode="numeric" value={form.advanced_rules.bxgy.reward_qty} onChange={(event) => updateBxgy({ reward_qty: Number(event.target.value) || 1 })} />
            <label htmlFor="coupon-bxgy-type">Ưu đãi sản phẩm tặng</label><select id="coupon-bxgy-type" className="search-input" value={form.advanced_rules.bxgy.discount_type} onChange={(event) => updateBxgy({ discount_type: event.target.value as AdvancedRules["bxgy"]["discount_type"] })}><option value="free">Miễn phí</option><option value="percent">Giảm phần trăm</option><option value="fixed">Giảm số tiền</option></select><label htmlFor="coupon-bxgy-value">Mức giảm</label><input id="coupon-bxgy-value" className="search-input" inputMode="decimal" value={form.advanced_rules.bxgy.discount_value} onChange={(event) => updateBxgy({ discount_value: Number(event.target.value) || 0 })} /><label htmlFor="coupon-bxgy-repeats">Số lần lặp tối đa (0 = không giới hạn)</label><input id="coupon-bxgy-repeats" className="search-input" inputMode="numeric" value={form.advanced_rules.bxgy.max_repeats} onChange={(event) => updateBxgy({ max_repeats: Number(event.target.value) || 0 })} /></div>
          <label className="coupon-check"><input type="checkbox" checked={form.advanced_rules.auto_apply_enabled} onChange={(event) => setRules({ auto_apply_enabled: event.target.checked })} /> Tự động áp dụng</label><label htmlFor="coupon-auto-category">ID danh mục auto-apply (0 = mọi danh mục)</label><input id="coupon-auto-category" className="search-input" inputMode="numeric" value={form.advanced_rules.auto_apply_category} onChange={(event) => setRules({ auto_apply_category: Number(event.target.value) || 0 })} />
        </div>}
        {selected?.status === "publish" && <p className="alert-row">Coupon này đã xuất bản. Mọi thay đổi và lưu lại sẽ áp dụng ngay trên cửa hàng.</p>}
        <button className="action-button" type="submit" disabled={busy}>{form.status === "publish" ? "Rà soát và xuất bản" : "Lưu bản nháp"}</button>
      </form>
    </section>}
  </>;
}

function RuleTarget({ title, targetType, targetId, onType, onId }: { title: string; targetType: "product" | "category"; targetId: number; onType: (value: "product" | "category") => void; onId: (value: number) => void }) {
  const id = title.includes("được tặng") ? "coupon-reward-target" : "coupon-trigger-target";
  return <><label htmlFor={`${id}-type`}>{title}</label><div style={{ display: "flex", gap: 8 }}><select id={`${id}-type`} className="search-input" value={targetType} onChange={(event) => onType(event.target.value as "product" | "category")}><option value="product">Sản phẩm</option><option value="category">Danh mục</option></select><input id={id} className="search-input" inputMode="numeric" aria-label={`${title}: mã sản phẩm hoặc danh mục`} value={targetId || ""} onChange={(event) => onId(Number(event.target.value) || 0)} /></div></>;
}
function parseIds(value: string) { return value.split(/[\s,]+/).map((part) => Number(part.trim())).filter((id) => Number.isInteger(id) && id > 0); }
function discountLabel(type: string) { return type === "percent" ? "Phần trăm" : type === "fixed_product" ? "Theo sản phẩm" : "Theo đơn"; }
