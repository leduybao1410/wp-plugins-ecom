"use client";

import { useCallback, useEffect, useState } from "react";
import * as Dialog from "@radix-ui/react-dialog";
import { apiFetch } from "@/lib/api";
import {
  FAQ_GROUPS,
  FAQ_GROUP_LABELS,
  FAQ_LOCALES,
  FAQ_LOCALE_LABELS,
  applyTranslations,
  faqBundleSummary,
  faqCompleteness,
  faqDisplayTitle,
  faqDraftFromRecord,
  faqListQuery,
  faqSavePayload,
  faqTranslationSource,
  validateFaqDraft,
} from "@/lib/faq-workflows.js";

type LocaleEntry = { question: string; answer: string; updated_at?: string };
type FaqDraft = {
  id: number;
  group: string;
  position: number;
  site_visible: boolean;
  bot_visible: boolean;
  revision: number;
  status: string;
  missing_locales: string[];
  translations: Record<string, LocaleEntry>;
};
type FaqListItem = {
  id: number;
  group: string;
  position: number;
  status: "draft" | "published";
  site_visible: boolean;
  bot_visible: boolean;
  revision: number;
  source: string;
  question: string;
  answer: string;
  filled_locales: string[];
  missing_locales: string[];
  updated_at: string;
  published_at: string | null;
};
type FaqRecord = FaqListItem & { translations: Record<string, LocaleEntry> };
type Settings = { locales: string[]; groups: string[]; max_bytes: number; max_items: number; last_compiled: string | null };
type ListResponse = { items: FaqListItem[]; total: number; page: number; per_page: number; total_pages: number; settings: Settings };
type BundleItem = { id: number; group: string; question: string; answer: string; site: boolean; bot: boolean };
type Bundle = {
  version: string;
  lang: string;
  generated_at: string | null;
  groups: { id: string; count: number }[];
  items: BundleItem[];
  item_count: number;
  bytes: number;
  truncated: boolean;
  max_items: number;
  max_bytes: number;
};
type ImportPreview = {
  total: number;
  complete: number;
  incomplete: number;
  installed: number;
  groups: { group: string; total: number; complete: number }[];
  generated_at: string | null;
};

async function api<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await apiFetch(`/api/dashboard/faqs${path}`, {
    ...init,
    cache: "no-store",
    headers: { ...(init?.body ? { "Content-Type": "application/json" } : {}), ...init?.headers },
  });
  const data = await response.json().catch(() => ({}));
  if (response.status === 401) throw new Error("Phiên quản trị đã hết hạn. Vui lòng đăng nhập lại.");
  if (!response.ok) throw new Error(data.message || data.error || "Không thể kết nối với kho FAQ.");
  return data as T;
}

const date = (value?: string | null) => value
  ? new Intl.DateTimeFormat("vi-VN", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Ho_Chi_Minh" }).format(new Date(`${value.replace(" ", "T")}Z`))
  : "—";

export function FaqManager() {
  const [items, setItems] = useState<FaqListItem[]>([]);
  const [total, setTotal] = useState(0);
  const [totalPages, setTotalPages] = useState(1);
  const [settings, setSettings] = useState<Settings | null>(null);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState("");
  const [group, setGroup] = useState("");
  const [lang, setLang] = useState("vi");
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [draft, setDraft] = useState<FaqDraft | null>(null);
  const [snapshot, setSnapshot] = useState("");
  const [editorError, setEditorError] = useState("");
  const [editorTab, setEditorTab] = useState("vi");
  const [translating, setTranslating] = useState(false);
  const [bundleLang, setBundleLang] = useState("vi");
  const [bundle, setBundle] = useState<Bundle | null>(null);
  const [bundleError, setBundleError] = useState("");
  const [importPreview, setImportPreview] = useState<ImportPreview | null>(null);
  const [importError, setImportError] = useState("");

  const load = useCallback(async (nextPage: number) => {
    setLoading(true);
    try {
      const data = await api<ListResponse>(`?${faqListQuery({ status, group, lang, search, page: nextPage, per_page: 25 })}`);
      setItems(data.items ?? []);
      setTotal(data.total ?? 0);
      setTotalPages(data.total_pages ?? 1);
      setSettings(data.settings ?? null);
      setPage(data.page ?? nextPage);
      setError("");
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Không tải được danh sách FAQ.");
    } finally {
      setLoading(false);
    }
  }, [group, lang, search, status]);

  const loadBundle = useCallback(async (nextLang: string) => {
    try {
      const data = await api<{ bundle: Bundle; settings: Settings }>(`/bundle?lang=${encodeURIComponent(nextLang)}`);
      setBundle(data.bundle);
      setSettings((current) => data.settings ?? current);
      setBundleError("");
    } catch (cause) {
      setBundleError(cause instanceof Error ? cause.message : "Không tải được gói ngữ cảnh.");
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => { void load(1); }, 0);
    return () => window.clearTimeout(timer);
    // Filters are the only inputs that reset pagination; `load` is stable per filter set.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, group, lang, search]);
  useEffect(() => {
    const timer = window.setTimeout(() => { void loadBundle(bundleLang); }, 0);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [bundleLang]);

  function openEditor(record?: FaqListItem) {
    setEditorError("");
    setEditorTab("vi");
    const next = faqDraftFromRecord(record);
    setDraft(next);
    setSnapshot(JSON.stringify(faqSavePayload(next)));
  }

  function closeEditor() {
    if (draft && snapshot && JSON.stringify(faqSavePayload(draft)) !== snapshot && !window.confirm("Bỏ thay đổi chưa lưu?")) return;
    setDraft(null);
  }

  async function openExisting(id: number) {
    setEditorError("");
    try {
      const data = await api<{ faq: FaqRecord }>(`/${id}`);
      setEditorTab("vi");
      const next = faqDraftFromRecord(data.faq);
      setDraft(next);
      setSnapshot(JSON.stringify(faqSavePayload(next)));
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Không tải được FAQ.");
    }
  }

  function updateTranslation(locale: string, field: "question" | "answer", value: string) {
    setDraft((current) => current ? {
      ...current,
      translations: { ...current.translations, [locale]: { ...current.translations[locale], [field]: value } },
    } : current);
  }

  async function persist(target: FaqDraft): Promise<FaqRecord | null> {
    const errors = validateFaqDraft(target);
    if (errors.length) { setEditorError(errors.join(" ")); return null; }
    setBusy(true);
    setEditorError("");
    try {
      const body = faqSavePayload(target);
      const data = target.id
        ? await api<{ faq: FaqRecord }>(`/${target.id}`, { method: "PATCH", body: JSON.stringify(body) })
        : await api<{ faq: FaqRecord }>("", { method: "POST", body: JSON.stringify(body) });
      setDraft(faqDraftFromRecord(data.faq));
      setSnapshot(JSON.stringify(faqSavePayload(faqDraftFromRecord(data.faq))));
      setNotice("Đã lưu FAQ.");
      await load(page);
      return data.faq;
    } catch (cause) {
      setEditorError(cause instanceof Error ? cause.message : "Không lưu được FAQ.");
      return null;
    } finally {
      setBusy(false);
    }
  }

  async function saveDraft() {
    if (!draft) return null;
    return persist(draft);
  }

  async function autoTranslate() {
    if (!draft) return;
    const source = faqTranslationSource(draft);
    if (!source) { setEditorError("Nhập câu hỏi và câu trả lời tiếng Việt trước khi dịch."); return; }
    setTranslating(true);
    setEditorError("");
    try {
      const data = await api<{ translations: Record<string, { question?: unknown; answer?: unknown }> }>("/translate", {
        method: "POST",
        body: JSON.stringify(source),
      });
      const hasExisting = Object.keys(data.translations ?? {}).some((locale) => {
        const entry = draft.translations?.[locale];
        return Boolean(entry?.question?.trim() || entry?.answer?.trim());
      });
      const overwrite = hasExisting ? window.confirm("Một số ngôn ngữ đã có nội dung. Ghi đè bằng bản dịch mới?") : false;
      const result = applyTranslations(draft, data.translations ?? {}, { overwrite });
      setDraft(result.draft);
      setNotice(`Đã dịch ${result.applied.length} ngôn ngữ${result.skipped.length ? `, giữ nguyên ${result.skipped.length} ngôn ngữ đã có nội dung` : ""}. Hãy rà soát trước khi lưu và xuất bản.`);
    } catch (cause) {
      setEditorError(cause instanceof Error ? cause.message : "Không dịch được FAQ. Kiểm tra cấu hình webhook dịch thuật.");
    } finally {
      setTranslating(false);
    }
  }

  async function publishDraft() {
    if (!draft) return;
    const errors = validateFaqDraft(draft, { publishing: true });
    if (errors.length) { setEditorError(errors.join(" ")); return; }
    const saved = await persist(draft);
    if (!saved) return;
    setBusy(true);
    try {
      await api(`/${saved.id}/publish`, { method: "POST", body: JSON.stringify({ revision: saved.revision }) });
      setNotice("Đã xuất bản FAQ và tổng hợp lại gói ngữ cảnh.");
      await load(page);
      await loadBundle(bundleLang);
      await openExisting(saved.id);
    } catch (cause) {
      setEditorError(cause instanceof Error ? cause.message : "Không xuất bản được FAQ.");
    } finally {
      setBusy(false);
    }
  }

  async function unpublishDraft() {
    if (!draft || !draft.id) return;
    setBusy(true);
    try {
      await api(`/${draft.id}/unpublish`, { method: "POST", body: JSON.stringify({ revision: draft.revision }) });
      setNotice("FAQ đã ngừng xuất bản và gói ngữ cảnh đã được cập nhật.");
      await load(page);
      await loadBundle(bundleLang);
      await openExisting(draft.id);
    } catch (cause) {
      setEditorError(cause instanceof Error ? cause.message : "Không ngừng xuất bản được FAQ.");
    } finally {
      setBusy(false);
    }
  }

  async function deleteDraft() {
    if (!draft?.id) return;
    if (!window.confirm("Xoá vĩnh viễn FAQ này? Thao tác không thể hoàn tác.")) return;
    setBusy(true);
    try {
      await api(`/${draft.id}?revision=${draft.revision}`, { method: "DELETE" });
      setDraft(null);
      setNotice("Đã xoá FAQ.");
      await load(page);
      await loadBundle(bundleLang);
    } catch (cause) {
      setEditorError(cause instanceof Error ? cause.message : "Không xoá được FAQ.");
    } finally {
      setBusy(false);
    }
  }

  async function rebuild() {
    setBusy(true);
    try {
      await api("/rebuild", { method: "POST" });
      setNotice("Đã tổng hợp lại gói ngữ cảnh cho cả 7 ngôn ngữ.");
      await loadBundle(bundleLang);
      await load(page);
    } catch (cause) {
      setBundleError(cause instanceof Error ? cause.message : "Không tổng hợp lại được.");
    } finally {
      setBusy(false);
    }
  }

  async function previewImport() {
    try {
      setImportPreview(await api<ImportPreview>("/import-preview"));
      setImportError("");
    } catch (cause) {
      setImportError(cause instanceof Error ? cause.message : "Không đọc được bộ FAQ mẫu.");
    }
  }

  async function runImport() {
    if (!window.confirm("Nhập bộ FAQ hiện có của website? FAQ đã có sẽ được giữ nguyên.")) return;
    setBusy(true);
    try {
      const result = await api<{ created: number; updated: number; skipped: number; published: number; incomplete: number }>("/import", { method: "POST", body: JSON.stringify({ overwrite: false }) });
      setNotice(`Đã nhập ${result.created} FAQ mới, xuất bản ${result.published}, còn ${result.incomplete} FAQ cần dịch đủ 7 ngôn ngữ.`);
      setImportPreview(null);
      await load(page);
      await loadBundle(bundleLang);
    } catch (cause) {
      setImportError(cause instanceof Error ? cause.message : "Không nhập được bộ FAQ mẫu.");
    } finally {
      setBusy(false);
    }
  }

  const completeness = draft ? faqCompleteness(draft) : { filled: [], missing: FAQ_LOCALES };
  const bundleSummary = faqBundleSummary(bundle);
  const published = items.filter((item) => item.status === "published").length;
  const incomplete = items.filter((item) => item.status === "published" && item.missing_locales.length > 0).length;

  return <>
    <div className="page-heading">
      <div><div className="eyebrow">EPIC / Hỗ trợ</div><h1>FAQ</h1><p>Câu hỏi thường gặp cho website và trợ lý chat. Mỗi lần xuất bản sẽ tổng hợp lại gói ngữ cảnh cho cả 7 ngôn ngữ.</p></div>
      <button className="quiet-button" type="button" onClick={() => openEditor()}>Tạo FAQ</button>
    </div>
    {notice && <div className="success-box" role="status">{notice}</div>}
    {error && <div className="error-box" role="alert">{error}</div>}

    <div className="summary-grid">
      <section className="card section-card">
        <div className="toolbar"><h2 style={{ margin: 0 }}>Kho FAQ</h2><span className="muted">{total.toLocaleString("vi-VN")} FAQ</span></div>
        <form className="toolbar" onSubmit={(event) => { event.preventDefault(); void load(1); }}>
          <input className="search-input" aria-label="Tìm FAQ" placeholder="Tìm câu hỏi hoặc câu trả lời…" value={search} onChange={(event) => setSearch(event.target.value)} />
          <button className="quiet-button" type="submit" disabled={loading}>Tìm</button>
        </form>
        <div className="toolbar" style={{ flexWrap: "wrap" }}>
          <select className="search-input" aria-label="Trạng thái" value={status} onChange={(event) => setStatus(event.target.value)}><option value="">Mọi trạng thái</option><option value="published">Đã xuất bản</option><option value="draft">Bản nháp</option></select>
          <select className="search-input" aria-label="Nhóm" value={group} onChange={(event) => setGroup(event.target.value)}><option value="">Mọi nhóm</option>{FAQ_GROUPS.map((id) => <option key={id} value={id}>{FAQ_GROUP_LABELS[id as keyof typeof FAQ_GROUP_LABELS]}</option>)}</select>
          <select className="search-input" aria-label="Ngôn ngữ xem" value={lang} onChange={(event) => setLang(event.target.value)}>{FAQ_LOCALES.map((id) => <option key={id} value={id}>{FAQ_LOCALE_LABELS[id as keyof typeof FAQ_LOCALE_LABELS]}</option>)}</select>
        </div>
        <div className="table-wrap">
          <table>
            <thead><tr><th>Câu hỏi</th><th>Nhóm</th><th>Ngôn ngữ</th><th>Trạng thái</th><th>Cập nhật</th></tr></thead>
            <tbody>
              {items.map((item) => <tr key={item.id}>
                <td><button type="button" style={{ background: "none", border: 0, padding: 0, font: "inherit", color: "inherit", textAlign: "left", cursor: "pointer", textDecoration: "underline" }} onClick={() => void openExisting(item.id)}>{faqDisplayTitle(item)}</button></td>
                <td>{FAQ_GROUP_LABELS[item.group as keyof typeof FAQ_GROUP_LABELS] ?? item.group}</td>
                <td><span className="muted">{item.filled_locales.length}/7</span></td>
                <td><span className="status" data-status={item.status === "published" ? "approved" : "pending"}>{item.status === "published" ? "Đã xuất bản" : "Bản nháp"}</span></td>
                <td>{date(item.updated_at)}</td>
              </tr>)}
              {!items.length && <tr><td colSpan={5} className="empty">{loading ? "Đang tải…" : "Chưa có FAQ. Tạo mới hoặc nhập bộ FAQ hiện có của website."}</td></tr>}
            </tbody>
          </table>
        </div>
        <div className="toolbar">
          <button className="quiet-button" type="button" disabled={loading || page <= 1} onClick={() => void load(page - 1)}>Trước</button>
          <span className="muted">Trang {page}/{totalPages} · {published}/{items.length} đã xuất bản trong trang</span>
          <button className="quiet-button" type="button" disabled={loading || page >= totalPages} onClick={() => void load(page + 1)}>Tiếp</button>
        </div>
        {incomplete > 0 && <p className="warning-box" role="status">{incomplete} FAQ đang xuất bản nhưng thiếu ngôn ngữ. Chúng không vào gói ngữ cảnh cho tới khi dịch đủ.</p>}
      </section>

      <section className="card section-card">
        <div className="toolbar"><h2 style={{ margin: 0 }}>Gói ngữ cảnh cho bot</h2><span className="status" data-status={bundleSummary.tone as string}>{bundleSummary.label}</span></div>
        <div className="toolbar">
          <select className="search-input" aria-label="Ngôn ngữ gói" value={bundleLang} onChange={(event) => setBundleLang(event.target.value)}>{FAQ_LOCALES.map((id) => <option key={id} value={id}>{FAQ_LOCALE_LABELS[id as keyof typeof FAQ_LOCALE_LABELS]}</option>)}</select>
          <button className="quiet-button" type="button" disabled={busy} onClick={() => void loadBundle(bundleLang)}>Tải lại</button>
          <button className="quiet-button" type="button" disabled={busy} onClick={() => void rebuild()}>Tổng hợp lại</button>
        </div>
        {bundleError && <div className="error-box" role="alert">{bundleError}</div>}
        {bundle && <>
          <div className="alert-row"><span>Phiên bản</span><strong>{bundle.version || "—"}</strong></div>
          <div className="alert-row"><span>Tổng hợp lúc</span><span>{date(bundle.generated_at)}</span></div>
          <div className="alert-row"><span>Giới hạn</span><span>{bundle.max_items} FAQ · {bundle.max_bytes.toLocaleString("vi-VN")} byte</span></div>
          <div className="alert-row"><span>Nhóm có mặt</span><span>{bundle.groups.map((entry) => `${FAQ_GROUP_LABELS[entry.id as keyof typeof FAQ_GROUP_LABELS] ?? entry.id} (${entry.count})`).join(" · ") || "—"}</span></div>
          {bundle.truncated && <p className="warning-box" role="status">Gói đã bị cắt bớt để vừa ngân sách prompt. Giảm số FAQ hoặc rút gọn câu trả lời.</p>}
          <details style={{ marginTop: 12 }}>
            <summary>Xem {bundle.item_count} FAQ bot nhận</summary>
            <ol style={{ marginTop: 10, paddingLeft: 18 }}>{bundle.items.map((item) => <li key={item.id} style={{ marginBottom: 6 }}><strong>{item.question}</strong>{!item.bot && <span className="muted"> · chỉ hiện trên trang</span>}</li>)}</ol>
          </details>
        </>}
        {settings && <p className="muted">Gói gồm FAQ đã xuất bản. FAQ chỉ hiện trên trang vẫn vào gói nhưng bot bỏ qua.</p>}
      </section>
    </div>

    <section className="card section-card" style={{ marginTop: 16 }}>
      <div className="toolbar"><h2 style={{ margin: 0 }}>Nhập bộ FAQ hiện có</h2><button className="quiet-button" type="button" disabled={busy} onClick={() => void previewImport()}>Kiểm tra bộ mẫu</button></div>
      <p className="muted">Bộ mẫu lấy từ 7 tệp ngôn ngữ của website. FAQ đủ 7 ngôn ngữ được xuất bản tự động; FAQ thiếu ngôn ngữ ở lại bản nháp.</p>
      {importError && <div className="error-box" role="alert">{importError}</div>}
      {importPreview && <>
        <div className="alert-row"><span>Tổng số</span><strong>{importPreview.total} FAQ ({importPreview.complete} đủ 7 ngôn ngữ)</strong></div>
        <div className="alert-row"><span>Đã có trong kho</span><strong>{importPreview.installed}</strong></div>
        <div className="alert-row"><span>Thiếu ngôn ngữ</span><strong>{importPreview.incomplete}</strong></div>
        <div className="alert-row" style={{ flexWrap: "wrap" }}>{importPreview.groups.map((entry) => <span key={entry.group} className="muted">{FAQ_GROUP_LABELS[entry.group as keyof typeof FAQ_GROUP_LABELS] ?? entry.group}: {entry.complete}/{entry.total}</span>)}</div>
        <button className="action-button" type="button" disabled={busy} onClick={() => void runImport()}>Nhập FAQ</button>
      </>}
      {settings && <small className="muted">Tổng hợp lần cuối: {date(settings.last_compiled)}</small>}
    </section>

    {draft && <Dialog.Root open onOpenChange={(open) => { if (!open) closeEditor(); }}>
      <Dialog.Portal>
        <Dialog.Overlay className="detail-backdrop" />
        <Dialog.Content className="detail-panel" aria-describedby={undefined}>
          <div className="detail-header">
            <div><div className="eyebrow">FAQ / {FAQ_GROUP_LABELS[draft.group as keyof typeof FAQ_GROUP_LABELS] ?? draft.group}</div><h2>{draft.id ? `FAQ #${draft.id}` : "FAQ mới"}</h2></div>
            <Dialog.Close asChild><button className="quiet-button" type="button">Đóng</button></Dialog.Close>
          </div>
          {editorError && <div className="error-box" role="alert">{editorError}</div>}
          <div className="detail-section">
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10 }}>
              <div><label htmlFor="faq-group">Nhóm</label><select id="faq-group" className="search-input" value={draft.group} onChange={(event) => setDraft({ ...draft, group: event.target.value })}>{FAQ_GROUPS.map((id) => <option key={id} value={id}>{FAQ_GROUP_LABELS[id as keyof typeof FAQ_GROUP_LABELS]}</option>)}</select></div>
              <div><label htmlFor="faq-position">Thứ tự trong nhóm</label><input id="faq-position" className="search-input" type="number" min={0} value={draft.position} onChange={(event) => setDraft({ ...draft, position: Number(event.target.value) || 0 })} /></div>
            </div>
            <div className="toolbar" style={{ marginTop: 10 }}>
              <label><input type="checkbox" checked={draft.site_visible} onChange={(event) => setDraft({ ...draft, site_visible: event.target.checked })} /> Hiện trên trang FAQ</label>
              <label><input type="checkbox" checked={draft.bot_visible} onChange={(event) => setDraft({ ...draft, bot_visible: event.target.checked })} /> Dùng cho trợ lý chat</label>
            </div>
          </div>
          <div className="detail-section">
            <div className="toolbar" style={{ flexWrap: "wrap" }} role="tablist" aria-label="Ngôn ngữ">
              {FAQ_LOCALES.map((locale) => <button key={locale} type="button" role="tab" aria-selected={editorTab === locale} className={editorTab === locale ? "action-button" : "quiet-button"} onClick={() => setEditorTab(locale)}>{FAQ_LOCALE_LABELS[locale as keyof typeof FAQ_LOCALE_LABELS]}{completeness.filled.includes(locale) ? " ✓" : ""}</button>)}
              <button className="quiet-button" type="button" disabled={busy || translating || !faqTranslationSource(draft)} onClick={() => void autoTranslate()}>{translating ? "Đang dịch…" : "Dịch tự động từ tiếng Việt"}</button>
            </div>
            <p className="muted">{completeness.filled.length}/7 ngôn ngữ đã đủ. Bắt buộc dịch đủ 7 ngôn ngữ trước khi xuất bản.</p>
            <label htmlFor="faq-question">Câu hỏi ({FAQ_LOCALE_LABELS[editorTab as keyof typeof FAQ_LOCALE_LABELS]})</label>
            <input id="faq-question" className="search-input" maxLength={300} value={draft.translations[editorTab]?.question ?? ""} onChange={(event) => updateTranslation(editorTab, "question", event.target.value)} />
            <label htmlFor="faq-answer" style={{ marginTop: 8, display: "block" }}>Câu trả lời</label>
            <textarea id="faq-answer" className="search-input" maxLength={2000} style={{ minHeight: 130, padding: 10 }} value={draft.translations[editorTab]?.answer ?? ""} onChange={(event) => updateTranslation(editorTab, "answer", event.target.value)} />
          </div>
          <div className="detail-section">
            <div className="alert-row"><span>Trạng thái</span><span className="status" data-status={draft.status === "published" ? "approved" : "pending"}>{draft.status === "published" ? "Đã xuất bản" : "Bản nháp"}</span></div>
          </div>
          <div className="detail-actions">
            <div className="toolbar">
              <button className="quiet-button" type="button" disabled={busy} onClick={() => void saveDraft()}>Lưu</button>
              <button className="action-button" type="button" disabled={busy} onClick={() => void publishDraft()}>Lưu &amp; xuất bản</button>
            </div>
            <div className="toolbar">
              {draft.status === "published" && <button className="quiet-button" type="button" disabled={busy} onClick={() => void unpublishDraft()}>Ngừng xuất bản</button>}
              {draft.id > 0 && <button className="quiet-button" type="button" disabled={busy} onClick={() => void deleteDraft()}>Xoá</button>}
              <small className="muted">Mỗi lần lưu/xuất bản sẽ tổng hợp lại gói ngữ cảnh.</small>
            </div>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>}
  </>;
}
