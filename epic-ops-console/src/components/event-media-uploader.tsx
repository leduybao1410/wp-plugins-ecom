"use client";

import { apiFetch } from "@/lib/api";
import { useRef, useState } from "react";
import { IMAGE_MAX_EDGE, IMAGE_QUALITY, mediaUploadPayload } from "@/lib/event-workflows.js";

export type EventMedia = {
  id: number;
  attachment_id: number;
  url: string;
  thumb_url: string;
  caption?: string;
};

type UploadResponse = { event: { id: number; revision: number; cover_media_id: number; cover_url: string; gallery: EventMedia[] }; media?: EventMedia };

type Props = {
  eventId: number;
  revision: number;
  coverUrl: string;
  gallery: EventMedia[];
  maxImageBytes: number;
  onEvent: (event: UploadResponse["event"]) => void;
  onError: (message: string) => void;
};

/** Read the file, downscale it in the browser, and return a base64 payload. */
async function toPayload(file: File): Promise<{ filename: string; mime: string; data_base64: string }> {
  const fallback = await new Promise<{ filename: string; mime: string; data_base64: string }>((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const result = String(reader.result ?? "");
      resolve({ filename: file.name, mime: file.type || "image/jpeg", data_base64: result.split(",")[1] ?? "" });
    };
    reader.onerror = () => reject(new Error("Không đọc được tệp ảnh."));
    reader.readAsDataURL(file);
  });

  try {
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, IMAGE_MAX_EDGE / Math.max(bitmap.width, bitmap.height));
    if (scale === 1 && file.size < 400_000) return fallback;
    const canvas = document.createElement("canvas");
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    const context = canvas.getContext("2d");
    if (!context) return fallback;
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close?.();
    const dataUrl = canvas.toDataURL("image/jpeg", IMAGE_QUALITY);
    const base64 = dataUrl.split(",")[1];
    if (!base64) return fallback;
    return { filename: file.name.replace(/\.[^.]+$/, "") + ".jpg", mime: "image/jpeg", data_base64: base64 };
  } catch {
    return fallback;
  }
}

export function EventMediaUploader({ eventId, revision, coverUrl, gallery, maxImageBytes, onEvent, onError }: Props) {
  const [busy, setBusy] = useState(false);
  const [kind, setKind] = useState<"cover" | "gallery">("gallery");
  const coverInput = useRef<HTMLInputElement>(null);
  const galleryInput = useRef<HTMLInputElement>(null);

  async function api<T>(path: string, init?: RequestInit): Promise<T> {
    const response = await apiFetch(`/api/dashboard/events/${eventId}${path}`, {
      ...init,
      cache: "no-store",
      headers: { ...(init?.body ? { "Content-Type": "application/json" } : {}), ...init?.headers },
    });
    const data = await response.json().catch(() => ({}));
    if (response.status === 401) throw new Error("Phiên quản trị đã hết hạn. Vui lòng đăng nhập lại.");
    if (!response.ok) throw new Error(data.message || data.error || "Không thể lưu ảnh.");
    return data as T;
  }

  async function upload(file: File, target: "cover" | "gallery") {
    setBusy(true);
    try {
      if (file.size > maxImageBytes) {
        throw new Error(`Ảnh tối đa ${Math.round(maxImageBytes / (1024 * 1024))} MB.`);
      }
      const payload = await toPayload(file);
      const data = await api<UploadResponse>("/media", {
        method: "POST",
        body: JSON.stringify(mediaUploadPayload({ ...payload, kind: target })),
      });
      onEvent(data.event);
    } catch (cause) {
      onError(cause instanceof Error ? cause.message : "Không tải được ảnh.");
    } finally {
      setBusy(false);
      if (coverInput.current) coverInput.current.value = "";
      if (galleryInput.current) galleryInput.current.value = "";
    }
  }

  async function remove(mediaId: number) {
    if (!window.confirm("Xoá ảnh này khỏi sự kiện? Tệp trong Thư viện cũng bị xoá.")) return;
    setBusy(true);
    try {
      const data = await api<{ event: UploadResponse["event"] }>(`/media/${mediaId}?revision=${revision}`, { method: "DELETE" });
      onEvent(data.event);
    } catch (cause) {
      onError(cause instanceof Error ? cause.message : "Không xoá được ảnh.");
    } finally {
      setBusy(false);
    }
  }

  async function move(index: number, direction: -1 | 1) {
    const next = [...gallery];
    const target = index + direction;
    if (target < 0 || target >= next.length) return;
    [next[index], next[target]] = [next[target], next[index]];
    setBusy(true);
    try {
      const data = await api<{ event: UploadResponse["event"] }>("/media/reorder", {
        method: "POST",
        body: JSON.stringify({ ids: next.map((item) => item.id), expected_revision: revision }),
      });
      onEvent(data.event);
    } catch (cause) {
      onError(cause instanceof Error ? cause.message : "Không sắp xếp lại được ảnh.");
    } finally {
      setBusy(false);
    }
  }

  return <>
    <div className="detail-section">
      <h3>Ảnh thumbnail</h3>
      <div style={{ display: "flex", gap: 12, alignItems: "center", flexWrap: "wrap" }}>
        {coverUrl
          ? <img src={coverUrl} alt="Thumbnail sự kiện" style={{ width: 148, height: 148, objectFit: "contain", background: "var(--alt)", borderRadius: 10, border: "1px solid var(--line)" }} />
          : <div className="muted" style={{ width: 148, height: 148, display: "grid", placeItems: "center", border: "1px dashed var(--line-strong)", borderRadius: 10, fontSize: 12, textAlign: "center", padding: 8 }}>Chưa có ảnh<br />(1:2 hoặc 1:1)</div>}
        <div style={{ display: "grid", gap: 6 }}>
          <input ref={coverInput} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={(event) => { const file = event.target.files?.[0]; if (file) void upload(file, "cover"); }} />
          <button className="quiet-button" type="button" disabled={busy} onClick={() => coverInput.current?.click()}>{coverUrl ? "Thay thumbnail" : "Tải thumbnail"}</button>
          <small className="muted">Bắt buộc trước khi xuất bản. Tỉ lệ khuyến nghị <strong>1:2 (dọc)</strong> hoặc <strong>1:1</strong> cho bài social — ảnh hiển thị trọn vẹn, không bị cắt. Nén còn tối đa {IMAGE_MAX_EDGE}px.</small>
        </div>
      </div>
    </div>

    <div className="detail-section">
      <div className="toolbar">
        <h3 style={{ margin: 0 }}>Ảnh sau sự kiện</h3>
        <span className="muted">{gallery.length} ảnh</span>
      </div>
      <input ref={galleryInput} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={(event) => { const file = event.target.files?.[0]; if (file) void upload(file, kind); }} />
      <div className="toolbar" style={{ flexWrap: "wrap" }}>
        <select className="search-input" aria-label="Loại ảnh" value={kind} onChange={(event) => setKind(event.target.value as "cover" | "gallery")}>
          <option value="gallery">Thêm vào gallery</option>
          <option value="cover">Đặt làm thumbnail</option>
        </select>
        <button className="quiet-button" type="button" disabled={busy} onClick={() => galleryInput.current?.click()}>Chọn ảnh</button>
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(120px,1fr))", gap: 10, marginTop: 10 }}>
        {gallery.map((item, index) => <figure key={item.id} style={{ margin: 0, display: "grid", gap: 5 }}>
          <img src={item.thumb_url || item.url} alt={item.caption || `Ảnh ${index + 1}`} style={{ width: "100%", height: 84, objectFit: "contain", background: "var(--alt)", borderRadius: 9, border: "1px solid var(--line)" }} />
          <div className="toolbar" style={{ gap: 4 }}>
            <button className="quiet-button" type="button" aria-label="Lên" disabled={busy || index === 0} onClick={() => void move(index, -1)} style={{ minHeight: 30, padding: "0 8px" }}>↑</button>
            <button className="quiet-button" type="button" aria-label="Xuống" disabled={busy || index === gallery.length - 1} onClick={() => void move(index, 1)} style={{ minHeight: 30, padding: "0 8px" }}>↓</button>
            <button className="quiet-button" type="button" aria-label="Xoá ảnh" disabled={busy} onClick={() => void remove(item.id)} style={{ minHeight: 30, padding: "0 8px" }}>✕</button>
          </div>
        </figure>)}
        {!gallery.length && <p className="muted" style={{ gridColumn: "1/-1", margin: 0 }}>Chưa có ảnh sau sự kiện. Ảnh chỉ hiển thị công khai khi bật “Hiện gallery sau sự kiện”.</p>}
      </div>
    </div>
  </>;
}
