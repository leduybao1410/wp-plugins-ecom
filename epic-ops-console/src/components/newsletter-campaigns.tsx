"use client";

import { apiFetch } from "@/lib/api";

import { useCallback, useEffect, useState } from "react";
import type { FormEvent } from "react";
import { validateNewsletterCampaign } from "@/lib/business-workflows.js";

type Campaign = {
  id: number; subject_vi: string; subject_en: string; body_vi: string; body_en: string;
  recipient_filter: "all" | "vi" | "en"; status: "draft" | "sending" | "done";
  total_recipients: number; sent_count: number; failed_count: number; created_at: string; revision: string;
};
type CampaignForm = { subject_vi: string; subject_en: string; body_vi: string; body_en: string; recipient_filter: "all" | "vi" | "en" };
type Page = { items?: Campaign[]; total?: number };

const initial: CampaignForm = { subject_vi: "", subject_en: "", body_vi: "", body_en: "", recipient_filter: "all" };

export function NewsletterCampaigns() {
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [selected, setSelected] = useState<Campaign | null>(null);
  const [form, setForm] = useState(initial);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [confirmSend, setConfirmSend] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const load = useCallback(async (pageNum = page) => {
    setBusy(true); setError("");
    try {
      const response = await apiFetch(`/api/dashboard/newsletter/campaigns?page=${pageNum}&per_page=10`);
      const payload: Page & { error?: string } = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không tải được chiến dịch");
      setCampaigns(payload.items ?? []); setTotal(payload.total ?? 0);
    } catch (e) { setError(e instanceof Error ? e.message : "Không tải được chiến dịch"); }
    finally { setBusy(false); }
  }, [page]);
  useEffect(() => { const timer = window.setTimeout(() => { void load(); }, 0); return () => window.clearTimeout(timer); }, [load]);

  function edit(campaign: Campaign) {
    setSelected(campaign);
    setForm({ subject_vi: campaign.subject_vi, subject_en: campaign.subject_en, body_vi: campaign.body_vi, body_en: campaign.body_en, recipient_filter: campaign.recipient_filter });
    setConfirmSend(false); setNotice("");
  }

  async function save(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError(""); setNotice("");
    try {
      const validated = validateNewsletterCampaign(form);
      const response = await apiFetch(selected ? `/api/dashboard/newsletter/campaigns/${selected.id}` : "/api/dashboard/newsletter/campaigns", {
        method: selected ? "PATCH" : "POST",
        headers: { "Content-Type": "application/json", ...(!selected ? { "Idempotency-Key": crypto.randomUUID() } : {}) },
        body: JSON.stringify({ ...form, ...validated, ...(selected ? { expected_revision: selected.revision } : {}) }),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không lưu được bản nháp");
      setNotice(selected ? "Đã lưu bản nháp." : "Đã tạo bản nháp và chụp danh sách người nhận.");
      setSelected(payload.campaign); setForm({ subject_vi: payload.campaign.subject_vi, subject_en: payload.campaign.subject_en, body_vi: payload.campaign.body_vi, body_en: payload.campaign.body_en, recipient_filter: payload.campaign.recipient_filter });
      await load();
    } catch (e) { setError(e instanceof Error ? e.message : "Không lưu được bản nháp"); }
    finally { setBusy(false); }
  }

  async function send() {
    if (!selected) return;
    try { validateNewsletterCampaign({ confirm_send: confirmSend }, { sending: true }); }
    catch (e) { setError(e instanceof Error ? e.message : "Cần xác nhận gửi chiến dịch"); return; }
    if (!window.confirm(`Gửi chiến dịch “${selected.subject_vi}” tới ${selected.total_recipients.toLocaleString("vi-VN")} người nhận?`)) return;
    setBusy(true); setError(""); setNotice("");
    try {
      const response = await apiFetch(`/api/dashboard/newsletter/campaigns/${selected.id}/send`, { method: "POST", headers: { "Content-Type": "application/json", "Idempotency-Key": crypto.randomUUID() }, body: JSON.stringify({ confirm_send: true }) });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không đưa được chiến dịch vào hàng đợi");
      setNotice("Chiến dịch đã được đưa vào hàng đợi gửi nền của WordPress."); setConfirmSend(false); await load();
      const detail = await apiFetch(`/api/dashboard/newsletter/campaigns/${selected.id}`);
      if (detail.ok) setSelected(await detail.json());
    } catch (e) { setError(e instanceof Error ? e.message : "Không đưa được chiến dịch vào hàng đợi"); }
    finally { setBusy(false); }
  }

  const canEdit = !selected || selected.status === "draft";
  return <>
    <div className="page-heading"><div><div className="eyebrow">EPIC / Tiếp thị</div><h1>Chiến dịch bản tin</h1><p>Soạn bản nháp, xem trước nội dung và theo dõi kết quả gửi theo từng người nhận.</p></div><button className="quiet-button" onClick={() => { setSelected(null); setForm(initial); setConfirmSend(false); setNotice(""); setError(""); }}>Tạo bản nháp</button></div>
    {notice && <div className="success-box" role="status">{notice}</div>}{error && <div className="error-box" role="alert">{error}</div>}
    <div className="summary-grid">
      <section className="card section-card"><div className="toolbar"><h2 style={{ margin: 0 }}>Chiến dịch đã lưu</h2><span className="muted">{total.toLocaleString("vi-VN")} chiến dịch</span></div>
        {campaigns.map((campaign) => <button key={campaign.id} type="button" className="alert-row campaign-row" onClick={() => edit(campaign)}><span><strong>{campaign.subject_vi}</strong><small style={{ display: "block", color: "var(--muted)", marginTop: 4 }}>{campaign.status} · {campaign.sent_count}/{campaign.total_recipients} đã gửi · {campaign.failed_count} lỗi</small></span><span aria-hidden="true">→</span></button>)}
        {!campaigns.length && <p className="empty">Chưa có chiến dịch bản tin.</p>}
        <div className="toolbar"><button className="quiet-button" disabled={page <= 1 || busy} onClick={() => setPage((value) => value - 1)}>Trước</button><span className="muted">Trang {page}</span><button className="quiet-button" disabled={busy || page * 10 >= total} onClick={() => setPage((value) => value + 1)}>Tiếp</button></div>
      </section>
      <section className="card section-card"><h2>{selected ? `Chiến dịch #${selected.id}` : "Soạn chiến dịch"}</h2>
        {selected && <div className="alert-row"><span>Trạng thái gửi</span><span className="status" data-status={selected.status === "done" ? "approved" : selected.status === "sending" ? "pending" : "draft"}>{selected.status}</span></div>}
        <form onSubmit={(event) => void save(event)} style={{ display: "grid", gap: 10 }}>
          <label htmlFor="campaign-subject-vi">Tiêu đề tiếng Việt</label><input id="campaign-subject-vi" className="search-input" value={form.subject_vi} disabled={!canEdit} onChange={(event) => setForm({ ...form, subject_vi: event.target.value })} required />
          <label htmlFor="campaign-body-vi">Nội dung tiếng Việt</label><textarea id="campaign-body-vi" className="search-input" style={{ minHeight: 130, padding: 10 }} value={form.body_vi} disabled={!canEdit} onChange={(event) => setForm({ ...form, body_vi: event.target.value })} required />
          <label htmlFor="campaign-subject-en">Tiêu đề tiếng Anh (không bắt buộc)</label><input id="campaign-subject-en" className="search-input" value={form.subject_en} disabled={!canEdit} onChange={(event) => setForm({ ...form, subject_en: event.target.value })} />
          <label htmlFor="campaign-body-en">Nội dung tiếng Anh (không bắt buộc)</label><textarea id="campaign-body-en" className="search-input" style={{ minHeight: 100, padding: 10 }} value={form.body_en} disabled={!canEdit} onChange={(event) => setForm({ ...form, body_en: event.target.value })} />
          <label htmlFor="campaign-recipients">Nhóm người nhận</label><select id="campaign-recipients" className="search-input" value={form.recipient_filter} disabled={!canEdit} onChange={(event) => setForm({ ...form, recipient_filter: event.target.value as typeof form.recipient_filter })}><option value="all">Tất cả người đăng ký</option><option value="vi">Giao diện tiếng Việt</option><option value="en">Giao diện tiếng Anh</option></select>
          {selected && <div className="alert-row"><span>Danh sách người nhận đã chụp</span><strong>{selected.total_recipients.toLocaleString("vi-VN")}</strong></div>}
          {canEdit && <button className="action-button" type="submit" disabled={busy}>{selected ? "Lưu bản nháp" : "Tạo bản nháp"}</button>}
        </form>
        <section style={{ marginTop: 20, borderTop: "1px solid var(--line)", paddingTop: 16 }}><h3>Xem trước thư tiếng Việt</h3><strong>{form.subject_vi || "Chưa có tiêu đề"}</strong><p className="muted" style={{ whiteSpace: "pre-wrap", lineHeight: 1.6 }}>{form.body_vi.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim() || "Nội dung sẽ hiển thị tại đây."}</p></section>
        {selected && canEdit && <div style={{ display: "grid", gap: 10, marginTop: 16 }}><label style={{ display: "flex", gap: 8, alignItems: "flex-start" }}><input type="checkbox" checked={confirmSend} onChange={(event) => setConfirmSend(event.target.checked)} /><span>Tôi đã xem lại tiêu đề, nội dung và danh sách {selected.total_recipients.toLocaleString("vi-VN")} người nhận.</span></label><button className="action-button" type="button" disabled={busy || !confirmSend} onClick={() => void send()}>Xác nhận đưa vào hàng đợi gửi</button><small className="muted">WordPress gửi theo lô nền và giữ lại kết quả từng người nhận.</small></div>}
        {selected && !canEdit && <div className="alert-row"><span>Kết quả hiện tại</span><strong>{selected.sent_count.toLocaleString("vi-VN")} đã gửi · {selected.failed_count.toLocaleString("vi-VN")} lỗi · {selected.total_recipients.toLocaleString("vi-VN")} người nhận</strong></div>}
      </section>
    </div>
  </>;
}
