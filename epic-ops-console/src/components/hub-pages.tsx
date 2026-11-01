"use client";

import { apiFetch } from "@/lib/api";

import { useRouter, useSearchParams } from "@/lib/router";
import { useEffect, useState } from "react";
import { RecordsPage } from "@/components/records-page";
import { NewsletterCampaigns } from "@/components/newsletter-campaigns";
import { MailCampaigns } from "@/components/mail-campaigns";
import { WholesaleSettingsPage } from "@/components/wholesale-settings";
import { FinanceLedger } from "@/components/finance-ledger";
import { CouponRedemptions } from "@/components/coupon-redemptions";
import { CouponManager } from "@/components/coupon-manager";

const options: Record<string, { id: string; label: string; resource?: string }[]> = {
  finance: [{ id: "costs", label: "Giá vốn", resource: "costs" }, { id: "distributors", label: "Nhà phân phối", resource: "distributors" }, { id: "ledger", label: "Sổ hoa hồng", resource: "ledger" }],
  marketing: [{ id: "newsletter", label: "Người đăng ký", resource: "newsletter" }, { id: "campaigns", label: "Chiến dịch" }, { id: "mail-campaigns", label: "Chiến dịch email" }, { id: "coupons", label: "Mã giảm giá", resource: "coupons" }, { id: "redemptions", label: "Lịch sử đổi mã" }],
  wholesale: [{ id: "wholesale-orders", label: "Đơn bán sỉ", resource: "wholesale-orders" }, { id: "wholesale-settings", label: "Khách sỉ và mức giá" }],
};

export function RecordsHub({ module, title }: { module: "finance" | "marketing" | "wholesale"; title: string }) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const requested = searchParams.get("view");
  const tab = options[module].find((item) => item.id === requested)?.id ?? options[module][0].id;
  const current = options[module].find((item) => item.id === tab) ?? options[module][0];
  return <>
    <div className="page-heading"><div><div className="eyebrow">EPIC / Quản trị</div><h1>{title}</h1><p>{module === "finance" ? "Giá vốn, nhà phân phối và sổ lợi nhuận hiện có." : module === "wholesale" ? "Đơn hàng riêng, khách được cấp quyền và mức giá bán sỉ." : "Người đăng ký, chiến dịch bản tin và mã giảm giá."}</p></div></div>
    <div role="tablist" aria-label={title} style={{ display: "flex", gap: 6, marginBottom: 14, overflowX: "auto" }}>
      {options[module].map((item) => <button key={item.id} type="button" role="tab" aria-selected={tab === item.id} className={tab === item.id ? "action-button" : "quiet-button"} onClick={() => { const url = new URL(window.location.href); url.searchParams.set("view", item.id); for (const key of ["page", "per_page", "source", "search", "status", "record"]) url.searchParams.delete(key); router.push(`${url.pathname}${url.search}`); }}>{item.label}</button>)}
    </div>
    {current.id === "campaigns" ? <NewsletterCampaigns /> : current.id === "mail-campaigns" ? <MailCampaigns /> : current.id === "redemptions" ? <CouponRedemptions /> : current.id === "coupons" ? <CouponManager /> : current.id === "wholesale-settings" ? <WholesaleSettingsPage /> : current.id === "ledger" ? <FinanceLedger /> : <RecordsPage key={current.resource} module={current.resource === "wholesale-orders" ? "wholesale-orders" : module} title={current.label} resource={current.resource ?? current.id} />}
  </>;
}

type Health = { wordpress?: boolean; woocommerce?: boolean; plugins?: Record<string, boolean> };
type AuditRow = { id: number; user_id: number; action: string; resource: string; record_id: string; outcome: string; created_at: string; fields: string };

export function SettingsPage() {
  const [health, setHealth] = useState<Health | null>(null);
  const [audit, setAudit] = useState<AuditRow[]>([]);
  const [error, setError] = useState("");
  useEffect(() => {
    Promise.all([apiFetch("/api/dashboard/health").then((r) => r.json()), apiFetch("/api/dashboard/audit?per_page=8").then((r) => r.json())])
      .then(([state, entries]) => { if (state.error || entries.error) throw new Error(state.error || entries.error); setHealth(state); setAudit(entries.items ?? []); })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : "Không tải được cài đặt"));
  }, []);
  return <>
    <div className="page-heading"><div><div className="eyebrow">EPIC / Quản trị</div><h1>Cài đặt kinh doanh</h1><p>Kiểm tra kết nối, lịch sử thao tác và truy cập cấu hình WordPress.</p></div></div>
    {error && <div className="error-box" role="alert">{error}</div>}
    <div className="summary-grid">
      <section className="card section-card"><h2>Tình trạng kết nối</h2>
        <HealthRow label="WordPress" ready={health?.wordpress} />
        <HealthRow label="WooCommerce" ready={health?.woocommerce} />
        {(Object.entries(health?.plugins ?? {}) as [string, boolean][]).map(([name, ready]) => <HealthRow key={name} label={name.replace(/^epic-/, "").replaceAll("-", " ")} ready={ready} />)}
        {!health && <p className="muted">Đang kiểm tra…</p>}
      </section>
      <section className="card section-card"><h2>Cấu hình WordPress</h2><p>Các cấu hình nhạy cảm và tác vụ không được plugin hiện tại hỗ trợ vẫn được quản lý tại nơi lưu dữ liệu.</p>
        <div className="alert-row"><span>Cài đặt vận chuyển WooCommerce</span><a href="https://admin.epicroastery.coffee/wp-admin/admin.php?page=wc-settings&tab=shipping" target="_blank" rel="noreferrer">Mở cấu hình ↗</a></div>
        <div className="alert-row"><span>Email thông báo WooCommerce</span><a href="https://admin.epicroastery.coffee/wp-admin/admin.php?page=wc-settings&tab=email" target="_blank" rel="noreferrer">Mở cấu hình ↗</a></div>
        <div className="alert-row"><span>Người dùng, plugin và thông tin đăng nhập</span><a href="https://admin.epicroastery.coffee/wp-admin/" target="_blank" rel="noreferrer">Mở wp-admin ↗</a></div>
      </section>
    </div>
    <section className="card" style={{ marginTop: 16 }}><div className="toolbar"><strong>Nhật ký thao tác</strong><span className="muted">Ghi tên trường thay đổi, không lưu giá trị</span></div><div className="table-wrap"><table><thead><tr><th>Thời điểm</th><th>Hành động</th><th>Bản ghi</th><th>Kết quả</th></tr></thead><tbody>{audit.map((item) => <tr key={item.id}><td>{new Intl.DateTimeFormat("vi-VN", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Ho_Chi_Minh" }).format(new Date(`${item.created_at}Z`))}</td><td>{item.action}</td><td>{item.resource} {item.record_id && `#${item.record_id}`}</td><td><span className="status" data-status={item.outcome === "success" ? "approved" : "failed"}>{item.outcome}</span></td></tr>)}{!audit.length && <tr><td colSpan={4} className="empty">Chưa có thao tác được ghi nhận.</td></tr>}</tbody></table></div></section>
  </>;
}

function HealthRow({ label, ready }: { label: string; ready: boolean | undefined }) { return <div className="alert-row"><span>{label}</span><span className="status" data-status={ready ? "approved" : ready === false ? "failed" : "pending"}>{ready === undefined ? "Chưa kiểm tra" : ready ? "Đang hoạt động" : "Chưa khả dụng"}</span></div>; }
