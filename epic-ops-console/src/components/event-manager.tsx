"use client";

import { apiFetch } from "@/lib/api";
import { useCallback, useEffect, useState } from "react";
import { Users } from "lucide-react";
import {
  capacityLabel,
  emptyEventDraft,
  eventDraftFromRecord,
  eventListQuery,
  eventTimelineLabel,
  eventTimelineTone,
  formatEventRange,
} from "@/lib/event-workflows.js";
import type { EventDraft, EventListItem, EventSettings } from "@/lib/event-types";
import { EventEditor } from "@/components/event-editor";
import { EventRegistrations } from "@/components/event-registrations";

type ListResponse = { items: EventListItem[]; total: number; page: number; per_page: number; total_pages: number; settings: EventSettings };

const date = (value?: string | null) => value
  ? new Intl.DateTimeFormat("vi-VN", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Ho_Chi_Minh" }).format(new Date(`${value.replace(" ", "T")}Z`))
  : "—";

async function api<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await apiFetch(`/api/dashboard/events${path}`, {
    ...init,
    cache: "no-store",
    headers: { ...(init?.body ? { "Content-Type": "application/json" } : {}), ...init?.headers },
  });
  const data = await response.json().catch(() => ({}));
  if (response.status === 401) throw new Error("Phiên quản trị đã hết hạn. Vui lòng đăng nhập lại.");
  if (!response.ok) throw new Error(data.message || data.error || "Không thể tải sự kiện.");
  return data as T;
}

export function EventManager() {
  const [items, setItems] = useState<EventListItem[]>([]);
  const [total, setTotal] = useState(0);
  const [totalPages, setTotalPages] = useState(1);
  const [page, setPage] = useState(1);
  const [settings, setSettings] = useState<EventSettings | null>(null);
  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [draft, setDraft] = useState<EventDraft | null>(null);
  const [registrationsEvent, setRegistrationsEvent] = useState<{ id: number; title: string } | null>(null);

  const load = useCallback(async (nextPage: number) => {
    setLoading(true);
    try {
      const data = await api<ListResponse>(`?${eventListQuery({ status, search, page: nextPage, per_page: 25 })}`);
      setItems(data.items ?? []);
      setTotal(data.total ?? 0);
      setTotalPages(data.total_pages ?? 1);
      setSettings(data.settings ?? null);
      setPage(data.page ?? nextPage);
      setError("");
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Không tải được danh sách sự kiện.");
    } finally {
      setLoading(false);
    }
  }, [search, status]);

  useEffect(() => {
    const timer = window.setTimeout(() => { void load(1); }, 0);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, search]);

  function openNew() {
    setError("");
    setNotice("");
    setDraft(emptyEventDraft() as EventDraft);
  }

  async function openExisting(id: number) {
    setError("");
    setNotice("");
    try {
      const data = await api<{ event: EventDraft }>(`/${id}`);
      setDraft(eventDraftFromRecord(data.event) as EventDraft);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Không tải được sự kiện.");
    }
  }

  async function afterSave(record: EventDraft) {
    if (record?.id) {
      await load(page);
      try {
        const data = await api<{ event: EventDraft }>(`/${record.id}`);
        setDraft((current) => current ? eventDraftFromRecord(data.event) as EventDraft : current);
      } catch {
        /* keep the current draft if the refresh fails */
      }
    }
  }

  if (registrationsEvent) {
    return <EventRegistrations
      eventId={registrationsEvent.id}
      eventTitle={registrationsEvent.title}
      onBack={() => { setRegistrationsEvent(null); void load(page); }}
    />;
  }

  const published = items.filter((item) => item.status === "published").length;
  const upcoming = items.filter((item) => item.status === "published" && item.state !== "ended").length;
  const upcomingRegistrations = items
    .filter((item) => item.state !== "ended")
    .reduce((sum, item) => sum + (item.stats?.taken ?? 0), 0);

  return <>
    <div className="page-heading">
      <div>
        <div className="eyebrow">EPIC / Sự kiện</div>
        <h1>Sự kiện</h1>
        <p>Tạo sự kiện, cấu hình form đăng ký, quản lý danh sách chờ và gallery sau sự kiện.</p>
      </div>
      <button className="action-button" type="button" onClick={openNew}>Tạo sự kiện</button>
    </div>

    {notice && <div className="success-box" role="status">{notice}</div>}
    {error && <div className="error-box" role="alert">{error}</div>}

    <div className="summary-grid">
      <section className="card section-card">
        <div className="toolbar"><h2 style={{ margin: 0 }}>Danh sách sự kiện</h2><span className="muted">{total.toLocaleString("vi-VN")} sự kiện</span></div>
        <form className="toolbar" style={{ flexWrap: "wrap" }} onSubmit={(event) => { event.preventDefault(); void load(1); }}>
          <input className="search-input" aria-label="Tìm sự kiện" placeholder="Tìm tên hoặc đường dẫn…" value={search} onChange={(event) => setSearch(event.target.value)} />
          <select className="search-input" aria-label="Trạng thái" value={status} onChange={(event) => setStatus(event.target.value)}>
            <option value="">Mọi trạng thái</option>
            <option value="published">Đã xuất bản</option>
            <option value="draft">Bản nháp</option>
            <option value="archived">Đã lưu trữ</option>
          </select>
          <button className="quiet-button" type="submit" disabled={loading}>Tìm</button>
        </form>

        <div className="table-wrap">
          <table>
            <thead><tr><th>Sự kiện</th><th>Thời gian</th><th>Diễn ra</th><th>Trạng thái</th><th>Chỗ</th><th>Đăng ký</th><th>Gallery</th><th>Thao tác</th></tr></thead>
            <tbody>
              {items.map((item) => <tr key={item.id}>
                <td>
                  <button type="button" style={{ background: "none", border: 0, padding: 0, font: "inherit", color: "inherit", textAlign: "left", cursor: "pointer", textDecoration: "underline" }} onClick={() => void openExisting(item.id)}>{item.title || item.slug}</button>
                  <div className="muted" style={{ fontSize: 11 }}>/{item.slug}</div>
                </td>
                <td>{formatEventRange(item.starts_at, item.ends_at)}</td>
                <td><span className="status" data-status={eventTimelineTone(item.state)}>{eventTimelineLabel(item.state)}</span></td>
                <td><span className="status" data-status={item.status === "published" ? "approved" : item.status === "archived" ? "failed" : "pending"}>{item.status === "published" ? "Đã xuất bản" : item.status === "archived" ? "Đã lưu trữ" : "Bản nháp"}</span></td>
                <td>{capacityLabel(item)}</td>
                <td>{item.stats?.taken ?? 0}{item.stats?.waitlisted ? <span className="muted"> · {item.stats.waitlisted} chờ</span> : null}</td>
                <td>{item.gallery_count}{item.gallery_visible ? <span className="muted"> · hiện</span> : <span className="muted"> · ẩn</span>}</td>
                <td><button className="quiet-button" type="button" style={{ display: "inline-flex", alignItems: "center", gap: 6, whiteSpace: "nowrap" }} aria-label={`Xem người tham gia ${item.title || item.slug}`} onClick={() => setRegistrationsEvent({ id: item.id, title: item.title || item.slug })}><Users size={15} aria-hidden />Người tham gia</button></td>
              </tr>)}
              {!items.length && <tr><td colSpan={8} className="empty">{loading ? "Đang tải…" : "Chưa có sự kiện nào. Tạo sự kiện đầu tiên."}</td></tr>}
            </tbody>
          </table>
        </div>

        <div className="toolbar">
          <button className="quiet-button" type="button" disabled={loading || page <= 1} onClick={() => void load(page - 1)}>Trước</button>
          <span className="muted">Trang {page}/{totalPages} · {published} đã xuất bản trong trang</span>
          <button className="quiet-button" type="button" disabled={loading || page >= totalPages} onClick={() => void load(page + 1)}>Tiếp</button>
        </div>
      </section>

      <section className="card section-card">
        <div className="toolbar"><h2 style={{ margin: 0 }}>Tình hình</h2><span className="status" data-status="approved">Đang chạy</span></div>
        <div className="alert-row"><span>Sự kiện sắp/đang diễn ra</span><strong>{upcoming}</strong></div>
        <div className="alert-row"><span>Đăng ký đã xác nhận</span><strong>{upcomingRegistrations}</strong></div>
        <div className="alert-row"><span>Ngôn ngữ nội dung</span><strong>vi, en</strong></div>
        <div className="alert-row"><span>Email nhận đăng ký</span><span>{settings?.notification_email ?? "—"}</span></div>
        <div className="alert-row"><span>Tổng hợp lần cuối</span><span>{settings?.last_compiled ? date(settings.last_compiled) : "—"}</span></div>
        <p className="muted">Sự kiện đã xuất bản hiện trên <code>/{`{lang}`}/events</code>. Để trống số chỗ nghĩa là không giới hạn; gallery chỉ công khai khi bật thủ công.</p>
        {settings && <p className="muted">Tối đa {settings.max_questions} câu hỏi, {settings.max_media} ảnh gallery, ảnh ≤ {Math.round(settings.max_image_bytes / (1024 * 1024))} MB.</p>}
      </section>
    </div>

    {draft && <EventEditor
      draft={draft}
      settings={settings}
      onChange={setDraft}
      onClose={() => setDraft(null)}
      onSaved={(record) => { setNotice("Đã lưu sự kiện."); void afterSave(record); }}
      onOpenRegistrations={(event) => { setDraft(null); setRegistrationsEvent(event); }}
    />}
  </>;
}
