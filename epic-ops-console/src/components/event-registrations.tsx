"use client";

import { apiFetch } from "@/lib/api";
import { useCallback, useEffect, useState } from "react";
import * as Dialog from "@radix-ui/react-dialog";
import {
  contactChannelLabel,
  contactLabel,
  registrationListQuery,
  registrationStatusLabel,
  registrationStatusTone,
} from "@/lib/event-workflows.js";
import type { EventDraft, EventRegistration, QuestionDraft, RegistrationListResponse } from "@/lib/event-types";

type Props = {
  eventId: number;
  eventTitle: string;
  onBack: () => void;
};

const date = (value?: string | null) => value
  ? new Intl.DateTimeFormat("vi-VN", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Ho_Chi_Minh" }).format(new Date(`${value.replace(" ", "T")}Z`))
  : "—";

async function api<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await apiFetch(`/api/dashboard${path}`, {
    ...init,
    cache: "no-store",
    headers: { ...(init?.body ? { "Content-Type": "application/json" } : {}), ...init?.headers },
  });
  const data = await response.json().catch(() => ({}));
  if (response.status === 401) throw new Error("Phiên quản trị đã hết hạn. Vui lòng đăng nhập lại.");
  if (!response.ok) throw new Error(data.message || data.error || "Không thể tải đăng ký.");
  return data as T;
}

export function EventRegistrations({ eventId, eventTitle, onBack }: Props) {
  const [data, setData] = useState<RegistrationListResponse | null>(null);
  const [questions, setQuestions] = useState<QuestionDraft[]>([]);
  const [status, setStatus] = useState("");
  const [channel, setChannel] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [selected, setSelected] = useState<EventRegistration | null>(null);
  const [statusDraft, setStatusDraft] = useState<EventRegistration["status"]>("confirmed");
  const [noteDraft, setNoteDraft] = useState("");
  const [showTallies, setShowTallies] = useState(false);

  const load = useCallback(async (nextPage: number) => {
    setLoading(true);
    try {
      const result = await api<RegistrationListResponse>(`/events${registrationListQuery({ eventId, status, channel, search, page: nextPage, per_page: 25 })}`);
      setData(result);
      setPage(result.page ?? nextPage);
      setError("");
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Không tải được danh sách đăng ký.");
    } finally {
      setLoading(false);
    }
  }, [channel, eventId, search, status]);

  useEffect(() => {
    const timer = window.setTimeout(() => { void load(1); }, 0);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, channel, search]);

  useEffect(() => {
    let cancelled = false;
    api<{ event: EventDraft }>(`/events/${eventId}`)
      .then((result) => { if (!cancelled) setQuestions(result.event.questions ?? []); })
      .catch(() => { if (!cancelled) setQuestions([]); });
    return () => { cancelled = true; };
  }, [eventId]);

  function openRegistration(registration: EventRegistration) {
    setSelected(registration);
    setStatusDraft(registration.status);
    setNoteDraft(registration.note ?? "");
  }

  async function saveRegistration() {
    if (!selected) return;
    setBusy(true);
    try {
      const result = await api<{ registration: EventRegistration }>(`/registrations/${selected.id}`, {
        method: "PATCH",
        body: JSON.stringify({ status: statusDraft, note: noteDraft }),
      });
      setSelected(result.registration);
      setNotice("Đã cập nhật đăng ký.");
      await load(page);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Không cập nhật được đăng ký.");
    } finally {
      setBusy(false);
    }
  }

  async function resend() {
    if (!selected) return;
    setBusy(true);
    try {
      const result = await api<{ email_status: string; registration: EventRegistration }>(`/registrations/${selected.id}/resend`, { method: "POST", body: JSON.stringify({}) });
      setSelected(result.registration);
      setNotice(result.email_status === "sent" ? "Đã gửi lại email xác nhận." : "Không gửi được email. Kiểm tra cấu hình email của website.");
      await load(page);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Không gửi lại được email.");
    } finally {
      setBusy(false);
    }
  }

  async function exportCsv() {
    setBusy(true);
    try {
      const params = new URLSearchParams();
      if (status) params.set("status", status);
      if (channel) params.set("channel", channel);
      if (search) params.set("search", search);
      const response = await apiFetch(`/api/dashboard/events/${eventId}/registrations/export?${params.toString()}`, { cache: "no-store" });
      if (!response.ok) throw new Error("Không xuất được CSV.");
      const blob = await response.blob();
      const disposition = response.headers.get("content-disposition") ?? "";
      const filename = /filename="([^"]+)"/.exec(disposition)?.[1] ?? `epic-events-${eventId}.csv`;
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      link.click();
      URL.revokeObjectURL(url);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Không xuất được CSV.");
    } finally {
      setBusy(false);
    }
  }

  const summary = data?.summary;
  const localized = (question?: QuestionDraft) => question?.translations?.vi ?? question?.translations?.en ?? null;
  const questionLabel = (questionId: number) => {
    const label = localized(questions.find((question) => question.id === questionId))?.label;
    return label || `Câu hỏi #${questionId}`;
  };

  return <>
    <div className="page-heading">
      <div>
        <div className="eyebrow">EPIC / Sự kiện</div>
        <h1>Đăng ký — {eventTitle}</h1>
        <p>Danh sách người đăng ký, danh sách chờ và câu trả lời khảo sát.</p>
      </div>
      <div className="toolbar">
        <button className="quiet-button" type="button" onClick={onBack}>← Danh sách sự kiện</button>
        <button className="quiet-button" type="button" disabled={busy} onClick={() => void exportCsv()}>Xuất CSV</button>
      </div>
    </div>

    {notice && <div className="success-box" role="status">{notice}</div>}
    {error && <div className="error-box" role="alert">{error}</div>}

    {summary && <section className="card section-card">
      <div className="toolbar"><h2 style={{ margin: 0 }}>Tổng quan</h2><span className="muted">{summary.total} đăng ký</span></div>
      <div className="alert-row"><span>Đã xác nhận</span><strong>{summary.confirmed + summary.attended}{summary.capacity ? `/${summary.capacity}` : " · không giới hạn"}</strong></div>
      <div className="alert-row"><span>Còn trống</span><strong>{summary.remaining === null ? "Không giới hạn" : summary.remaining}</strong></div>
      <div className="alert-row"><span>Danh sách chờ</span><strong>{summary.waitlisted}</strong></div>
      <div className="alert-row"><span>Đã tham dự / không đến</span><strong>{summary.attended} / {summary.no_show}</strong></div>
      <div className="alert-row"><span>Đã huỷ</span><strong>{summary.cancelled}</strong></div>
      {data && data.answers_summary.length > 0 && <>
        <button className="quiet-button" type="button" onClick={() => setShowTallies((current) => !current)}>{showTallies ? "Ẩn" : "Xem"} thống kê câu trả lời</button>
        {showTallies && <div style={{ marginTop: 10, display: "grid", gap: 14 }}>
          {data.answers_summary.map((question) => <div key={question.question_id}>
            <strong>{question.label}</strong>
            <div style={{ display: "grid", gap: 4, marginTop: 6 }}>
              {question.options.map((option) => {
                const max = Math.max(1, ...question.options.map((entry) => entry.count));
                return <div key={option.value} style={{ display: "grid", gridTemplateColumns: "1fr 120px 40px", gap: 8, alignItems: "center", fontSize: 12 }}>
                  <span>{option.label}</span>
                  <span style={{ height: 8, borderRadius: 999, background: "var(--line)", overflow: "hidden" }}><span style={{ display: "block", height: "100%", width: `${Math.round((option.count / max) * 100)}%`, background: "var(--accent)" }} /></span>
                  <span className="muted">{option.count}</span>
                </div>;
              })}
            </div>
          </div>)}
        </div>}
      </>}
    </section>}

    <section className="card section-card" style={{ marginTop: 16 }}>
      <form className="toolbar" style={{ flexWrap: "wrap" }} onSubmit={(event) => { event.preventDefault(); void load(1); }}>
        <input className="search-input" aria-label="Tìm đăng ký" placeholder="Tìm tên, email, SĐT hoặc mã…" value={search} onChange={(event) => setSearch(event.target.value)} />
        <select className="search-input" aria-label="Trạng thái" value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">Mọi trạng thái</option>
          <option value="confirmed">Đã xác nhận</option>
          <option value="waitlisted">Danh sách chờ</option>
          <option value="attended">Đã tham dự</option>
          <option value="no_show">Không đến</option>
          <option value="cancelled">Đã huỷ</option>
        </select>
        <select className="search-input" aria-label="Kênh liên hệ" value={channel} onChange={(event) => setChannel(event.target.value)}>
          <option value="">Mọi kênh</option>
          <option value="email">Có email</option>
          <option value="phone">Chỉ SĐT</option>
        </select>
        <button className="quiet-button" type="submit" disabled={loading}>Tìm</button>
      </form>

      <div className="table-wrap">
        <table>
          <thead><tr><th>Mã</th><th>Họ tên</th><th>Liên hệ</th><th>Kênh</th><th>Trạng thái</th><th>Đăng ký lúc</th></tr></thead>
          <tbody>
            {data?.items.map((registration) => <tr key={registration.id}>
              <td><button type="button" style={{ background: "none", border: 0, padding: 0, font: "inherit", color: "inherit", textAlign: "left", cursor: "pointer", textDecoration: "underline" }} onClick={() => openRegistration(registration)}>{registration.code}</button></td>
              <td>{registration.name}</td>
              <td>{contactLabel(registration)}</td>
              <td>{contactChannelLabel(registration)}{registration.contact_channel === "phone" && <span className="muted"> · chưa xác thực</span>}</td>
              <td><span className="status" data-status={registrationStatusTone(registration.status)}>{registrationStatusLabel(registration.status)}{registration.status === "waitlisted" && registration.waitlist_position ? ` #${registration.waitlist_position}` : ""}</span></td>
              <td>{date(registration.created_at)}</td>
            </tr>)}
            {!data?.items.length && <tr><td colSpan={6} className="empty">{loading ? "Đang tải…" : "Chưa có đăng ký nào."}</td></tr>}
          </tbody>
        </table>
      </div>

      <div className="toolbar">
        <button className="quiet-button" type="button" disabled={loading || page <= 1} onClick={() => void load(page - 1)}>Trước</button>
        <span className="muted">Trang {page}/{data?.total_pages ?? 1} · {data?.total ?? 0} đăng ký</span>
        <button className="quiet-button" type="button" disabled={loading || page >= (data?.total_pages ?? 1)} onClick={() => void load(page + 1)}>Tiếp</button>
      </div>
      <p className="muted">Đăng ký chỉ có số điện thoại không nhận email xác nhận (không OTP, không SMS). Liên hệ thủ công theo số đã lưu.</p>
    </section>

    {selected && <Dialog.Root open onOpenChange={(open) => { if (!open) setSelected(null); }}>
      <Dialog.Portal>
        <Dialog.Overlay className="detail-backdrop" />
        <Dialog.Content className="detail-panel" aria-describedby={undefined}>
          <div className="detail-header">
            <div><div className="eyebrow">Đăng ký / {selected.code}</div><h2>{selected.name}</h2></div>
            <Dialog.Close asChild><button className="quiet-button" type="button">Đóng</button></Dialog.Close>
          </div>

          <div className="detail-section">
            <div className="alert-row"><span>Email</span><span>{selected.email ?? "—"}</span></div>
            <div className="alert-row"><span>Số điện thoại</span><span>{selected.phone ?? "—"}{selected.phone && <span className="muted"> · chưa xác thực</span>}</span></div>
            <div className="alert-row"><span>Kênh liên hệ</span><span>{contactChannelLabel(selected)}</span></div>
            <div className="alert-row"><span>Trạng thái email</span><span>{selected.email_status === "sent" ? "Đã gửi" : selected.email_status === "failed" ? "Gửi lỗi" : selected.email_status === "not_applicable" ? "Không áp dụng (chỉ SĐT)" : "Chưa gửi"}</span></div>
            <div className="alert-row"><span>Đăng ký lúc</span><span>{date(selected.created_at)}</span></div>
            <div className="alert-row"><span>Ngôn ngữ</span><span>{selected.locale}</span></div>
          </div>

          <div className="detail-section">
            <h3>Câu trả lời</h3>
            {selected.answers.length === 0 && <p className="muted">Không có câu trả lời bổ sung.</p>}
            {selected.answers.map((answer) => {
              const question = localized(questions.find((entry) => entry.id === answer.question_id));
              const labels = answer.options.map((value) => question?.options.find((option) => option.value === value)?.label ?? value);
              return <div key={answer.question_id} style={{ marginBottom: 10 }}>
                <div className="muted">{questionLabel(answer.question_id)}</div>
                <strong>{labels.length ? labels.join(", ") : answer.text || "—"}</strong>
              </div>;
            })}
          </div>

          <div className="detail-section">
            <label htmlFor="registration-status">Trạng thái</label>
            <select id="registration-status" className="search-input" value={statusDraft} onChange={(event) => setStatusDraft(event.target.value as EventRegistration["status"])}>
              <option value="confirmed">Đã xác nhận</option>
              <option value="waitlisted">Danh sách chờ</option>
              <option value="attended">Đã tham dự</option>
              <option value="no_show">Không đến</option>
              <option value="cancelled">Đã huỷ</option>
            </select>
            <label htmlFor="registration-note" style={{ display: "block", marginTop: 8 }}>Ghi chú nội bộ</label>
            <textarea id="registration-note" className="search-input" style={{ minHeight: 80, padding: 10 }} value={noteDraft} onChange={(event) => setNoteDraft(event.target.value)} />
          </div>

          <div className="detail-actions">
            <div className="toolbar" style={{ flexWrap: "wrap" }}>
              <button className="action-button" type="button" disabled={busy} onClick={() => void saveRegistration()}>Lưu thay đổi</button>
              <button className="quiet-button" type="button" disabled={busy || !selected.email} onClick={() => void resend()}>Gửi lại email</button>
            </div>
            {!selected.email && <small className="muted">Đăng ký này không có email nên không thể gửi xác nhận.</small>}
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>}
  </>;
}
