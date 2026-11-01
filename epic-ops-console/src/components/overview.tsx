"use client";

import { apiFetch } from "@/lib/api";

import { DatePicker } from "./date-picker";

import { useCallback, useEffect, useState } from "react";
import type { FormEvent } from "react";
import Link from "@/lib/router";
import { useSearchParams } from "@/lib/router";
import { localToday, normalizeOverviewRange, overviewPresetRange } from "@/lib/overview-query.js";
import { ArrowRight, Boxes, CircleAlert, ClipboardCheck, PackageCheck, Star } from "lucide-react";

type Summary = {
  from: string; to: string; orders: number | null; revenue: number | null;
  fulfillment_backlog: number | null; low_stock: number | null;
  pending_leads: number | null; pending_reviews: number | null; currency: string | null;
};

export function Overview() {
  const searchParams = useSearchParams();
  const today = localToday();
  const [from, setFrom] = useState(searchParams.get("from") ?? today);
  const [to, setTo] = useState(searchParams.get("to") ?? today);
  const [appliedRange, setAppliedRange] = useState({ from: searchParams.get("from") ?? today, to: searchParams.get("to") ?? today });
  const [summary, setSummary] = useState<Summary | null>(null);
  const [health, setHealth] = useState<{ wordpress?: boolean; woocommerce?: boolean; plugins?: Record<string, boolean> } | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(true);
  const [period, setPeriod] = useState(() => searchParams.has("from") || searchParams.has("to") ? "custom" : "today");

  const load = useCallback(async (rangeFrom: string, rangeTo: string) => {
    setBusy(true); setError("");
    try {
      const range = normalizeOverviewRange({ from: rangeFrom, to: rangeTo });
      const query = new URLSearchParams(range);
      window.history.replaceState(null, "", `${window.location.pathname}?${query}`);
      const [statsResponse, healthResponse] = await Promise.all([
        apiFetch(`/api/dashboard/overview?${query}`), apiFetch("/api/dashboard/health"),
      ]);
      const [stats, integrations] = await Promise.all([statsResponse.json(), healthResponse.json()]);
      if (!statsResponse.ok || !healthResponse.ok || stats.error || integrations.error) throw new Error(stats.error || integrations.error || "Không tải được dữ liệu");
      setSummary(stats); setHealth(integrations);
    } catch (e) { setError(e instanceof Error ? e.message : "Không tải được dữ liệu"); }
    finally { setBusy(false); }
  }, []);

  useEffect(() => { const timer = window.setTimeout(() => { void load(appliedRange.from, appliedRange.to); }, 0); return () => window.clearTimeout(timer); }, [load, appliedRange]);

  function applyRange(event: FormEvent) {
    event.preventDefault(); setError("");
    try { setAppliedRange(normalizeOverviewRange({ from, to })); }
    catch (e) { setError(e instanceof Error ? e.message : "Khoảng thời gian không hợp lệ"); }
  }

  function choosePeriod(value: string) {
    setPeriod(value);
    if (value !== "custom") {
      try { const range = overviewPresetRange(value, today); setFrom(range.from); setTo(range.to); setAppliedRange(range); }
      catch (e) { setError(e instanceof Error ? e.message : "Khoảng thời gian không hợp lệ"); }
    }
  }

  return <>
    <div className="page-heading"><div><div className="eyebrow">EPIC · Trung tâm vận hành</div><h1>Chào buổi sáng</h1><p>Theo dõi hoạt động kinh doanh và xử lý những việc cần ưu tiên.</p></div><span className="date-stamp">{new Intl.DateTimeFormat("vi-VN", { dateStyle: "full", timeZone: "Asia/Ho_Chi_Minh" }).format(new Date())}</span></div>
    <section className="overview-period card" aria-label="Chọn kỳ báo cáo">
      <div className="period-tabs" role="group" aria-label="Khoảng thời gian báo cáo">{[["today", "Hôm nay"], ["last7", "7 ngày qua"], ["month", "Tháng này"], ["custom", "Tùy chọn"]].map(([key, label]) => <button key={key} type="button" className={`period-tab${period === key ? " is-active" : ""}`} aria-pressed={period === key} onClick={() => choosePeriod(key)}>{label}</button>)}</div>
      {period === "custom" && <form className="period-custom" onSubmit={applyRange} aria-label="Lọc khoảng thời gian tùy chọn"><label htmlFor="overview-from">Từ ngày</label><DatePicker id="overview-from" label="Từ ngày" value={from} max={to} onChange={setFrom} /><label htmlFor="overview-to">Đến ngày</label><DatePicker id="overview-to" label="Đến ngày" value={to} min={from} onChange={setTo} /><button className="quiet-button" type="submit" disabled={busy}>Cập nhật</button></form>}
      <span className="period-range" aria-live="polite">{busy ? "Đang cập nhật…" : `${from} — ${to}`}</span>
    </section>
    {error && <div className="error-box" role="alert">{error}</div>}
    <section className="overview-performance" aria-label="Kết quả kinh doanh" aria-busy={busy}><div className="section-heading"><div><div className="eyebrow">Kết quả kinh doanh</div><h2>Hiệu suất bán lẻ</h2></div><span className="muted">Theo kỳ đã chọn</span></div><div className="performance-grid">
      <Stat label="Doanh thu đã ghi nhận" value={money(summary?.revenue, summary?.currency)} hint="Đơn đã thanh toán hoặc đang xử lý" />
      <Stat label="Đơn hàng" value={count(summary?.orders)} hint="Tất cả trạng thái trong kỳ" />
    </div></section>
    <section className="overview-work" aria-label="Công việc ưu tiên"><div className="section-heading"><div><div className="eyebrow">Việc cần quan tâm</div><h2>Hàng chờ vận hành</h2></div><span className="muted">Tình trạng hiện tại</span></div><div className="work-grid">
      <WorkStat icon={PackageCheck} label="Đơn cần hoàn tất" value={count(summary?.fulfillment_backlog)} detail="Đơn đang xử lý hoặc tạm giữ" href="/orders?status=processing" tone="orange" />
      <WorkStat icon={Boxes} label="Sản phẩm sắp hết" value={count(summary?.low_stock)} detail="Theo ngưỡng tồn kho WooCommerce" href="/products" tone="gold" />
      <WorkStat icon={ClipboardCheck} label="Email tiếp nhận đang chờ" value={count(summary?.pending_leads)} detail="Thông báo yêu cầu chưa gửi" href="/leads?status=pending" tone="blue" />
      <WorkStat icon={Star} label="Đánh giá chờ duyệt" value={count(summary?.pending_reviews)} detail="Cần kiểm tra nội dung" href="/reviews?status=pending" tone="green" />
    </div></section>
    {health && (health.woocommerce === false || Object.values(health.plugins ?? {}).some((value) => !value)) && <div className="warning-box integration-warning" role="status"><CircleAlert size={18} aria-hidden="true" /><span>Một số kết nối cần kiểm tra.</span><Link href="/settings">Xem tình trạng hệ thống <ArrowRight size={14} /></Link></div>}
    <section className="summary-grid overview-lower">
      <div className="card section-card"><div className="section-heading"><div><div className="eyebrow">Bắt đầu xử lý</div><h2>Lối tắt công việc</h2></div></div>
        <QuickLink href="/orders" title="Rà soát đơn mới" detail="Đối soát thanh toán và thông tin giao hàng" />
        <QuickLink href="/shipments" title="Chuẩn bị vận chuyển" detail="Kiểm tra đơn trước khi tạo vận đơn" />
        <QuickLink href="/leads" title="Theo dõi khách hàng tiềm năng" detail="Yêu cầu liên hệ, dùng thử và bán sỉ" />
      </div>
      <div className="card section-card"><div className="section-heading"><div><div className="eyebrow">Trạng thái kết nối</div><h2>Tích hợp</h2></div><Link href="/settings" className="text-link">Chi tiết <ArrowRight size={14} /></Link></div>
        <Health label="WordPress" value={health?.wordpress} />
        <Health label="WooCommerce" value={health?.woocommerce} />
        {health?.plugins && Object.entries(health.plugins).map(([key, value]) => <Health key={key} label={key.replace(/^epic-/, "").replaceAll("-", " ")} value={value} />)}
        {!health && <p>Đang kiểm tra kết nối…</p>}
      </div>
    </section>
    <p className="muted data-note">Số liệu không khả dụng hiển thị “—”. Doanh thu chỉ cộng đơn bán lẻ đã thanh toán.</p>
  </>;
}

function count(value: number | null | undefined) { return value == null ? "—" : value.toLocaleString("vi-VN"); }
function money(value: number | null | undefined, currency: string | null | undefined) { return value == null || !currency ? "—" : new Intl.NumberFormat("vi-VN", { style: "currency", currency }).format(value); }
function Stat({ label, value, hint, href }: { label: string; value: string; hint: string; href?: string }) {
  const content = <><span>{label}</span><strong>{value}</strong><small>{hint}</small></>;
  return <div className="card stat-card">{href ? <Link href={href} style={{ display: "grid", gap: 8, height: "100%", textDecoration: "none", color: "inherit" }}>{content}</Link> : content}</div>;
}
function WorkStat({ icon: Icon, label, value, detail, href, tone }: { icon: typeof PackageCheck; label: string; value: string; detail: string; href: string; tone: string }) { return <Link className={`work-card tone-${tone}`} href={href}><span className="work-icon"><Icon size={18} aria-hidden="true" /></span><span className="work-copy"><strong>{label}</strong><small>{detail}</small></span><b>{value}</b><ArrowRight className="work-arrow" size={16} aria-hidden="true" /></Link>; }
function QuickLink({ href, title, detail }: { href: string; title: string; detail: string }) { return <Link href={href} className="alert-row quick-link" style={{ textDecoration: "none", color: "inherit" }}><span><strong>{title}</strong><small style={{ display: "block", color: "var(--muted)", marginTop: 4 }}>{detail}</small></span><ArrowRight size={16} aria-hidden="true" /></Link>; }
function Health({ label, value }: { label: string; value: boolean | undefined }) { return <div className="alert-row"><span>{label}</span><span className="status" data-status={value ? "approved" : value === undefined ? "pending" : "failed"}>{value === undefined ? "Chưa kiểm tra" : value ? "Đang hoạt động" : "Chưa khả dụng"}</span></div>; }
