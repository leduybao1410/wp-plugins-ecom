"use client";

import { apiFetch } from "@/lib/api";

import { DatePicker } from "./date-picker";

import { useCallback, useEffect, useState } from "react";
import { useSearchParams } from "@/lib/router";
import { normalizeCouponRedemptionFilters } from "@/lib/business-workflows.js";

type Redemption = {
  id: number; coupon_code: string; generated_from: number | null; order_id: number; order_number: string | null;
  discount_amount: string; order_total: string | null; billing_email: string | null; billing_phone: string | null;
  status: "active" | "removed"; redeemed_at: string;
};
type Result = { items?: Redemption[]; total?: number; page?: number; per_page?: number };
type Filters = { from: string; to: string; status: "all" | "active" | "removed"; search: string; email: string; generated_from: string; sort: string; order: "asc" | "desc"; page: number };
const pageSize = 20;

export function CouponRedemptions() {
  const params = useSearchParams();
  const [filters, setFilters] = useState<Filters>({
    from: params.get("from") ?? "", to: params.get("to") ?? "", status: (params.get("status") as Filters["status"]) ?? "all",
    search: params.get("search") ?? "", email: params.get("email") ?? "", generated_from: params.get("generated_from") ?? "",
    sort: params.get("sort") ?? "redeemed_at", order: params.get("order") === "asc" ? "asc" : "desc", page: Number(params.get("page") ?? "1") || 1,
  });
  const [draft, setDraft] = useState(filters);
  const [data, setData] = useState<Result | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setBusy(true); setError("");
    try {
      const query = new URLSearchParams({ page: String(filters.page), per_page: String(pageSize), sort: filters.sort, order: filters.order, status: filters.status });
      if (filters.from) query.set("from", filters.from);
      if (filters.to) query.set("to", filters.to);
      if (filters.search.trim()) query.set("search", filters.search.trim());
      if (filters.email.trim()) query.set("email", filters.email.trim());
      if (filters.generated_from) query.set("generated_from", filters.generated_from);
      window.history.replaceState(null, "", `${window.location.pathname}?${query}`);
      const response = await apiFetch(`/api/dashboard/coupons/redemptions?${query}`);
      const payload: Result & { error?: string } = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không tải được báo cáo đổi mã");
      setData(payload);
    } catch (e) { setError(e instanceof Error ? e.message : "Không tải được báo cáo đổi mã"); }
    finally { setBusy(false); }
  }, [filters]);
  useEffect(() => { const timer = window.setTimeout(() => { void load(); }, 0); return () => window.clearTimeout(timer); }, [load]);

  function apply(event: React.FormEvent) {
    event.preventDefault(); setError("");
    try { normalizeCouponRedemptionFilters({ from: draft.from, to: draft.to, status: draft.status, page: 1 }); }
    catch (e) { setError(e instanceof Error ? e.message : "Bộ lọc không hợp lệ"); return; }
    if (draft.generated_from && (!/^\d+$/.test(draft.generated_from) || Number(draft.generated_from) < 1)) { setError("Mã mẫu phải là số nguyên dương."); return; }
    setFilters({ ...draft, page: 1 });
  }

  function sortBy(sort: string) {
    const order = filters.sort === sort && filters.order === "asc" ? "desc" : "asc";
    setFilters({ ...filters, sort, order, page: 1 }); setDraft({ ...draft, sort, order, page: 1 });
  }

  function movePage(page: number) { setFilters({ ...filters, page }); setDraft({ ...draft, page }); }

  return <>
    <div className="page-heading"><div><div className="eyebrow">EPIC / Tiếp thị</div><h1>Lịch sử đổi mã giảm giá</h1><p>Bản ghi lấy từ plugin EPIC Advanced Coupons. Coupon chỉ xuất hiện sau khi đã được gắn vào đơn WooCommerce.</p></div></div>
    {error && <div className="error-box" role="alert">{error}</div>}
    <form className="card toolbar finance-filters" onSubmit={apply}>
      <label htmlFor="redemption-from">Từ ngày</label><DatePicker id="redemption-from" label="Từ ngày" value={draft.from} onChange={(value) => setDraft({ ...draft, from: value })} />
      <label htmlFor="redemption-to">Đến ngày</label><DatePicker id="redemption-to" label="Đến ngày" value={draft.to} onChange={(value) => setDraft({ ...draft, to: value })} />
      <label htmlFor="redemption-status">Trạng thái</label><select id="redemption-status" className="search-input" value={draft.status} onChange={(event) => setDraft({ ...draft, status: event.target.value as Filters["status"] })}><option value="all">Tất cả</option><option value="active">Đang áp dụng</option><option value="removed">Đã gỡ khỏi đơn</option></select>
      <label htmlFor="redemption-search">Mã giảm giá</label><input id="redemption-search" className="search-input" value={draft.search} onChange={(event) => setDraft({ ...draft, search: event.target.value })} placeholder="Tìm mã coupon" />
      <label htmlFor="redemption-email">Email người mua</label><input id="redemption-email" type="email" className="search-input" value={draft.email} onChange={(event) => setDraft({ ...draft, email: event.target.value })} placeholder="Tất cả email" />
      <label htmlFor="redemption-batch">Mã coupon mẫu</label><input id="redemption-batch" inputMode="numeric" className="search-input" value={draft.generated_from} onChange={(event) => setDraft({ ...draft, generated_from: event.target.value })} placeholder="Mọi mã" />
      <button className="quiet-button" type="submit" disabled={busy}>Lọc báo cáo</button>
    </form>
    <section className="card"><div className="toolbar"><strong>Lượt đổi mã</strong><span className="muted" aria-live="polite">{busy ? "Đang tải…" : `${(data?.total ?? 0).toLocaleString("vi-VN")} lượt`}</span></div>
      <div className="table-wrap"><table><thead><tr><th>Mã coupon</th><th>Mã mẫu</th><th>Mã đơn</th><th>Người mua</th><th>Giảm giá</th><th>Tổng đơn</th><th>Trạng thái</th><th><button className="table-sort" type="button" onClick={() => sortBy("redeemed_at")}>Thời điểm{filters.sort === "redeemed_at" ? filters.order === "asc" ? " ↑" : " ↓" : ""}</button></th></tr></thead><tbody>
        {data?.items?.map((row) => <tr key={row.id}><td>{row.coupon_code}</td><td>{row.generated_from ? `#${row.generated_from}` : "—"}</td><td>#{row.order_number || row.order_id}</td><td>{[row.billing_email, row.billing_phone].filter(Boolean).join(" · ") || "—"}</td><td>{money(row.discount_amount)}</td><td>{row.order_total == null ? "—" : money(row.order_total)}</td><td><span className="status" data-status={row.status === "active" ? "approved" : "draft"}>{row.status === "active" ? "Đang áp dụng" : "Đã gỡ"}</span></td><td>{new Intl.DateTimeFormat("vi-VN", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Ho_Chi_Minh" }).format(new Date(`${row.redeemed_at.replace(" ", "T")}Z`))}</td></tr>)}
        {!busy && !data?.items?.length && <tr><td colSpan={8} className="empty">Chưa có lượt đổi mã khớp bộ lọc. Báo cáo chỉ ghi nhận coupon trong đơn WooCommerce đã lưu.</td></tr>}
      </tbody></table></div>
      <div className="toolbar"><span className="muted">Trang {filters.page}</span><div style={{ display: "flex", gap: 8 }}><button type="button" className="quiet-button" disabled={busy || filters.page <= 1} onClick={() => movePage(filters.page - 1)}>Trước</button><button type="button" className="quiet-button" disabled={busy || (data?.total != null && filters.page * pageSize >= data.total)} onClick={() => movePage(filters.page + 1)}>Tiếp</button></div></div>
    </section>
  </>;
}

function money(value: string | number) { return `${Number(value).toLocaleString("vi-VN")} ₫`; }
