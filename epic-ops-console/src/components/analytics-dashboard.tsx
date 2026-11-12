"use client";

import { useCallback, useEffect, useState } from "react";
import type { FormEvent, ReactNode } from "react";
import { ArrowDownRight, ArrowUpRight, ExternalLink, RefreshCw, TriangleAlert } from "lucide-react";
import { DatePicker } from "./date-picker";
import { apiFetch } from "@/lib/api";
import { mapAnalyticsView } from "@/lib/posthog-analytics.js";
import { localToday, normalizeOverviewRange, overviewPresetRange } from "@/lib/overview-query.js";

const POSTHOG_DASHBOARD_URL = "https://us.posthog.com/project/715536/dashboard/2313977";

type ViewId = "overview" | "campaigns" | "funnel" | "checkout" | "content" | "quality" | "reconcile";
type Preset = "today" | "last7" | "month" | "custom";

type Summary = {
  pageviews: number; visitors: number; sessions: number; orders: number; revenue: number;
  checkouts: number; errors: number; cvr: number | null; aov: number | null; error_rate: number | null;
};
type OverviewData = {
  daily: Array<{ day: string; pageviews: number; visitors: number; sessions: number; orders: number; revenue: number; checkouts: number; errors: number }>;
  totals: Summary;
  previous: Summary | null;
  deltas: Partial<Record<keyof Summary, number | null>>;
};
type CampaignsData = {
  rows: Array<{ promotion_id: string; slot: string; impressions: number; clicks: number; dismissals: number; orders: number; revenue: number; ctr: number | null; dismiss_rate: number | null; rpm: number | null }>;
  totals: { impressions: number; clicks: number; dismissals: number; orders: number; revenue: number; ctr: number | null; dismiss_rate: number | null; rpm: number | null };
};
type FunnelData = {
  steps: Array<{ event: string; label: string; count: number; conversion: number | null; dropoff: number | null }>;
  first: number; last: number; overall: number | null;
};
type CheckoutData = {
  errors: Array<{ error_code: string; field_group: string; count: number }>;
  errors_total: number; checkouts: number; error_rate: number | null;
  latency_p75: number | null; latency_samples: number;
};
type ContentData = {
  pages: Array<{ path: string; views: number; visitors: number }>;
  sources: Array<{ source: string; campaign: string; pageviews: number; visitors: number }>;
  products: Array<{ item_id: string; item_name: string; add_to_carts: number; purchases: number; revenue: number }>;
  products_available: boolean;
};
type QualityData = {
  daily: Array<{ day: string; lcp_p75: number | null; inp_p75: number | null; cls_p75: number | null; samples: number }>;
  overall: { lcp_p75: number | null; inp_p75: number | null; cls_p75: number | null; samples: number };
};
type ReconcileData = {
  daily: Array<{ day: string; orders: number; revenue: number }>;
  posthog: { revenue: number; orders: number };
  woocommerce: { revenue: number | null; orders: number | null; currency: string; available: boolean };
  difference: { revenue: number | null; revenue_pct: number | null; orders: number | null };
};
type AnalyticsPayload = {
  view: ViewId; from: string; to: string; updated_at: string; source: "posthog"; sample: true; stale?: boolean;
  data: OverviewData | CampaignsData | FunnelData | CheckoutData | ContentData | QualityData | ReconcileData;
};
type RawAnalyticsPayload = {
  view: ViewId; from: string; to: string; updated_at: string; source: "posthog"; sample: true; stale?: boolean;
  results: Record<string, unknown>;
  woocommerce?: unknown;
  error?: string;
  message?: string;
};

const tabs: Array<{ id: ViewId; label: string }> = [
  { id: "overview", label: "Tổng quan" },
  { id: "campaigns", label: "Chiến dịch" },
  { id: "funnel", label: "Phễu" },
  { id: "checkout", label: "Checkout" },
  { id: "content", label: "Nội dung" },
  { id: "quality", label: "Chất lượng" },
  { id: "reconcile", label: "Đối soát" },
];

export function AnalyticsDashboard() {
  const today = localToday();
  const initial = overviewPresetRange("last7", today);
  const [view, setView] = useState<ViewId>("overview");
  const [period, setPeriod] = useState<Preset>("last7");
  const [from, setFrom] = useState(initial.from);
  const [to, setTo] = useState(initial.to);
  const [applied, setApplied] = useState(initial);
  const [payload, setPayload] = useState<AnalyticsPayload | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(true);
  const [reloadKey, setReloadKey] = useState(0);

  const load = useCallback(async (signal: AbortSignal) => {
    setBusy(true);
    setError("");
    const query = new URLSearchParams({ view, from: applied.from, to: applied.to });
    try {
      const response = await apiFetch(`/api/dashboard/analytics?${query}`, { signal });
      const body = (await response.json()) as RawAnalyticsPayload;
      if (body.error || body.message) throw new Error(body.error || body.message);
      if (!signal.aborted) {
        const data = mapAnalyticsView(body.view, body.results ?? {}, {
          woocommerce: body.woocommerce,
          productsAvailable: body.results?.products != null,
        });
        setPayload({ ...body, data } as AnalyticsPayload);
      }
    } catch (cause) {
      if (signal.aborted) return;
      if (cause instanceof DOMException && cause.name === "AbortError") return;
      setError(cause instanceof Error ? cause.message : "Không tải được dữ liệu phân tích");
    } finally {
      if (!signal.aborted) setBusy(false);
    }
  }, [view, applied]);

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => { void load(controller.signal); }, 0);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [load, reloadKey]);

  function choosePeriod(value: Preset) {
    setPeriod(value);
    if (value === "custom") return;
    try {
      const range = overviewPresetRange(value, today);
      setFrom(range.from);
      setTo(range.to);
      setApplied(range);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Khoảng thời gian không hợp lệ");
    }
  }

  function applyRange(event: FormEvent) {
    event.preventDefault();
    try {
      setApplied(normalizeOverviewRange({ from, to }));
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Khoảng thời gian không hợp lệ");
    }
  }

  const data = payload && payload.view === view ? payload : null;
  const updatedAt = data?.updated_at
    ? new Intl.DateTimeFormat("vi-VN", { timeStyle: "short", timeZone: "Asia/Ho_Chi_Minh" }).format(new Date(data.updated_at))
    : "";

  return <>
    <div className="page-heading">
      <div>
        <div className="eyebrow">EPIC · Phân tích</div>
        <h1>Phân tích</h1>
        <p>Lưu lượng, chiến dịch và phễu mua hàng từ dữ liệu PostHog của storefront.</p>
      </div>
      <span className="date-stamp">{busy && !data ? "Đang tải…" : updatedAt ? `Cập nhật lúc ${updatedAt}` : ""}</span>
    </div>

    <section className="overview-period card" aria-label="Chọn kỳ phân tích">
      <div className="period-tabs" role="group" aria-label="Khoảng thời gian phân tích">
        {([["today", "Hôm nay"], ["last7", "7 ngày qua"], ["month", "Tháng này"], ["custom", "Tùy chọn"]] as Array<[Preset, string]>).map(([key, label]) => (
          <button key={key} type="button" className={`period-tab${period === key ? " is-active" : ""}`} aria-pressed={period === key} onClick={() => choosePeriod(key)}>{label}</button>
        ))}
      </div>
      {period === "custom" && <form className="period-custom" onSubmit={applyRange} aria-label="Lọc khoảng thời gian tùy chọn">
        <label htmlFor="analytics-from">Từ ngày</label><DatePicker id="analytics-from" label="Từ ngày" value={from} max={to} onChange={setFrom} />
        <label htmlFor="analytics-to">Đến ngày</label><DatePicker id="analytics-to" label="Đến ngày" value={to} min={from} onChange={setTo} />
        <button className="quiet-button" type="submit" disabled={busy}>Cập nhật</button>
      </form>}
      <div className="analytics-period-actions">
        <span className="period-range" aria-live="polite">{applied.from} — {applied.to}</span>
        <button className="quiet-button analytics-refresh" type="button" onClick={() => setReloadKey((value) => value + 1)} disabled={busy}>
          <RefreshCw size={14} aria-hidden="true" />{busy ? "Đang tải…" : "Làm mới"}
        </button>
      </div>
    </section>

    <div className="warning-box analytics-note" role="note">
      <TriangleAlert size={17} aria-hidden="true" />
      <span>Số liệu chỉ từ khách đã đồng ý phân tích, có độ trễ 2–3 phút và là <strong>mẫu</strong> — không phải toàn bộ traffic.</span>
    </div>

    {data?.stale && <div className="warning-box analytics-note" role="status"><TriangleAlert size={17} aria-hidden="true" /><span>PostHog chưa phản hồi kịp. Đang hiển thị số liệu cũ đã lưu lúc {updatedAt}.</span></div>}
    {error && <div className="error-box" role="alert">{error} <button className="text-link" type="button" onClick={() => setReloadKey((value) => value + 1)}>Thử lại</button></div>}

    <div className="analytics-tabs" role="tablist" aria-label="Nhóm phân tích">
      {tabs.map((tab) => <button key={tab.id} type="button" role="tab" id={`analytics-tab-${tab.id}`} aria-selected={view === tab.id} aria-controls="analytics-panel" className={`analytics-tab${view === tab.id ? " is-active" : ""}`} onClick={() => setView(tab.id)}>{tab.label}</button>)}
    </div>

    <div id="analytics-panel" role="tabpanel" aria-labelledby={`analytics-tab-${view}`} aria-busy={busy}>
      {!data && busy && <AnalyticsSkeleton />}
      {!data && !busy && !error && <p className="empty">Chưa có dữ liệu trong khoảng này.</p>}
      {data && view === "overview" && <OverviewPanel data={data.data as OverviewData} />}
      {data && view === "campaigns" && <CampaignsPanel data={data.data as CampaignsData} />}
      {data && view === "funnel" && <FunnelPanel data={data.data as FunnelData} />}
      {data && view === "checkout" && <CheckoutPanel data={data.data as CheckoutData} />}
      {data && view === "content" && <ContentPanel data={data.data as ContentData} />}
      {data && view === "quality" && <QualityPanel data={data.data as QualityData} />}
      {data && view === "reconcile" && <ReconcilePanel data={data.data as ReconcileData} />}
    </div>

    <div className="analytics-footer">
      <p className="muted data-note">Doanh thu web chỉ tính event <code>purchase</code> có <code>value</code>. Đơn gắn <code>promotion_id</code> chỉ khi khách bấm CTA, không tính view-through. Chênh lệch với WooCommerce là bình thường do consent, adblock và đơn ngoài web.</p>
      <a className="text-link" href={POSTHOG_DASHBOARD_URL} target="_blank" rel="noreferrer">Mở trong PostHog <ExternalLink size={14} aria-hidden="true" /></a>
    </div>
  </>;
}

function OverviewPanel({ data }: { data: OverviewData }) {
  const { totals, deltas, daily } = data;
  return <>
    <section className="analytics-kpis" aria-label="Chỉ số chính">
      <Kpi label="Khách" value={num(totals.visitors)} delta={deltas.visitors} hint="Người dùng duy nhất xem trang" />
      <Kpi label="Pageview" value={num(totals.pageviews)} delta={deltas.pageviews} hint="Tổng lượt xem trang" />
      <Kpi label="Phiên" value={num(totals.sessions)} delta={deltas.sessions} hint="Phiên có pageview" />
      <Kpi label="Đơn web" value={num(totals.orders)} delta={deltas.orders} hint="Event purchase" />
      <Kpi label="Doanh thu web" value={vnd(totals.revenue)} delta={deltas.revenue} hint="Tổng value của purchase" />
      <Kpi label="AOV" value={vnd(totals.aov)} delta={deltas.aov} hint="Doanh thu / đơn" />
      <Kpi label="CVR" value={pct(totals.cvr)} delta={deltas.cvr} hint="Đơn / khách" />
      <Kpi label="Tỉ lệ lỗi checkout" value={pct(totals.error_rate)} delta={deltas.error_rate} inverse hint="Lỗi / bắt đầu thanh toán" />
    </section>
    {daily.length === 0
      ? <p className="empty state-card">Chưa có dữ liệu trong khoảng này — PostHog chỉ nhận event sau khi khách đồng ý phân tích.</p>
      : <>
        <section className="analytics-charts" aria-label="Xu hướng theo ngày">
          <ChartCard title="Doanh thu theo ngày" total={vnd(totals.revenue)} values={daily.map((row) => row.revenue)} />
          <ChartCard title="Pageview theo ngày" total={num(totals.pageviews)} values={daily.map((row) => row.pageviews)} />
        </section>
        <section className="card analytics-table">
          <div className="analytics-table-head"><h2>Chi tiết theo ngày</h2><span className="muted">{daily.length} ngày có dữ liệu</span></div>
          <div className="table-wrap">
            <table>
              <thead><tr><th>Ngày</th><th>Pageview</th><th>Khách</th><th>Đơn</th><th>Doanh thu</th></tr></thead>
              <tbody>{daily.map((row) => <tr key={row.day}><td>{formatDay(row.day)}</td><td>{num(row.pageviews)}</td><td>{num(row.visitors)}</td><td>{num(row.orders)}</td><td>{vnd(row.revenue)}</td></tr>)}</tbody>
            </table>
          </div>
        </section>
      </>}
  </>;
}

function CampaignsPanel({ data }: { data: CampaignsData }) {
  if (!data.rows.length) return <p className="empty state-card">Chưa có impression hay doanh thu gắn chiến dịch trong khoảng này.</p>;
  return <>
    <section className="analytics-kpis analytics-kpis-4" aria-label="Tổng hợp chiến dịch">
      <Kpi label="Impressions" value={num(data.totals.impressions)} hint="view_promotion đã dedupe theo phiên" />
      <Kpi label="CTR" value={pct(data.totals.ctr)} hint="Click / impression" />
      <Kpi label="Doanh thu gắn chiến dịch" value={vnd(data.totals.revenue)} hint={`${num(data.totals.orders)} đơn`} />
      <Kpi label="RPM" value={vnd(data.totals.rpm)} hint="Doanh thu / 1.000 impressions" />
    </section>
    <section className="card analytics-table">
      <div className="analytics-table-head"><h2>Hiệu quả theo chiến dịch</h2><span className="muted">Chỉ đơn bấm CTA mới được gắn mã</span></div>
      <div className="table-wrap">
        <table>
          <thead><tr><th>promotion_id</th><th>Slot</th><th>Impressions</th><th>Clicks</th><th>CTR</th><th>Dismiss</th><th>Đơn</th><th>Doanh thu</th><th>RPM</th></tr></thead>
          <tbody>{data.rows.map((row, index) => <tr key={`${row.promotion_id}-${row.slot}-${index}`}>
            <td><strong>{row.promotion_id}</strong></td>
            <td><span className="status">{row.slot}</span></td>
            <td>{num(row.impressions)}</td>
            <td>{num(row.clicks)}</td>
            <td>{pct(row.ctr)}</td>
            <td>{pct(row.dismiss_rate)}</td>
            <td>{num(row.orders)}</td>
            <td>{vnd(row.revenue)}</td>
            <td>{vnd(row.rpm)}</td>
          </tr>)}</tbody>
        </table>
      </div>
    </section>
  </>;
}

function FunnelPanel({ data }: { data: FunnelData }) {
  if (!data.first) return <p className="empty state-card">Chưa đủ event để dựng phễu trong khoảng này.</p>;
  return <section className="card analytics-funnel">
    <div className="analytics-table-head"><h2>Phễu mua hàng</h2><span className="muted">Chuyển đổi tổng: <strong>{pct(data.overall)}</strong></span></div>
    <div className="funnel-list">
      {data.steps.map((step, index) => {
        const width = data.first > 0 ? Math.max((step.count / data.first) * 100, step.count > 0 ? 2 : 0) : 0;
        return <div className="funnel-step" key={step.event}>
          <div className="funnel-step-top"><span><b>{index + 1}</b>{step.label}</span><strong>{num(step.count)}</strong></div>
          <div className="funnel-bar" aria-hidden="true"><span style={{ width: `${width}%` }} /></div>
          <div className="funnel-step-meta">
            <span>{pct(step.conversion)} so với bước đầu</span>
            {step.dropoff !== null && <span className="funnel-drop">Rơi {pct(step.dropoff)}</span>}
          </div>
        </div>;
      })}
    </div>
    <p className="muted data-note">Cửa sổ chuyển đổi mặc định 14 ngày, thứ tự có bước trung gian cho phép.</p>
  </section>;
}

function CheckoutPanel({ data }: { data: CheckoutData }) {
  return <>
    <section className="analytics-kpis analytics-kpis-4" aria-label="Sức khoẻ checkout">
      <Kpi label="Bắt đầu thanh toán" value={num(data.checkouts)} hint="Event begin_checkout" />
      <Kpi label="Lỗi checkout" value={num(data.errors_total)} hint="Event checkout_error" />
      <Kpi label="Tỉ lệ lỗi" value={pct(data.error_rate)} inverse hint="Lỗi / bắt đầu thanh toán" />
      <Kpi label="Độ trễ p75" value={ms(data.latency_p75)} hint={`${num(data.latency_samples)} mẫu checkout_latency`} />
    </section>
    {data.errors.length === 0
      ? <p className="empty state-card">Không ghi nhận lỗi checkout trong khoảng này.</p>
      : <section className="card analytics-table">
        <div className="analytics-table-head"><h2>Lỗi theo mã</h2><span className="muted">Sắp xếp theo số lần</span></div>
        <div className="table-wrap"><table>
          <thead><tr><th>error_code</th><th>Nhóm trường</th><th>Số lần</th><th>Tỉ trọng</th></tr></thead>
          <tbody>{data.errors.map((row, index) => <tr key={`${row.error_code}-${row.field_group}-${index}`}>
            <td><strong>{row.error_code}</strong></td><td>{row.field_group}</td><td>{num(row.count)}</td>
            <td>{pct(data.errors_total ? row.count / data.errors_total : null)}</td>
          </tr>)}</tbody>
        </table></div>
      </section>}
  </>;
}

function ContentPanel({ data }: { data: ContentData }) {
  return <div className="analytics-content-grid">
    <TableCard title="Trang xem nhiều" empty="Chưa có pageview." columns={3} head={<><th>Đường dẫn</th><th>Lượt xem</th><th>Khách</th></>}>
      {data.pages.map((row) => <tr key={row.path}><td className="analytics-path">{row.path}</td><td>{num(row.views)}</td><td>{num(row.visitors)}</td></tr>)}
    </TableCard>
    <TableCard title="Nguồn UTM" empty="Chưa có nguồn UTM." columns={3} head={<><th>Nguồn</th><th>Chiến dịch</th><th>Khách</th></>}>
      {data.sources.map((row, index) => <tr key={`${row.source}-${row.campaign}-${index}`}><td>{row.source}</td><td>{row.campaign || "—"}</td><td>{num(row.visitors)}</td></tr>)}
    </TableCard>
    <TableCard title="Sản phẩm được thêm vào giỏ" empty={data.products_available ? "Chưa có add_to_cart." : "Chưa lấy được item-level; cần thêm item_id phía storefront."} columns={4} head={<><th>Sản phẩm</th><th>Thêm giỏ</th><th>Đơn</th><th>Doanh thu</th></>}>
      {data.products.map((row, index) => <tr key={`${row.item_id}-${row.item_name}-${index}`}><td>{row.item_name || row.item_id || "—"}<small className="analytics-sub">{row.item_id}</small></td><td>{num(row.add_to_carts)}</td><td>{num(row.purchases)}</td><td>{vnd(row.revenue)}</td></tr>)}
    </TableCard>
  </div>;
}

function QualityPanel({ data }: { data: QualityData }) {
  return <>
    <section className="analytics-kpis analytics-kpis-3" aria-label="Web vitals p75">
      <VitalKpi label="LCP p75" value={ms(data.overall.lcp_p75)} good={data.overall.lcp_p75 !== null && data.overall.lcp_p75 <= 2500} hint="Tốt ≤ 2,5 s" />
      <VitalKpi label="INP p75" value={ms(data.overall.inp_p75)} good={data.overall.inp_p75 !== null && data.overall.inp_p75 <= 200} hint="Tốt ≤ 200 ms" />
      <VitalKpi label="CLS p75" value={data.overall.cls_p75 === null ? "—" : data.overall.cls_p75.toFixed(3)} good={data.overall.cls_p75 !== null && data.overall.cls_p75 <= 0.1} hint="Tốt ≤ 0,1" />
    </section>
    {data.daily.length === 0
      ? <p className="empty state-card">Chưa có dữ liệu $web_vitals trong khoảng này.</p>
      : <section className="card analytics-table">
        <div className="analytics-table-head"><h2>Web vitals theo ngày</h2><span className="muted">{num(data.overall.samples)} mẫu</span></div>
        <div className="table-wrap"><table>
          <thead><tr><th>Ngày</th><th>LCP p75</th><th>INP p75</th><th>CLS p75</th><th>Mẫu</th></tr></thead>
          <tbody>{data.daily.map((row) => <tr key={row.day}><td>{formatDay(row.day)}</td><td>{ms(row.lcp_p75)}</td><td>{ms(row.inp_p75)}</td><td>{row.cls_p75 === null ? "—" : row.cls_p75.toFixed(3)}</td><td>{num(row.samples)}</td></tr>)}</tbody>
        </table></div>
      </section>}
  </>;
}

function ReconcilePanel({ data }: { data: ReconcileData }) {
  return <>
    <section className="analytics-kpis analytics-kpis-4" aria-label="Đối soát doanh thu">
      <Kpi label="Doanh thu PostHog" value={vnd(data.posthog.revenue)} hint={`${num(data.posthog.orders)} đơn purchase`} />
      <Kpi label="Doanh thu WooCommerce" value={data.woocommerce.available ? vnd(data.woocommerce.revenue) : "—"} hint={data.woocommerce.available ? `${num(data.woocommerce.orders)} đơn trong kỳ` : "Không tải được adapter WordPress"} />
      <Kpi label="Chênh lệch" value={data.difference.revenue === null ? "—" : `${data.difference.revenue > 0 ? "+" : ""}${vnd(data.difference.revenue)}`} hint="PostHog − WooCommerce" />
      <Kpi label="Chênh lệch %" value={pct(data.difference.revenue_pct)} hint="Khác nguồn là bình thường" />
    </section>
    {data.daily.length > 0 && <section className="analytics-charts" aria-label="Doanh thu PostHog theo ngày">
      <ChartCard title="Doanh thu PostHog theo ngày" total={vnd(data.posthog.revenue)} values={data.daily.map((row) => row.revenue)} />
      <div className="card analytics-explain">
        <h2>Vì sao lệch?</h2>
        <ul>
          <li>Chỉ khách đồng ý phân tích mới được ghi nhận.</li>
          <li>Trình chặn quảng cáo có thể chặn posthog-js.</li>
          <li>Đơn ngoài web (điện thoại, cửa hàng) chỉ có trong WooCommerce.</li>
          <li>Độ trễ ingest 2–3 phút có thể làm thiếu đơn mới nhất.</li>
        </ul>
      </div>
    </section>}
  </>;
}

function Kpi({ label, value, hint, delta, inverse = false }: { label: string; value: string; hint?: string; delta?: number | null; inverse?: boolean }) {
  return <div className="card stat-card analytics-kpi">
    <span>{label}</span>
    <strong>{value}</strong>
    <div className="analytics-kpi-foot">
      {delta !== undefined && <Delta value={delta} inverse={inverse} />}
      {hint && <small>{hint}</small>}
    </div>
  </div>;
}

function VitalKpi({ label, value, good, hint }: { label: string; value: string; good: boolean; hint: string }) {
  return <div className="card stat-card analytics-kpi">
    <span>{label}</span>
    <strong>{value}</strong>
    <div className="analytics-kpi-foot"><span className={`status ${good ? "is-good" : "is-warn"}`} data-status={good ? "approved" : "pending"}>{good ? "Tốt" : "Cần cải thiện"}</span><small>{hint}</small></div>
  </div>;
}

function Delta({ value, inverse }: { value: number | null; inverse: boolean }) {
  if (value === null || value === undefined) return <small>Chưa có kỳ trước</small>;
  const positive = value >= 0;
  const good = inverse ? !positive : positive;
  const Icon = positive ? ArrowUpRight : ArrowDownRight;
  return <span className={`analytics-delta ${good ? "is-good" : "is-bad"}`} title="So với kỳ trước cùng độ dài">
    <Icon size={13} aria-hidden="true" />{positive ? "+" : ""}{(value * 100).toFixed(1)}%
  </span>;
}

function ChartCard({ title, total, values }: { title: string; total: string; values: number[] }) {
  return <div className="card analytics-chart">
    <div><span>{title}</span><strong>{total}</strong></div>
    <Sparkline values={values} label={title} />
  </div>;
}

function Sparkline({ values, label }: { values: number[]; label: string }) {
  const height = 42;
  const max = Math.max(...values, 0);
  const safe = values.length ? values : [0];
  const points = safe.map((value, index) => {
    const x = safe.length === 1 ? 0 : (index / (safe.length - 1)) * 100;
    const y = max > 0 ? height - 3 - (Math.max(value, 0) / max) * (height - 6) : height - 3;
    return `${x.toFixed(2)},${y.toFixed(2)}`;
  }).join(" ");
  return <svg className="analytics-sparkline" viewBox={`0 0 100 ${height}`} preserveAspectRatio="none" role="img" aria-label={`${label}: ${max === 0 ? "không có dữ liệu" : "xu hướng"}`}>
    <polyline points={points} fill="none" stroke="currentColor" strokeWidth="2" vectorEffect="non-scaling-stroke" strokeLinejoin="round" strokeLinecap="round" />
  </svg>;
}

function TableCard({ title, empty, columns, head, children }: { title: string; empty: string; columns: number; head: ReactNode; children: ReactNode }) {
  const rows = Array.isArray(children) ? children : [children];
  return <section className="card analytics-table">
    <div className="analytics-table-head"><h2>{title}</h2></div>
    <div className="table-wrap"><table>
      <thead><tr>{head}</tr></thead>
      <tbody>{rows.length ? rows : <tr><td className="empty" colSpan={columns}>{empty}</td></tr>}</tbody>
    </table></div>
  </section>;
}

function AnalyticsSkeleton() {
  return <div className="analytics-kpis analytics-kpis-4" aria-hidden="true">
    {[0, 1, 2, 3].map((index) => <div className="card analytics-kpi analytics-skeleton" key={index}><span /><strong /><small /></div>)}
  </div>;
}

function num(value: number | null | undefined) {
  return value === null || value === undefined ? "—" : value.toLocaleString("vi-VN");
}
function vnd(value: number | null | undefined) {
  if (value === null || value === undefined) return "—";
  return new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND", maximumFractionDigits: 0 }).format(value);
}
function pct(value: number | null | undefined) {
  return value === null || value === undefined ? "—" : `${(value * 100).toFixed(1)}%`;
}
function ms(value: number | null | undefined) {
  if (value === null || value === undefined) return "—";
  return value >= 1000 ? `${(value / 1000).toFixed(2)} s` : `${Math.round(value)} ms`;
}
function formatDay(day: string) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) return day || "—";
  return new Intl.DateTimeFormat("vi-VN", { day: "2-digit", month: "2-digit", timeZone: "Asia/Ho_Chi_Minh" }).format(new Date(`${day}T12:00:00Z`));
}
