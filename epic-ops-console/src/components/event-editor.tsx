"use client";

import { apiFetch } from "@/lib/api";
import { useState } from "react";
import * as Dialog from "@radix-ui/react-dialog";
import {
  EVENT_LOCALES,
  EVENT_LOCALE_LABELS,
  eventCompleteness,
  eventDraftFromRecord,
  eventSavePayload,
  questionsPayload,
  toOffsetIso,
  validateEventDraft,
} from "@/lib/event-workflows.js";
import type { EventDraft, EventSettings } from "@/lib/event-types";
import { EventMediaUploader } from "@/components/event-media-uploader";
import { EventQuestionBuilder } from "@/components/event-question-builder";
import { RichTextField } from "@/components/rich-text-field";

type Props = {
  draft: EventDraft;
  settings: EventSettings | null;
  onChange: (draft: EventDraft) => void;
  onClose: () => void;
  onSaved: (record: EventDraft) => void;
  onOpenRegistrations: (event: { id: number; title: string }) => void;
};

async function api<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await apiFetch(`/api/dashboard/events${path}`, {
    ...init,
    cache: "no-store",
    headers: { ...(init?.body ? { "Content-Type": "application/json" } : {}), ...init?.headers },
  });
  const data = await response.json().catch(() => ({}));
  if (response.status === 401) throw new Error("Phiên quản trị đã hết hạn. Vui lòng đăng nhập lại.");
  if (!response.ok) throw new Error(data.message || data.error || "Không thể lưu sự kiện.");
  return data as T;
}

export function EventEditor({ draft, settings, onChange, onClose, onSaved, onOpenRegistrations }: Props) {
  const [tab, setTab] = useState<"vi" | "en">("vi");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [snapshot] = useState(() => JSON.stringify(eventSavePayload(draft)));

  const completeness = eventCompleteness(draft);
  const published = draft.status === "published";
  const closeAfterStart = Boolean(
    draft.registration_closes_at
      && draft.starts_at
      && new Date(toOffsetIso(draft.registration_closes_at)).getTime() > new Date(toOffsetIso(draft.starts_at)).getTime(),
  );

  function update(patch: Partial<EventDraft>) {
    onChange({ ...draft, ...patch });
  }

  function updateTranslation(field: "title" | "summary" | "description", value: string) {
    onChange({
      ...draft,
      translations: {
        ...draft.translations,
        [tab]: { ...draft.translations[tab], [field]: value },
      },
    });
  }

  function mergeMedia(event: { revision: number; cover_media_id: number; cover_url: string; gallery: EventDraft["gallery"] }) {
    onChange({ ...draft, revision: event.revision, cover_media_id: event.cover_media_id, cover_url: event.cover_url, gallery: event.gallery });
  }

  /** Field save + question save, in that order so the revision stays fresh. */
  async function persist(): Promise<EventDraft | null> {
    const errors = validateEventDraft(draft);
    if (errors.length) { setError(errors.join(" ")); return null; }
    setBusy(true);
    setError("");
    setNotice("");
    try {
      const body = eventSavePayload(draft);
      const saved = draft.id
        ? await api<{ event: EventDraft }>(`/${draft.id}`, { method: "PATCH", body: JSON.stringify(body) })
        : await api<{ event: EventDraft }>("", { method: "POST", body: JSON.stringify(body) });
      let record = saved.event;
      const questionResult = await api<{ event: EventDraft }>(`/${record.id}/questions`, {
        method: "PUT",
        body: JSON.stringify(questionsPayload(draft.questions, record.revision)),
      });
      record = questionResult.event;
      const next = eventDraftFromRecord(record) as EventDraft;
      onChange(next);
      onSaved(next);
      setNotice("Đã lưu sự kiện.");
      return next;
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Không lưu được sự kiện.");
      return null;
    } finally {
      setBusy(false);
    }
  }

  async function publish() {
    const errors = validateEventDraft(draft, { publishing: true });
    if (errors.length) { setError(errors.join(" ")); return; }
    const saved = await persist();
    if (!saved) return;
    setBusy(true);
    try {
      const data = await api<{ event: EventDraft }>(`/${saved.id}/publish`, { method: "POST", body: JSON.stringify({ expected_revision: saved.revision }) });
      const next = eventDraftFromRecord(data.event) as EventDraft;
      onChange(next);
      onSaved(next);
      setNotice("Đã xuất bản sự kiện.");
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Không xuất bản được sự kiện.");
    } finally {
      setBusy(false);
    }
  }

  async function unpublish() {
    if (!draft.id) return;
    setBusy(true);
    try {
      const data = await api<{ event: EventDraft }>(`/${draft.id}/unpublish`, { method: "POST", body: JSON.stringify({ expected_revision: draft.revision }) });
      const next = eventDraftFromRecord(data.event) as EventDraft;
      onChange(next);
      onSaved(next);
      setNotice("Đã ngừng xuất bản.");
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Không ngừng xuất bản được.");
    } finally {
      setBusy(false);
    }
  }

  async function archive() {
    if (!draft.id) return;
    if (!window.confirm("Lưu trữ sự kiện này? Sự kiện sẽ biến mất khỏi website nhưng dữ liệu đăng ký vẫn giữ.")) return;
    setBusy(true);
    try {
      await api(`/${draft.id}/archive`, { method: "POST", body: JSON.stringify({ expected_revision: draft.revision }) });
      onSaved(draft);
      onClose();
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Không lưu trữ được sự kiện.");
    } finally {
      setBusy(false);
    }
  }

  function close() {
    if (JSON.stringify(eventSavePayload(draft)) !== snapshot && !window.confirm("Bỏ thay đổi chưa lưu?")) return;
    onClose();
  }

  return <Dialog.Root open onOpenChange={(open) => { if (!open) close(); }}>
    <Dialog.Portal>
      <Dialog.Overlay className="detail-backdrop" />
      <Dialog.Content className="detail-panel" aria-describedby={undefined}>
        <div className="detail-header">
          <div>
            <div className="eyebrow">Sự kiện / {draft.id ? `#${draft.id}` : "mới"}</div>
            <h2>{draft.translations.vi?.title || draft.translations.en?.title || "Sự kiện mới"}</h2>
          </div>
          <Dialog.Close asChild><button className="quiet-button" type="button">Đóng</button></Dialog.Close>
        </div>

        {error && <div className="error-box" role="alert">{error}</div>}
        {notice && <div className="success-box" role="status">{notice}</div>}

        <div className="detail-section">
          <div className="toolbar" role="tablist" aria-label="Ngôn ngữ nội dung" style={{ flexWrap: "wrap" }}>
            {EVENT_LOCALES.map((locale) => <button key={locale} type="button" role="tab" aria-selected={tab === locale} className={tab === locale ? "action-button" : "quiet-button"} onClick={() => setTab(locale as "vi" | "en")}>
              {EVENT_LOCALE_LABELS[locale as keyof typeof EVENT_LOCALE_LABELS]}{completeness.filled.includes(locale) ? " ✓" : ""}
            </button>)}
            <span className="muted">{completeness.filled.length}/{EVENT_LOCALES.length} ngôn ngữ đã đủ</span>
          </div>
          <label htmlFor="event-title" style={{ display: "block", marginTop: 8 }}>Tên sự kiện ({EVENT_LOCALE_LABELS[tab as keyof typeof EVENT_LOCALE_LABELS]})</label>
          <input id="event-title" className="search-input" maxLength={200} value={draft.translations[tab]?.title ?? ""} onChange={(event) => updateTranslation("title", event.target.value)} />
          <label htmlFor="event-summary" style={{ display: "block", marginTop: 8 }}>Mô tả ngắn</label>
          <input id="event-summary" className="search-input" maxLength={400} value={draft.translations[tab]?.summary ?? ""} onChange={(event) => updateTranslation("summary", event.target.value)} />
          <RichTextField
            id="event-description"
            label="Mô tả chi tiết"
            value={draft.translations[tab]?.description ?? ""}
            onChange={(value) => updateTranslation("description", value)}
            hint="Dán văn bản thường hoặc HTML cơ bản (đoạn văn, danh sách, liên kết, ảnh). Văn bản thường được tự chia đoạn khi lưu; nội dung được lọc an toàn."
          />
        </div>

        <div className="detail-section">
          <h3>Thời gian và địa điểm</h3>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10 }}>
            <div>
              <label htmlFor="event-start">Bắt đầu</label>
              <input id="event-start" className="search-input" type="datetime-local" value={draft.starts_at} onChange={(event) => update({ starts_at: event.target.value })} />
            </div>
            <div>
              <label htmlFor="event-end">Kết thúc</label>
              <input id="event-end" className="search-input" type="datetime-local" value={draft.ends_at} onChange={(event) => update({ ends_at: event.target.value })} />
            </div>
          </div>
          <small className="muted">Giờ Việt Nam (Asia/Ho_Chi_Minh).</small>
          <label htmlFor="event-location-name" style={{ display: "block", marginTop: 10 }}>Địa điểm</label>
          <input id="event-location-name" className="search-input" maxLength={200} value={draft.location_name} onChange={(event) => update({ location_name: event.target.value })} placeholder="EPIC Coffee Roastery" />
          <label htmlFor="event-location-address" style={{ display: "block", marginTop: 8 }}>Địa chỉ</label>
          <input id="event-location-address" className="search-input" maxLength={300} value={draft.location_address} onChange={(event) => update({ location_address: event.target.value })} />
          <label htmlFor="event-map-url" style={{ display: "block", marginTop: 8 }}>Liên kết bản đồ (không bắt buộc)</label>
          <input id="event-map-url" className="search-input" type="url" value={draft.map_url} onChange={(event) => update({ map_url: event.target.value })} placeholder="https://maps.google.com/…" />
        </div>

        <div className="detail-section">
          <h3>Đăng ký</h3>
          <div className="toolbar" style={{ flexWrap: "wrap" }}>
            <label><input type="checkbox" checked={draft.registration_enabled} onChange={(event) => update({ registration_enabled: event.target.checked })} /> Mở form đăng ký</label>
            <label><input type="checkbox" checked={draft.gallery_visible} onChange={(event) => update({ gallery_visible: event.target.checked })} /> Hiện gallery sau sự kiện</label>
          </div>
          <p className="warning-box" role="status" style={{ marginTop: 8 }}>
            Gallery công khai ngay khi bật, không phụ thuộc ngày kết thúc. Tắt cho tới khi có ảnh đã duyệt.
          </p>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10 }}>
            <div>
              <label htmlFor="event-capacity">Số chỗ</label>
              <input id="event-capacity" className="search-input" type="number" min={1} max={settings?.max_capacity ?? 100000} value={draft.capacity} onChange={(event) => update({ capacity: event.target.value })} placeholder="Để trống = không giới hạn" />
            </div>
            <div>
              <label htmlFor="event-closes">Đóng đăng ký lúc</label>
              <div style={{ display: "flex", gap: 6 }}>
                <input
                  id="event-closes"
                  className="search-input"
                  type="datetime-local"
                  style={{ flex: 1 }}
                  value={draft.registration_closes_at}
                  onChange={(event) => update({ registration_closes_at: event.target.value })}
                  aria-describedby="event-closes-hint"
                />
                <button
                  className="quiet-button"
                  type="button"
                  disabled={!draft.registration_closes_at}
                  onClick={() => update({ registration_closes_at: "" })}
                  title="Xoá hạn — đăng ký sẽ đóng khi sự kiện bắt đầu"
                >
                  Xoá
                </button>
              </div>
              <small className="muted" id="event-closes-hint" style={{ display: "block", marginTop: 4 }}>
                Để trống: đăng ký đóng khi sự kiện bắt đầu.
              </small>
              {closeAfterStart && (
                <small style={{ display: "block", marginTop: 4, color: "var(--danger)" }}>
                  Hạn đóng phải bằng hoặc trước giờ bắt đầu.
                </small>
              )}
            </div>
          </div>
          <div className="toolbar" style={{ marginTop: 8, flexWrap: "wrap" }}>
            <label><input type="checkbox" disabled={!String(draft.capacity).trim()} checked={draft.waitlist_enabled} onChange={(event) => update({ waitlist_enabled: event.target.checked })} /> Bật danh sách chờ khi hết chỗ</label>
            <span className="muted">{String(draft.capacity).trim() ? "" : "Danh sách chờ chỉ áp dụng khi có giới hạn số chỗ."}</span>
          </div>
        </div>

        {draft.id > 0
          ? <EventMediaUploader
              eventId={draft.id}
              revision={draft.revision}
              coverUrl={draft.cover_url}
              gallery={draft.gallery}
              maxImageBytes={settings?.max_image_bytes ?? 5242880}
              onEvent={mergeMedia}
              onError={setError}
            />
          : <div className="detail-section"><h3>Ảnh</h3><p className="muted">Lưu sự kiện dưới dạng bản nháp trước, sau đó tải thumbnail và ảnh gallery.</p></div>}

        <div className="detail-section">
          <label htmlFor="event-slug">Đường dẫn</label>
          <input id="event-slug" className="search-input" value={draft.slug} onChange={(event) => update({ slug: event.target.value })} placeholder="tu-dong-tu-ten-neu-de-trong" />
          <small className="muted">/vi/events/{draft.slug || "…"} · /en/events/{draft.slug || "…"}</small>
        </div>

        <EventQuestionBuilder questions={draft.questions} onChange={(questions) => update({ questions })} />

        {draft.id > 0 && <div className="detail-section">
          <div className="alert-row"><span>Trạng thái</span><span className="status" data-status={published ? "approved" : draft.status === "archived" ? "failed" : "pending"}>{draft.status === "published" ? "Đã xuất bản" : draft.status === "archived" ? "Đã lưu trữ" : "Bản nháp"}</span></div>
          {draft.stats && <>
            <div className="alert-row"><span>Đăng ký</span><strong>{draft.stats.taken}{draft.stats.capacity ? `/${draft.stats.capacity}` : ""} đã xác nhận · {draft.stats.waitlisted} chờ</strong></div>
            <button className="quiet-button" type="button" style={{ display: "inline-flex", alignItems: "center", gap: 6 }} onClick={() => onOpenRegistrations({ id: draft.id, title: draft.translations.vi?.title || "Sự kiện" })}>Xem người tham gia</button>
          </>}
        </div>}

        <div className="detail-actions">
          <div className="toolbar" style={{ flexWrap: "wrap" }}>
            <button className="quiet-button" type="button" disabled={busy} onClick={() => void persist()}>Lưu</button>
            <button className="action-button" type="button" disabled={busy} onClick={() => void publish()}>{published ? "Lưu & cập nhật" : "Lưu & xuất bản"}</button>
          </div>
          <div className="toolbar" style={{ flexWrap: "wrap" }}>
            {published && <button className="quiet-button" type="button" disabled={busy} onClick={() => void unpublish()}>Ngừng xuất bản</button>}
            {draft.id > 0 && draft.status !== "archived" && <button className="quiet-button" type="button" disabled={busy} onClick={() => void archive()}>Lưu trữ</button>}
            <small className="muted">Mỗi lần lưu sẽ tổng hợp lại gói nội dung cho website.</small>
          </div>
        </div>
      </Dialog.Content>
    </Dialog.Portal>
  </Dialog.Root>;
}
