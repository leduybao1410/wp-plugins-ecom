"use client";

import { apiFetch } from "@/lib/api";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useRouter, useSearchParams } from "@/lib/router";

type Item = { id: number; visitor: string; locale: string; status: "open" | "resolved"; activity_version: number; last_activity_at: string; preview: string; response_kind: string; outcome: string; unread: boolean };
type Turn = { id: number; user_text: string; response_text: string; response_kind: string; outcome: string; incomplete?: boolean; cards: Record<string, unknown> | null; started_at: string; completed_at: string | null; duration_ms: number | null };
type Note = { id: number; user_id: number; user_name: string; note_text: string; created_at: string };
type Detail = { conversation: { id: number; locale: string; status: "open" | "resolved"; activity_version: number; created_at: string; last_activity_at: string; expires_at: string }; turns: Turn[]; total: number; total_pages: number };
type Summary = { conversations: number; turns: number; outcomes: { answered: number; fallback: number; error: number; incomplete: number }; averageDurationMs: number | null };
type Health = { ready: boolean; cleanupOverdue?: boolean; cleanupScheduled?: boolean; lastCleanupAt?: string | null; reason?: string };
type ListPage = { page: number; total_pages: number; total: number };

const date = (value?: string | null) => value ? new Intl.DateTimeFormat("vi-VN", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Ho_Chi_Minh" }).format(new Date(`${value.replace(" ", "T")}Z`)) : "—";
const label: Record<string, string> = { vi: "Tiếng Việt", en: "English", ru: "Русский", hi: "हिन्दी", zh: "中文", ko: "한국어", ja: "日本語" };

async function api<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await apiFetch(`/api/dashboard/support/${path}`, { ...init, cache: "no-store", headers: { ...(init?.body ? { "Content-Type": "application/json" } : {}), ...init?.headers } });
  const data = await response.json().catch(() => ({}));
  if (response.status === 401) throw new Error("Phiên quản trị đã hết hạn. Vui lòng đăng nhập lại để xem hội thoại.");
  if (!response.ok) throw new Error(data.message || data.error || "Không thể kết nối với hộp thư hỗ trợ.");
  return data as T;
}

export function SupportConversations() {
  const router = useRouter(); const params = useSearchParams();
  const selected = Number(params.get("conversation")) || 0;
  const [items, setItems] = useState<Item[]>([]); const [detail, setDetail] = useState<Detail | null>(null); const [notes, setNotes] = useState<Note[]>([]);
  const [listPage, setListPage] = useState<ListPage>({ page: 1, total_pages: 1, total: 0 });
  const [loadedTurnPage, setLoadedTurnPage] = useState(1);
  const [summary, setSummary] = useState<Summary | null>(null); const [health, setHealth] = useState<Health | null>(null); const [error, setError] = useState(""); const [loading, setLoading] = useState(true); const [connected, setConnected] = useState(true); const [noteText, setNoteText] = useState(""); const [busy, setBusy] = useState(false); const [exporting, setExporting] = useState(false);
  const listRef = useRef<HTMLDivElement>(null); const endRef = useRef<HTMLDivElement>(null); const requestRef = useRef<AbortController | null>(null); const detailRequestRef = useRef<AbortController | null>(null);
  const readVersionRef = useRef<Record<number, number>>({});
  const filterQuery = useMemo(() => { const next = new URLSearchParams(); for (const key of ["search", "status", "locale", "unread", "from", "to", "page"]) { const value = params.get(key); if (value) next.set(key, value); } next.set("per_page", "25"); return next.toString(); }, [params]);
  const load = useCallback(async (showLoading = false) => {
    requestRef.current?.abort(); const controller = new AbortController(); requestRef.current = controller;
    if (showLoading) setLoading(true);
    try {
      const summaryQuery = new URLSearchParams();
      for (const key of ["from", "to"]) { const value = params.get(key); if (value) summaryQuery.set(key, value); }
      const [list, stats, status] = await Promise.all([
        api<{ items: Item[] } & ListPage>(`conversations?${filterQuery}`, { signal: controller.signal }),
        api<Summary>(`summary${summaryQuery.size ? `?${summaryQuery}` : ""}`, { signal: controller.signal }),
        api<Health>("health", { signal: controller.signal }),
      ]);
      setItems(list.items); setListPage({ page: list.page, total_pages: list.total_pages, total: list.total }); setSummary(stats); setHealth(status); setConnected(true); setError("");
    } catch (cause) { if (!(cause instanceof DOMException && cause.name === "AbortError")) { setConnected(false); setError(cause instanceof Error ? cause.message : "Hộp thư tạm thời không khả dụng."); } }
    finally { if (!controller.signal.aborted) setLoading(false); }
  }, [filterQuery, params]);
  const loadDetail = useCallback(async () => {
    detailRequestRef.current?.abort(); const controller = new AbortController(); detailRequestRef.current = controller;
    if (!selected) return;
    try {
      const [d, n] = await Promise.all([api<Detail>(`conversations/${selected}?per_page=50`, { signal: controller.signal }), api<{ items: Note[] }>(`conversations/${selected}/notes?per_page=50`, { signal: controller.signal })]);
      setLoadedTurnPage(1);
      setDetail(previous => {
        if (previous?.conversation.id !== selected) return d;
        const freshIds = new Set(d.turns.map(turn => turn.id));
        return { ...d, turns: [...previous.turns.filter(turn => !freshIds.has(turn.id)), ...d.turns] };
      }); setNotes(n.items);
    } catch (cause) { if (!(cause instanceof DOMException && cause.name === "AbortError")) { setDetail(null); setError(cause instanceof Error ? cause.message : "Không thể mở hội thoại."); } }
  }, [selected]);
  const loadOlder = async () => {
    if (!detail || loadedTurnPage >= detail.total_pages) return;
    const container = document.querySelector<HTMLElement>(".support-transcript");
    const beforeHeight = container?.scrollHeight ?? 0; const beforeTop = container?.scrollTop ?? 0;
    const nextPage = loadedTurnPage + 1;
    try {
      const older = await api<Detail>(`conversations/${selected}?page=${nextPage}&per_page=50`);
      setDetail(previous => previous?.conversation.id === selected ? { ...previous, turns: [...older.turns, ...previous.turns] } : previous);
      setLoadedTurnPage(nextPage);
      requestAnimationFrame(() => { if (container) container.scrollTop = beforeTop + container.scrollHeight - beforeHeight; });
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Không tải được tin nhắn cũ hơn."); }
  };
  useEffect(() => {
    if (!selected || !detail || detail.conversation.id !== selected || document.visibilityState !== "visible" || (readVersionRef.current[selected] ?? 0) >= detail.conversation.activity_version) return;
    readVersionRef.current[selected] = detail.conversation.activity_version;
    void api(`conversations/${selected}/read`, { method: "POST", body: JSON.stringify({ readVersion: detail.conversation.activity_version }) });
  }, [selected, detail]);
  useEffect(() => {
    const timer = window.setTimeout(() => { void load(); void loadDetail(); }, 0);
    return () => { window.clearTimeout(timer); requestRef.current?.abort(); detailRequestRef.current?.abort(); };
  }, [load, loadDetail]);
  useEffect(() => {
    const refresh = () => { if (document.visibilityState === "visible") { void load(); void loadDetail(); } };
    const timer = window.setInterval(refresh, 10_000); document.addEventListener("visibilitychange", refresh); window.addEventListener("focus", refresh);
    return () => { window.clearInterval(timer); document.removeEventListener("visibilitychange", refresh); window.removeEventListener("focus", refresh); };
  }, [load, loadDetail]);
  const choose = (id: number) => { setLoadedTurnPage(1); const next = new URLSearchParams(params.toString()); if (id) next.set("conversation", String(id)); else next.delete("conversation"); router.replace(`/support/conversations${next.size ? `?${next}` : ""}`, { scroll: false }); };
  const setFilter = (key: string, value: string) => { const next = new URLSearchParams(params.toString()); if (value) next.set(key, value); else next.delete(key); if (key !== "page") next.delete("page"); router.replace(`/support/conversations?${next}`, { scroll: false }); };
  const updateStatus = async () => { if (!detail) return; setBusy(true); try { await api(`conversations/${selected}`, { method: "PATCH", body: JSON.stringify({ status: detail.conversation.status === "open" ? "resolved" : "open", revision: detail.conversation.activity_version }) }); await load(); await loadDetail(); } catch (e) { setError(e instanceof Error ? e.message : "Không lưu được trạng thái."); } finally { setBusy(false); } };
  const addNote = async (event: React.FormEvent) => { event.preventDefault(); if (!noteText.trim()) return; setBusy(true); try { const key = crypto.randomUUID().replaceAll("-", ""); await api(`conversations/${selected}/notes`, { method: "POST", headers: { "Idempotency-Key": key }, body: JSON.stringify({ text: noteText, idempotencyKey: key }) }); setNoteText(""); await loadDetail(); } catch (e) { setError(e instanceof Error ? e.message : "Không lưu được ghi chú."); } finally { setBusy(false); } };
  const removeConversation = async () => { if (!detail || !window.confirm(`Xóa vĩnh viễn hội thoại ${`Khách #${selected}`} cùng toàn bộ tin nhắn và ghi chú?`)) return; setBusy(true); try { await api(`conversations/${selected}`, { method: "DELETE" }); choose(0); await load(); } catch (e) { setError(e instanceof Error ? e.message : "Không xóa được hội thoại."); } finally { setBusy(false); } };
  const downloadExport = async (format: "backup" | "training") => {
    setExporting(true);
    try {
      const query = new URLSearchParams({ format });
      for (const key of ["from", "to"]) { const value = params.get(key); if (value) query.set(key, value); }
      const response = await apiFetch(`/api/dashboard/support/export?${query}`, { cache: "no-store" });
      if (!response.ok) { const data = await response.json().catch(() => ({})); throw new Error(data.message || data.error || "Không thể tải bản xuất hội thoại."); }
      const disposition = response.headers.get("Content-Disposition") ?? "";
      const filename = disposition.match(/filename="(epic-support-(?:backup|training)-\d{4}-\d{2}-\d{2}\.(?:json|jsonl))"/)?.[1];
      if (!filename) throw new Error("Tệp xuất không hợp lệ.");
      const url = URL.createObjectURL(await response.blob());
      const anchor = document.createElement("a"); anchor.href = url; anchor.download = filename; anchor.click(); window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Không thể tải bản xuất hội thoại."); }
    finally { setExporting(false); }
  };

  return <section>
    <div className="page-heading"><div><div className="eyebrow">Chăm sóc khách hàng</div><h1>Hội thoại hỗ trợ</h1><p>Theo dõi câu hỏi, câu trả lời và các trường hợp bot chưa xử lý được. Nội dung đã được che dữ liệu nhận diện và tự xóa sau 30 ngày.</p></div><div className="support-export-actions"><button className="quiet-button" disabled={exporting} onClick={() => void downloadExport("backup")}>{exporting ? "Đang chuẩn bị…" : "Tải bản sao JSON"}</button><button className="quiet-button" disabled={exporting} onClick={() => void downloadExport("training")}>Tải dữ liệu huấn luyện JSONL</button><button className="quiet-button" onClick={() => { void load(true); void loadDetail(); }}>Làm mới</button></div></div>
    <p className="support-export-note">Bản sao gồm hội thoại đã che thông tin, không gồm ghi chú nội bộ. JSONL chỉ gồm câu trả lời FAQ và gợi ý sản phẩm đã ghi nhận thành công; hãy rà soát trước khi huấn luyện. Tệp đã tải xuống không tự xóa theo thời hạn 30 ngày của kho lưu trữ.</p>
    {error && <div className={connected ? "error-box" : "warning-box"} role="status">{connected ? error : `Mất kết nối: ${error} Dữ liệu đang hiển thị có thể đã cũ.`}</div>}
    {summary && <div className="support-stats"><div className="card"><span>Hội thoại</span><strong>{summary.conversations}</strong></div><div className="card"><span>Lượt hỗ trợ</span><strong>{summary.turns}</strong></div><div className="card"><span>Trả lời dự phòng</span><strong>{summary.outcomes.fallback}</strong></div><div className="card"><span>Lỗi / chưa hoàn tất</span><strong>{summary.outcomes.error} / {summary.outcomes.incomplete}</strong></div><div className="card"><span>Thời gian xử lý TB</span><strong>{summary.averageDurationMs == null ? "—" : `${(summary.averageDurationMs / 1000).toFixed(1)}s`}</strong></div></div>}
    {health && (!health.ready || health.cleanupOverdue) && <div className="warning-box" role="status">{!health.ready ? "Kho lưu hội thoại chưa sẵn sàng." : "Tác vụ xóa dữ liệu quá hạn chưa chạy đúng lịch."}</div>}
    <div className={`support-layout ${selected ? "has-selection" : ""}`}>
      <section className="support-list card" aria-label="Danh sách hội thoại">
        <div className="support-filters"><input className="search-input" aria-label="Tìm trong nội dung đã che thông tin" placeholder="Tìm nội dung…" value={params.get("search") ?? ""} onChange={e => setFilter("search", e.target.value)} /><select className="search-input" aria-label="Trạng thái" value={params.get("status") ?? ""} onChange={e => setFilter("status", e.target.value)}><option value="">Mọi trạng thái</option><option value="open">Đang mở</option><option value="resolved">Đã xử lý</option></select><select className="search-input" aria-label="Ngôn ngữ" value={params.get("locale") ?? ""} onChange={e => setFilter("locale", e.target.value)}><option value="">Mọi ngôn ngữ</option>{Object.entries(label).map(([k,v]) => <option key={k} value={k}>{v}</option>)}</select><select className="search-input" aria-label="Loại phản hồi" value={params.get("category") ?? ""} onChange={e => setFilter("category", e.target.value)}><option value="">Mọi loại phản hồi</option>{["faq", "order", "products", "fallback", "budget", "error", "pending"].map(x => <option key={x} value={x}>{x}</option>)}</select><input type="date" className="search-input" aria-label="Từ ngày" value={params.get("from") ?? ""} onChange={e => setFilter("from", e.target.value)} /><input type="date" className="search-input" aria-label="Đến ngày" value={params.get("to") ?? ""} onChange={e => setFilter("to", e.target.value)} /><label><input type="checkbox" checked={params.get("unread") === "true"} onChange={e => setFilter("unread", e.target.checked ? "true" : "")} /> Chưa đọc</label></div>
        <div ref={listRef} className="support-items">{loading ? <div className="empty">Đang tải hội thoại…</div> : !items.length ? <div className="empty">Chưa có hội thoại phù hợp với bộ lọc.</div> : items.map(item => <button key={item.id} className={`support-item ${selected === item.id ? "selected" : ""}`} onClick={() => choose(item.id)} aria-current={selected === item.id ? "true" : undefined}><span className="support-item-top"><strong>{item.visitor}</strong><time>{date(item.last_activity_at)}</time></span><span className="support-item-meta">{label[item.locale] ?? item.locale} · {item.status === "open" ? "Đang mở" : "Đã xử lý"} {item.unread && <b>Chưa đọc</b>}</span><span className="support-preview">{item.preview || "(Không có nội dung xem trước)"}</span><span className="support-item-meta">{item.response_kind} · {item.outcome}</span></button>)}</div>
        <footer className="support-pagination"><span>{listPage.total ? `Trang ${listPage.page}/${listPage.total_pages} · ${listPage.total} hội thoại` : "0 hội thoại"}</span><div><button className="quiet-button" disabled={listPage.page <= 1} onClick={() => setFilter("page", String(listPage.page - 1))}>Trước</button><button className="quiet-button" disabled={listPage.page >= listPage.total_pages} onClick={() => setFilter("page", String(listPage.page + 1))}>Sau</button></div></footer>
      </section>
      <section className="support-detail card" aria-label="Chi tiết hội thoại">{!selected ? <div className="empty support-select">Chọn một hội thoại để xem nội dung.</div> : !detail ? <div className="empty">Đang tải chi tiết…</div> : <>
        <header className="support-detail-head"><div><strong>Khách #{selected}</strong><span>{label[detail.conversation.locale] ?? detail.conversation.locale} · Hoạt động gần nhất {date(detail.conversation.last_activity_at)}</span><small>Phiên hết hạn {date(detail.conversation.expires_at)} · Chỉ lưu trữ nội dung đã che thông tin</small></div><div className="support-actions"><button className="quiet-button" disabled={busy} onClick={() => void updateStatus()}>{detail.conversation.status === "open" ? "Đánh dấu đã xử lý" : "Mở lại"}</button><button className="quiet-button danger-button" disabled={busy} onClick={() => void removeConversation()}>Xóa</button><button className="icon-button support-close" aria-label="Đóng chi tiết" onClick={() => choose(0)}>×</button></div></header>
        <div className="support-transcript">{loadedTurnPage < detail.total_pages && <button className="quiet-button support-older" onClick={() => void loadOlder()}>Tải tin nhắn cũ hơn</button>}{detail.turns.map(turn => { const incomplete = turn.incomplete === true; const products = turn.cards && Array.isArray(turn.cards.products) ? turn.cards.products as Array<{name:string; origin?:string; notes?:string; inStock?:boolean; packs?:Array<{weightLabel:string; priceVnd:number; inStock:boolean}>}> : []; const order = turn.cards?.orderStatus as {label?:string;description?:string}|undefined; return <article className="support-turn" key={turn.id}><div className="support-message customer"><strong>Khách · {date(turn.started_at)}</strong><p>{turn.user_text}</p></div><div className={`support-message ${turn.outcome === "answered" ? "bot" : "failure"}`}><strong>{turn.outcome === "answered" ? "Bot" : turn.outcome === "pending" ? (incomplete ? "Chưa hoàn tất" : "Đang xử lý") : "Dự phòng / lỗi"} · {turn.response_kind}</strong><p>{turn.response_text || (incomplete ? "Chưa ghi nhận bước hoàn tất sau hai phút. Điều này không xác nhận khách không nhận được câu trả lời." : "Đang xử lý yêu cầu hỗ trợ.")}</p>{products.map((p,i) => <div className="support-card-snapshot" key={`${p.name}-${i}`}><strong>{p.name}</strong><p>{p.origin}{p.notes ? ` · ${p.notes}` : ""}</p><span>{p.inStock ? "Còn hàng" : "Hết hàng"} · thông tin hiển thị tại thời điểm chat</span>{p.packs?.map(pack => <small key={pack.weightLabel}>{pack.weightLabel}: {pack.priceVnd.toLocaleString("vi-VN")}₫ · {pack.inStock ? "còn hàng" : "hết hàng"}</small>)}</div>)}{order && <p className="support-card-snapshot"><strong>{order.label}</strong><br />{order.description}</p>}<small>{turn.duration_ms == null ? "Chưa ghi nhận thời gian" : `${(turn.duration_ms / 1000).toFixed(1)} giây xử lý`}</small></div></article>; })}<div ref={endRef} /></div>
        <div className="support-notes"><h2>Ghi chú nội bộ</h2>{notes.map(note => <div className="support-note" key={note.id}><strong>{note.user_name || "Quản trị viên"} · {date(note.created_at)}</strong><p>{note.note_text}</p></div>)}<form onSubmit={addNote}><textarea className="search-input" maxLength={2000} value={noteText} onChange={e => setNoteText(e.target.value)} placeholder="Ghi chú nội bộ (tối đa 2.000 ký tự)" aria-label="Ghi chú nội bộ" /><button className="action-button" disabled={busy || !noteText.trim()}>Thêm ghi chú</button></form></div>
      </>}</section>
    </div>
    <p className="support-footnote">Bản ghi phản ánh nội dung và kết quả xử lý được hệ thống ghi nhận; không xác nhận khách đã xem, mức độ hài lòng hoặc vấn đề đã được giải quyết.</p>
  </section>;
}
