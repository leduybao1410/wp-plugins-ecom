"use client";

import { apiFetch } from "@/lib/api";

import { useCallback, useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import {
  buildImportRows,
  campaignProgress,
  campaignStatusBadge,
  formatDuration,
  guessColumnMap,
  parseCsv,
  slugifyTemplate,
  statusBadge,
  TEMPLATE_CONTENT_KEYS,
  TEMPLATE_MODE_LABELS,
  TEMPLATE_STATUS_LABELS,
  validateCampaignDraft,
  validateSchedule,
  validateTemplateDraft,
} from "@/lib/mail-campaigns.js";

type Template = {
  id: number; slug: string; label: string; locales: string[]; required: string[]; vars: string[];
  subjects: Record<string, string>; sample: Record<string, string>; group: string;
  mode: "preset" | "html"; layout: string; status: "active" | "inactive"; source: string;
  content: Record<string, string>; body_html: string; in_use: number; revision: string;
};
type TemplateLayout = {
  slug: string; label: string; subjects: Record<string, string>; required: string[]; vars: string[];
  sample: Record<string, string>;
};
type TemplatePreview = { subject: string; html: string; text: string };
type TemplateForm = {
  label: string; slug: string; mode: "preset" | "html"; layout: string;
  subjects: { vi: string; en: string };
  content: { headline: string; body: string; note: string; cta_label: string; cta_url: string; last_order_hint: string };
  body_html: string; status: "active" | "inactive";
};
type Delivery = { max_per_hour: number; used_this_hour: number; remaining: number; window_resets_at: string; spacing_seconds: number };
type Campaign = {
  id: number; name: string; kind: string; source: string; template: string; locale: "vi" | "en";
  subject: string; body_text: string; default_vars: Record<string, string>; status: string;
  total_recipients: number; sent_count: number; failed_count: number; lead_count: number; pending_count: number;
  test_sent_at: string | null; scheduled_at: string | null; started_at: string | null; finished_at: string | null;
  created_at: string; revision: string;
};
type Recipient = {
  id: number; email: string; name: string; company: string; phone: string; locale: string;
  status: "pending" | "success" | "failed" | "lead"; error: string; attempts: number;
  sent_at: string | null; lead_marked_at: string | null;
};
type Form = {
  name: string; kind: "outreach" | "newsletter"; source: string; template: string; locale: "vi" | "en";
  subject: string; body_text: string; headline: string; cta_label: string; cta_url: string; note: string; last_order_hint: string;
};

const blankForm: Form = {
  name: "", kind: "outreach", source: "import", template: "", locale: "vi",
  subject: "", body_text: "", headline: "", cta_label: "", cta_url: "", note: "", last_order_hint: "",
};

const filters = [
  { value: "", label: "Tất cả" },
  { value: "pending", label: "PENDING" },
  { value: "success", label: "SUCCESS" },
  { value: "failed", label: "FAILED" },
  { value: "lead", label: "ĐÃ PHẢN HỒI" },
];

function formFromCampaign(campaign: Campaign): Form {
  return {
    name: campaign.name, kind: campaign.kind === "newsletter" ? "newsletter" : "outreach",
    source: campaign.source, template: campaign.template, locale: campaign.locale,
    subject: campaign.subject, body_text: campaign.body_text,
    headline: campaign.default_vars?.headline ?? "",
    cta_label: campaign.default_vars?.cta_label ?? "", cta_url: campaign.default_vars?.cta_url ?? "",
    note: campaign.default_vars?.note ?? "", last_order_hint: campaign.default_vars?.last_order_hint ?? "",
  };
}

const blankTemplateForm: TemplateForm = {
  label: "", slug: "", mode: "preset", layout: "",
  subjects: { vi: "", en: "" },
  content: { headline: "", body: "", note: "", cta_label: "", cta_url: "", last_order_hint: "" },
  body_html: "", status: "active",
};

function templateFormFrom(template: Template): TemplateForm {
  return {
    label: template.label, slug: template.slug, mode: template.mode, layout: template.layout,
    subjects: {
      vi: template.subjects?.vi ?? "",
      en: template.subjects?.en ?? "",
    },
    content: {
      headline: template.content?.headline ?? "",
      body: template.content?.body ?? "",
      note: template.content?.note ?? "",
      cta_label: template.content?.cta_label ?? "",
      cta_url: template.content?.cta_url ?? "",
      last_order_hint: template.content?.last_order_hint ?? "",
    },
    body_html: template.body_html ?? "",
    status: template.status === "inactive" ? "inactive" : "active",
  };
}

function payload(form: Form) {
  return {
    name: form.name, kind: form.kind, source: form.source, template: form.template,
    locale: form.locale, subject: form.subject, body_text: form.body_text,
    default_vars: {
      ...(form.headline.trim() ? { headline: form.headline.trim() } : {}),
      ...(form.cta_label.trim() ? { cta_label: form.cta_label.trim() } : {}),
      ...(form.cta_url.trim() ? { cta_url: form.cta_url.trim() } : {}),
      ...(form.note.trim() ? { note: form.note.trim() } : {}),
      ...(form.last_order_hint.trim() ? { last_order_hint: form.last_order_hint.trim() } : {}),
    },
  };
}

async function download(url: string, fallbackName: string) {
  const response = await apiFetch(url);
  await saveBlob(response, fallbackName);
}

async function saveBlob(response: Response, fallbackName: string) {
  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    throw new Error(payload.error || "Không tải được tệp");
  }
  const blob = await response.blob();
  const disposition = response.headers.get("Content-Disposition") ?? "";
  const name = disposition.match(/filename="([^"]+)"/)?.[1] ?? fallbackName;
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = name;
  link.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function MailCampaigns() {
  const [templates, setTemplates] = useState<Template[]>([]);
  const [layouts, setLayouts] = useState<TemplateLayout[]>([]);
  const [templatePanelOpen, setTemplatePanelOpen] = useState(false);
  const [editingTemplate, setEditingTemplate] = useState<Template | null>(null);
  const [templateForm, setTemplateForm] = useState<TemplateForm>(blankTemplateForm);
  const [templatePreview, setTemplatePreview] = useState<TemplatePreview | null>(null);
  const [templateBusy, setTemplateBusy] = useState(false);
  const [delivery, setDelivery] = useState<Delivery | null>(null);
  const [testTo, setTestTo] = useState("");
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Campaign | null>(null);
  const [form, setForm] = useState<Form>(blankForm);
  const [recipients, setRecipients] = useState<Recipient[]>([]);
  const [recipientTotal, setRecipientTotal] = useState(0);
  const [recipientPage, setRecipientPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState("");
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const [importText, setImportText] = useState("");
  const [importHeaders, setImportHeaders] = useState<string[]>([]);
  const [importRows, setImportRows] = useState<string[][]>([]);
  const [importMap, setImportMap] = useState<{ email: number | null; name: number; company: number; phone: number; locale: number }>({ email: null, name: -1, company: -1, phone: -1, locale: -1 });
  const [importSource, setImportSource] = useState("");
  const [scheduleAt, setScheduleAt] = useState("");
  const [confirmSend, setConfirmSend] = useState(false);
  const fileRef = useRef<HTMLInputElement | null>(null);

  const loadTemplates = useCallback(async () => {
    try {
      const response = await apiFetch("/api/dashboard/mail-campaigns/templates");
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không tải được mẫu email");
      setTemplates(payload.items ?? []);
      setLayouts(payload.layouts ?? []);
      setDelivery(payload.delivery ?? null);
      setTestTo(payload.test_to ?? "");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Không tải được mẫu email");
    }
  }, []);

  const loadCampaigns = useCallback(async (nextPage = page) => {
    setBusy(true); setError("");
    try {
      const params = new URLSearchParams({ page: String(nextPage), per_page: "20" });
      if (query.trim()) params.set("search", query.trim());
      const response = await apiFetch(`/api/dashboard/mail-campaigns?${params}`);
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không tải được chiến dịch");
      setCampaigns(payload.items ?? []);
      setTotal(payload.total ?? 0);
      setDelivery(payload.delivery ?? null);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Không tải được chiến dịch");
    } finally { setBusy(false); }
  }, [page, query]);

  const loadRecipients = useCallback(async (campaign: Campaign, nextPage = 1, status = statusFilter, term = search) => {
    try {
      const params = new URLSearchParams({ page: String(nextPage), per_page: "50" });
      if (status) params.set("status", status);
      if (term.trim()) params.set("search", term.trim());
      const response = await apiFetch(`/api/dashboard/mail-campaigns/${campaign.id}/recipients?${params}`);
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không tải được danh sách người nhận");
      setRecipients(payload.items ?? []);
      setRecipientTotal(payload.total ?? 0);
      if (payload.campaign) { setSelected(payload.campaign); setForm(formFromCampaign(payload.campaign)); }
    } catch (e) {
      setError(e instanceof Error ? e.message : "Không tải được danh sách người nhận");
    }
  }, [statusFilter, search]);

  useEffect(() => { const timer = window.setTimeout(() => { void loadTemplates(); }, 0); return () => window.clearTimeout(timer); }, [loadTemplates]);
  useEffect(() => { const timer = window.setTimeout(() => { void loadCampaigns(); }, 0); return () => window.clearTimeout(timer); }, [loadCampaigns]);

  useEffect(() => {
    if (selected?.status !== "sending") return;
    const timer = window.setInterval(() => { void loadRecipients(selected, recipientPage, statusFilter, search); }, 30_000);
    return () => window.clearInterval(timer);
  }, [selected, recipientPage, statusFilter, search, loadRecipients]);

  const canEdit = !selected || ["draft", "scheduled"].includes(selected.status);
  const progress = campaignProgress(selected ?? {}, delivery?.max_per_hour ?? 10);
  const activeTemplate = templates.find((item) => item.slug === form.template);

  function reset() {
    setSelected(null); setForm(blankForm); setRecipients([]); setRecipientTotal(0);
    setConfirmSend(false); setScheduleAt(""); setNotice(""); setError(""); setImportOpen(false);
  }

  function applyTemplate(slug: string) {
    const template = templates.find((item) => item.slug === slug);
    setForm((current) => {
      if (!template) return { ...current, template: slug };
      const subject = template.subjects?.[current.locale] ?? current.subject;
      const sample = template.sample ?? {};
      return {
        ...current,
        template: slug,
        subject: current.subject.trim() ? current.subject : subject.replace(/\{\{[a-z_]+\}\}/gi, "").trim(),
        body_text: current.body_text.trim() ? current.body_text : (sample.body ?? ""),
        headline: current.headline || sample.headline || "",
        cta_label: current.cta_label || sample.cta_label || "",
        cta_url: current.cta_url || sample.cta_url || "",
        note: current.note || sample.note || "",
        last_order_hint: current.last_order_hint || sample.last_order_hint || "",
      };
    });
  }

  function openTemplate(template: Template | null) {
    setError(""); setNotice(""); setTemplatePreview(null);
    setEditingTemplate(template);
    setTemplateForm(template ? templateFormFrom(template) : { ...blankTemplateForm, content: { ...blankTemplateForm.content }, subjects: { ...blankTemplateForm.subjects } });
  }

  function chooseLayout(slug: string) {
    const layout = layouts.find((item) => item.slug === slug);
    setTemplateForm((current) => {
      const content = { ...current.content };
      for (const key of TEMPLATE_CONTENT_KEYS) {
        if (!content[key as keyof typeof content] && layout?.sample?.[key]) content[key as keyof typeof content] = layout.sample[key];
      }
      return {
        ...current,
        layout: slug,
        subjects: {
          vi: current.subjects.vi || layout?.subjects?.vi || "",
          en: current.subjects.en || layout?.subjects?.en || "",
        },
        content,
      };
    });
  }

  async function saveTemplate(event: FormEvent) {
    event.preventDefault(); setTemplateBusy(true); setError(""); setNotice(""); setTemplatePreview(null);
    try {
      const validated = validateTemplateDraft(templateForm);
      const response = await apiFetch(editingTemplate ? `/api/dashboard/mail-campaigns/templates/${editingTemplate.id}` : "/api/dashboard/mail-campaigns/templates", {
        method: editingTemplate ? "PATCH" : "POST",
        headers: { "Content-Type": "application/json", "Idempotency-Key": crypto.randomUUID() },
        body: JSON.stringify({ ...validated, ...(editingTemplate ? { expected_revision: editingTemplate.revision } : {}) }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "Không lưu được mẫu email");
      setEditingTemplate(result.template);
      setTemplateForm(templateFormFrom(result.template));
      setNotice(editingTemplate ? "Đã lưu mẫu email." : "Đã tạo mẫu email. Mẫu xuất hiện trong danh sách chọn khi soạn chiến dịch.");
      await loadTemplates();
    } catch (e) { setError(e instanceof Error ? e.message : "Không lưu được mẫu email"); }
    finally { setTemplateBusy(false); }
  }

  async function previewTemplate() {
    setTemplateBusy(true); setError("");
    try {
      const validated = validateTemplateDraft(templateForm);
      const response = await apiFetch("/api/dashboard/mail-campaigns/templates/preview", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ...validated, locale: form.locale }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "Không xem trước được mẫu");
      setTemplatePreview(result.preview);
    } catch (e) { setError(e instanceof Error ? e.message : "Không xem trước được mẫu"); }
    finally { setTemplateBusy(false); }
  }

  async function removeTemplate() {
    if (!editingTemplate) return;
    if (!window.confirm(`Xoá mẫu “${editingTemplate.label}”? Chỉ xoá được khi chưa có chiến dịch nào dùng.`)) return;
    setTemplateBusy(true); setError(""); setNotice("");
    try {
      const response = await apiFetch(`/api/dashboard/mail-campaigns/templates/${editingTemplate.id}`, {
        method: "DELETE",
        headers: { "Idempotency-Key": crypto.randomUUID() },
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "Không xoá được mẫu email");
      setNotice("Đã xoá mẫu email.");
      openTemplate(null);
      await loadTemplates();
    } catch (e) { setError(e instanceof Error ? e.message : "Không xoá được mẫu email"); }
    finally { setTemplateBusy(false); }
  }

  async function createOrSave(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError(""); setNotice("");
    try {
      const validated = validateCampaignDraft(form);
      const body = { ...payload(form), ...validated };
      const response = await apiFetch(selected ? `/api/dashboard/mail-campaigns/${selected.id}` : "/api/dashboard/mail-campaigns", {
        method: selected ? "PATCH" : "POST",
        headers: { "Content-Type": "application/json", ...(selected ? {} : { "Idempotency-Key": crypto.randomUUID() }) },
        body: JSON.stringify({ ...body, ...(selected ? { expected_revision: selected.revision } : {}) }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "Không lưu được chiến dịch");
      setSelected(result.campaign);
      setForm(formFromCampaign(result.campaign));
      setNotice(selected ? "Đã lưu thay đổi." : "Đã tạo chiến dịch. Tiếp theo hãy nhập danh sách người nhận.");
      await loadCampaigns();
    } catch (e) { setError(e instanceof Error ? e.message : "Không lưu được chiến dịch"); }
    finally { setBusy(false); }
  }

  function toggleRecipientTone(row: Recipient) {
    return statusBadge(row.status);
  }

  async function act(action: string, payloadBody: Record<string, unknown> = {}, successMessage = "Đã cập nhật.") {
    if (!selected) return;
    setBusy(true); setError(""); setNotice("");
    try {
      const response = await apiFetch(`/api/dashboard/mail-campaigns/${selected.id}/${action}`, {
        method: "POST",
        headers: { "Content-Type": "application/json", "Idempotency-Key": crypto.randomUUID() },
        body: JSON.stringify(payloadBody),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "Thao tác không thành công");
      if (result.campaign) { setSelected(result.campaign); setForm(formFromCampaign(result.campaign)); }
      if (result.delivery) setDelivery(result.delivery);
      setNotice(result.test?.tests_remaining !== undefined ? `Đã gửi thử tới ${result.test.to}. Còn ${result.test.tests_remaining} lần thử.` : successMessage);
      setConfirmSend(false); setScheduleAt("");
      await loadCampaigns();
      if (result.campaign) await loadRecipients(result.campaign, 1);
    } catch (e) { setError(e instanceof Error ? e.message : "Thao tác không thành công"); }
    finally { setBusy(false); }
  }

  async function removeCampaign() {
    if (!selected || !window.confirm(`Xoá chiến dịch “${selected.name}”? Danh sách người nhận vẫn được giữ để tra cứu.`)) return;
    setBusy(true); setError("");
    try {
      const response = await apiFetch(`/api/dashboard/mail-campaigns/${selected.id}`, { method: "DELETE", headers: { "Idempotency-Key": crypto.randomUUID() } });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "Không xoá được chiến dịch");
      setNotice("Đã xoá chiến dịch. Danh sách người nhận vẫn được lưu.");
      reset();
      await loadCampaigns();
    } catch (e) { setError(e instanceof Error ? e.message : "Không xoá được chiến dịch"); }
    finally { setBusy(false); }
  }

  async function importRowsNow() {
    if (!selected) return;
    const built = buildImportRows({ rows: importRows, map: importMap, defaultLocale: form.locale });
    if (built.missingEmailColumn) { setError("Chọn cột chứa địa chỉ email."); return; }
    if (!built.rows.length) { setError("Không có dòng hợp lệ để nhập."); return; }
    setBusy(true); setError(""); setNotice("");
    try {
      let inserted = 0; let duplicates = 0; let invalid = built.invalid;
      for (let index = 0; index < built.rows.length; index += 500) {
        const chunk = built.rows.slice(index, index + 500);
        const response = await apiFetch(`/api/dashboard/mail-campaigns/${selected.id}/recipients`, {
          method: "POST",
          headers: { "Content-Type": "application/json", "Idempotency-Key": crypto.randomUUID() },
          body: JSON.stringify({ rows: chunk }),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Không nhập được danh sách");
        inserted += result.import?.inserted ?? 0;
        duplicates += result.import?.duplicates ?? 0;
        invalid += result.import?.invalid ?? 0;
        if (result.campaign) { setSelected(result.campaign); setForm(formFromCampaign(result.campaign)); }
      }
      setNotice(`Đã nhập ${inserted} email. Bỏ qua ${duplicates} trùng và ${invalid} dòng không hợp lệ.`);
      setImportOpen(false); setImportText(""); setImportHeaders([]); setImportRows([]); setImportSource("");
      if (fileRef.current) fileRef.current.value = "";
      await loadRecipients(selected, 1);
      await loadCampaigns();
    } catch (e) { setError(e instanceof Error ? e.message : "Không nhập được danh sách"); }
    finally { setBusy(false); }
  }

  async function readFile(file: File) {
    setError(""); setNotice("");
    try {
      if (/\.xlsx$/i.test(file.name)) {
        if (file.size > 2 * 1024 * 1024) throw new Error("Tệp Excel tối đa 2 MB.");
        const { default: readXlsxFile } = await import("read-excel-file/browser");
        const sheet = (await readXlsxFile(file)) as unknown as unknown[][];
        if (!sheet.length) throw new Error("Tệp không có dữ liệu.");
        const headers = (sheet[0] ?? []).map((cell) => String(cell ?? "").trim());
        const rows = sheet.slice(1).map((row) => (row ?? []).map((cell) => (cell instanceof Date ? cell.toISOString().slice(0, 10) : String(cell ?? "").trim())));
        setImportHeaders(headers); setImportRows(rows); setImportMap(guessColumnMap(headers)); setImportSource(file.name);
        return;
      }
      if (file.size > 2 * 1024 * 1024) throw new Error("Tệp CSV tối đa 2 MB.");
      const text = await file.text();
      const parsed = parseCsv(text);
      setImportHeaders(parsed.headers); setImportRows(parsed.rows); setImportMap(guessColumnMap(parsed.headers)); setImportSource(file.name);
    } catch (e) { setError(e instanceof Error ? e.message : "Không đọc được tệp"); }
  }

  function parsePasted() {
    try {
      setError("");
      const parsed = parseCsv(importText, ",");
      if (parsed.headers.length === 1 && parsed.headers[0] && !parsed.rows.length) {
        const rows = importText.split(/\r?\n/).map((line) => [line.trim()]).filter((row) => row[0]);
        setImportHeaders(["email"]); setImportRows(rows); setImportMap({ email: 0, name: -1, company: -1, phone: -1, locale: -1 }); setImportSource("dán tay");
        return;
      }
      setImportHeaders(parsed.headers); setImportRows(parsed.rows); setImportMap(guessColumnMap(parsed.headers)); setImportSource("dán tay");
    } catch (e) { setError(e instanceof Error ? e.message : "Không đọc được nội dung dán"); }
  }

  async function sendNow() {
    if (!selected || !confirmSend) { setError("Xác nhận đã rà soát trước khi gửi."); return; }
    let scheduled: string | null = null;
    try { scheduled = scheduleAt ? validateSchedule(scheduleAt) : null; } catch (e) { setError(e instanceof Error ? e.message : "Giờ hẹn không hợp lệ"); return; }
    if (!window.confirm(scheduled ? `Hẹn gửi “${selected.name}” lúc ${scheduled.replace("T", " ")}?` : `Bắt đầu gửi “${selected.name}” tới ${selected.pending_count.toLocaleString("vi-VN")} người nhận?`)) return;
    await act("send", { confirm_send: true, ...(scheduled ? { scheduled_at: scheduled } : {}) }, scheduled ? "Đã hẹn giờ gửi." : "Chiến dịch đã vào hàng đợi gửi nền.");
  }

  const preview = buildImportRows({ rows: importRows, map: importMap, defaultLocale: form.locale });

  return <>
    <div className="page-heading">
      <div><div className="eyebrow">EPIC / Tiếp thị</div><h1>Chiến dịch email</h1><p>Nhập danh sách, gửi theo mẫu đã duyệt và theo dõi từng địa chỉ: PENDING, SUCCESS, FAILED và ĐÃ PHẢN HỒI.</p></div>
      <div className="toolbar">
        <button className="quiet-button" type="button" onClick={() => { const next = !templatePanelOpen; setTemplatePanelOpen(next); if (next) openTemplate(null); }}>{templatePanelOpen ? "Đóng mẫu email" : "Mẫu email"}</button>
        <button className="quiet-button" type="button" onClick={reset}>Tạo chiến dịch</button>
      </div>
    </div>
    {notice && <div className="success-box" role="status">{notice}</div>}
    {error && <div className="error-box" role="alert">{error}</div>}
    {delivery && <p className="muted">Hạn mức gửi chiến dịch: {delivery.max_per_hour} mail/giờ · còn {delivery.remaining} trong giờ này · cách nhau {Math.round(delivery.spacing_seconds / 60)} phút mỗi thư.</p>}

    {templatePanelOpen && <section className="card" style={{ marginBottom: 16 }}>
      <div className="toolbar" style={{ flexWrap: "wrap" }}>
        <h2 style={{ margin: 0 }}>Mẫu email chiến dịch</h2>
        <span className="muted">{templates.length} mẫu · preset dùng bố cục đã duyệt, HTML chỉ thay khối thân trong khung EPIC</span>
        <button className="quiet-button" type="button" disabled={templateBusy} onClick={() => openTemplate(null)}>Tạo mẫu</button>
        <button className="quiet-button" type="button" onClick={() => setTemplatePanelOpen(false)}>Đóng</button>
      </div>
      <div className="summary-grid">
        <section className="section-card">
          <div className="toolbar"><h3 style={{ margin: 0 }}>Danh sách mẫu</h3><span className="muted">Bấm để sửa</span></div>
          {templates.map((template) => <button key={template.id} type="button" className="alert-row campaign-row" onClick={() => openTemplate(template)} aria-current={editingTemplate?.id === template.id ? "true" : undefined}>
            <span>
              <strong>{template.label}</strong>
              <small style={{ display: "block", color: "var(--muted)", marginTop: 4 }}>
                {template.slug} · {TEMPLATE_MODE_LABELS[template.mode]} · {TEMPLATE_STATUS_LABELS[template.status]} · {template.in_use} chiến dịch · {template.source === "builtin" ? "hệ thống" : "tự tạo"}
              </small>
            </span>
            <span aria-hidden="true">→</span>
          </button>)}
          {!templates.length && <p className="empty">Chưa có mẫu email.</p>}
        </section>

        <section className="section-card">
          <h3 style={{ marginTop: 0 }}>{editingTemplate ? `Sửa mẫu #${editingTemplate.id}` : "Tạo mẫu email"}</h3>
          <form onSubmit={(event) => void saveTemplate(event)} style={{ display: "grid", gap: 10 }}>
            <label htmlFor="template-label">Tên mẫu</label>
            <input id="template-label" className="search-input" value={templateForm.label} disabled={templateBusy} maxLength={200} onChange={(event) => setTemplateForm({ ...templateForm, label: event.target.value })} required />
            <label htmlFor="template-slug">Mã mẫu (slug)</label>
            <input id="template-slug" className="search-input" value={templateForm.slug} disabled={templateBusy || Boolean(editingTemplate && editingTemplate.in_use > 0)} onChange={(event) => setTemplateForm({ ...templateForm, slug: event.target.value })} placeholder={slugifyTemplate(templateForm.label)} />
            {editingTemplate && editingTemplate.in_use > 0 && <small className="muted">Mẫu đang được {editingTemplate.in_use} chiến dịch dùng nên không đổi được mã.</small>}
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10 }}>
              <div><label htmlFor="template-mode">Kiểu mẫu</label><select id="template-mode" className="search-input" value={templateForm.mode} disabled={templateBusy} onChange={(event) => setTemplateForm({ ...templateForm, mode: event.target.value as TemplateForm["mode"], layout: event.target.value === "html" ? "" : templateForm.layout })}><option value="preset">Bố cục có sẵn</option><option value="html">HTML nâng cao</option></select></div>
              <div><label htmlFor="template-status">Trạng thái</label><select id="template-status" className="search-input" value={templateForm.status} disabled={templateBusy} onChange={(event) => setTemplateForm({ ...templateForm, status: event.target.value as TemplateForm["status"] })}><option value="active">Đang dùng</option><option value="inactive">Tạm ẩn (không hiện khi chọn)</option></select></div>
            </div>
            {templateForm.mode === "preset" && <>
              <label htmlFor="template-layout">Bố cục đã duyệt</label>
              <select id="template-layout" className="search-input" value={templateForm.layout} disabled={templateBusy} onChange={(event) => chooseLayout(event.target.value)} required>
                <option value="">Chọn bố cục…</option>
                {layouts.map((layout) => <option key={layout.slug} value={layout.slug}>{layout.label} ({layout.slug})</option>)}
              </select>
              <small className="muted">Bố cục quyết định khung HTML; mẫu chỉ cung cấp tiêu đề và nội dung mặc định.</small>
            </>}
            {templateForm.mode === "html" && <>
              <label htmlFor="template-html">Khối HTML thân thư</label>
              <textarea id="template-html" className="search-input" style={{ minHeight: 180, padding: 10, fontFamily: "monospace" }} value={templateForm.body_html} disabled={templateBusy} onChange={(event) => setTemplateForm({ ...templateForm, body_html: event.target.value })} placeholder={'<p style="margin:0 0 18px">Kính gửi {{name}},</p>\n<p style="margin:0 0 18px">{{body}}</p>'} required />
              <small className="muted">Nội dung được lọc bằng wp_kses_post và luôn nằm trong khung EPIC. Dùng placeholder: {"{{name}}, {{company}}, {{headline}}, {{body}}, {{cta_label}}, {{cta_url}}, {{note}}, {{last_order_hint}}"}.</small>
            </>}
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10 }}>
              <div><label htmlFor="template-subject-vi">Tiêu đề mặc định (VI)</label><input id="template-subject-vi" className="search-input" value={templateForm.subjects.vi} disabled={templateBusy} maxLength={255} onChange={(event) => setTemplateForm({ ...templateForm, subjects: { ...templateForm.subjects, vi: event.target.value } })} /></div>
              <div><label htmlFor="template-subject-en">Tiêu đề mặc định (EN)</label><input id="template-subject-en" className="search-input" value={templateForm.subjects.en} disabled={templateBusy} maxLength={255} onChange={(event) => setTemplateForm({ ...templateForm, subjects: { ...templateForm.subjects, en: event.target.value } })} /></div>
            </div>
            <label htmlFor="template-headline">Tiêu đề lớn mặc định</label>
            <input id="template-headline" className="search-input" value={templateForm.content.headline} disabled={templateBusy} onChange={(event) => setTemplateForm({ ...templateForm, content: { ...templateForm.content, headline: event.target.value } })} />
            <label htmlFor="template-body">Nội dung mặc định (văn bản thuần)</label>
            <textarea id="template-body" className="search-input" style={{ minHeight: 100, padding: 10 }} value={templateForm.content.body} disabled={templateBusy} onChange={(event) => setTemplateForm({ ...templateForm, content: { ...templateForm.content, body: event.target.value } })} />
            <label htmlFor="template-note">Ghi chú mặc định</label>
            <input id="template-note" className="search-input" value={templateForm.content.note} disabled={templateBusy} onChange={(event) => setTemplateForm({ ...templateForm, content: { ...templateForm.content, note: event.target.value } })} />
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10 }}>
              <div><label htmlFor="template-cta-label">Nhãn nút</label><input id="template-cta-label" className="search-input" value={templateForm.content.cta_label} disabled={templateBusy} onChange={(event) => setTemplateForm({ ...templateForm, content: { ...templateForm.content, cta_label: event.target.value } })} /></div>
              <div><label htmlFor="template-cta-url">Liên kết nút</label><input id="template-cta-url" className="search-input" value={templateForm.content.cta_url} disabled={templateBusy} placeholder="https://…" onChange={(event) => setTemplateForm({ ...templateForm, content: { ...templateForm.content, cta_url: event.target.value } })} /></div>
            </div>
            <label htmlFor="template-hint">Gợi ý đơn trước (mẫu nhắc mua lại)</label>
            <input id="template-hint" className="search-input" value={templateForm.content.last_order_hint} disabled={templateBusy} onChange={(event) => setTemplateForm({ ...templateForm, content: { ...templateForm.content, last_order_hint: event.target.value } })} />
            <div className="toolbar" style={{ flexWrap: "wrap" }}>
              <button className="action-button" type="submit" disabled={templateBusy}>{editingTemplate ? "Lưu mẫu" : "Tạo mẫu"}</button>
              <button className="quiet-button" type="button" disabled={templateBusy} onClick={() => void previewTemplate()}>Xem trước</button>
              {editingTemplate && <button className="quiet-button danger-button" type="button" disabled={templateBusy} onClick={() => void removeTemplate()}>Xoá mẫu</button>}
              <button className="quiet-button" type="button" disabled={templateBusy} onClick={() => openTemplate(null)}>Mẫu mới</button>
            </div>
          </form>
          {templatePreview && <div style={{ marginTop: 16, borderTop: "1px solid var(--line)", paddingTop: 16 }}>
            <div className="alert-row"><span>Xem trước ({form.locale === "en" ? "EN" : "VI"})</span><strong>{templatePreview.subject}</strong></div>
            <iframe title="Xem trước mẫu email" srcDoc={templatePreview.html} sandbox="" style={{ width: "100%", height: 520, border: "1px solid var(--line)", background: "#fff", marginTop: 10 }} />
          </div>}
        </section>
      </div>
    </section>}

    <div className="summary-grid">
      <section className="card section-card">
        <div className="toolbar"><h2 style={{ margin: 0 }}>Chiến dịch đã lưu</h2><span className="muted">{total.toLocaleString("vi-VN")} chiến dịch</span></div>
        <form className="toolbar" onSubmit={(event) => { event.preventDefault(); setQuery(search); setPage(1); }}>
          <label className="sr-only" htmlFor="campaign-search">Tìm chiến dịch</label>
          <input id="campaign-search" className="search-input" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Tìm theo tên…" />
          <button className="quiet-button" type="submit" disabled={busy}>Tìm</button>
        </form>
        {campaigns.map((campaign) => {
          const badge = campaignStatusBadge(campaign.status);
          return <button key={campaign.id} type="button" className="alert-row campaign-row" onClick={() => { setSelected(campaign); setForm(formFromCampaign(campaign)); setRecipientPage(1); setStatusFilter(""); setSearch(""); setConfirmSend(false); setScheduleAt(""); setNotice(""); void loadRecipients(campaign, 1, "", ""); }}>
            <span><strong>{campaign.name}</strong><small style={{ display: "block", color: "var(--muted)", marginTop: 4 }}>{badge.label} · {campaign.sent_count}/{campaign.total_recipients} đã gửi · {campaign.failed_count} lỗi · {campaign.lead_count} phản hồi</small></span>
            <span aria-hidden="true">→</span>
          </button>;
        })}
        {!campaigns.length && <p className="empty">Chưa có chiến dịch email.</p>}
        <div className="toolbar">
          <button className="quiet-button" type="button" disabled={page <= 1 || busy} onClick={() => { const next = page - 1; setPage(next); void loadCampaigns(next); }}>Trước</button>
          <span className="muted">Trang {page}</span>
          <button className="quiet-button" type="button" disabled={busy || page * 20 >= total} onClick={() => { const next = page + 1; setPage(next); void loadCampaigns(next); }}>Tiếp</button>
        </div>
      </section>

      <section className="card section-card">
        <h2>{selected ? `Chiến dịch #${selected.id}` : "Soạn chiến dịch"}</h2>
        {selected && <div className="alert-row"><span>Trạng thái</span><span className="status" data-status={campaignStatusBadge(selected.status).tone}>{campaignStatusBadge(selected.status).label}</span></div>}

        <form onSubmit={(event) => void createOrSave(event)} style={{ display: "grid", gap: 10 }}>
          <label htmlFor="campaign-name">Tên chiến dịch</label>
          <input id="campaign-name" className="search-input" value={form.name} disabled={!canEdit} onChange={(event) => setForm({ ...form, name: event.target.value })} required />
          <label htmlFor="campaign-template">Mẫu email đã duyệt</label>
          <select id="campaign-template" className="search-input" value={form.template} disabled={!canEdit} onChange={(event) => applyTemplate(event.target.value)} required>
            <option value="">Chọn mẫu…</option>
            {templates.filter((template) => template.status !== "inactive" || template.slug === form.template).map((template) => <option key={template.slug} value={template.slug}>{template.label}{template.status === "inactive" ? " · tạm ẩn" : ""}</option>)}
          </select>
          <small className="muted">Mẫu do EPIC quản lý trong mục <strong>Mẫu email</strong> ở đầu trang.</small>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10 }}>
            <div><label htmlFor="campaign-kind">Loại</label><select id="campaign-kind" className="search-input" value={form.kind} disabled={!canEdit} onChange={(event) => setForm({ ...form, kind: event.target.value as Form["kind"] })}><option value="outreach">Outreach (nhập tay)</option><option value="newsletter">Bản tin (opt-in)</option></select></div>
            <div><label htmlFor="campaign-locale">Ngôn ngữ</label><select id="campaign-locale" className="search-input" value={form.locale} disabled={!canEdit} onChange={(event) => setForm({ ...form, locale: event.target.value as Form["locale"] })}><option value="vi">Tiếng Việt</option><option value="en">English</option></select></div>
          </div>
          <label htmlFor="campaign-subject">Tiêu đề email</label>
          <input id="campaign-subject" className="search-input" value={form.subject} disabled={!canEdit} maxLength={255} onChange={(event) => setForm({ ...form, subject: event.target.value })} required />
          <label htmlFor="campaign-body">Nội dung (văn bản thuần)</label>
          <textarea id="campaign-body" className="search-input" style={{ minHeight: 150, padding: 10 }} value={form.body_text} disabled={!canEdit} onChange={(event) => setForm({ ...form, body_text: event.target.value })} required />
          <small className="muted">Chỉ nhận văn bản. Mẫu email quyết định khung và định dạng; HTML tự do không được chấp nhận.</small>
          <label htmlFor="campaign-headline">Tiêu đề lớn trong thư (không bắt buộc)</label>
          <input id="campaign-headline" className="search-input" value={form.headline} disabled={!canEdit} onChange={(event) => setForm({ ...form, headline: event.target.value })} placeholder="Một nguồn cà phê ổn định cho toàn bộ khách sạn." />
          <label htmlFor="campaign-note">Ghi chú trong khung (không bắt buộc)</label>
          <input id="campaign-note" className="search-input" value={form.note} disabled={!canEdit} onChange={(event) => setForm({ ...form, note: event.target.value })} placeholder="Áp dụng cho đơn đặt trước ngày 30/11." />
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10 }}>
            <div><label htmlFor="campaign-cta-label">Nhãn nút</label><input id="campaign-cta-label" className="search-input" value={form.cta_label} disabled={!canEdit} onChange={(event) => setForm({ ...form, cta_label: event.target.value })} placeholder="Xem bảng giá" /></div>
            <div><label htmlFor="campaign-cta-url">Liên kết nút</label><input id="campaign-cta-url" className="search-input" value={form.cta_url} disabled={!canEdit} onChange={(event) => setForm({ ...form, cta_url: event.target.value })} placeholder="https://…" /></div>
          </div>
          {form.template === "campaign-reorder" && <><label htmlFor="campaign-hint">Gợi ý đơn trước</label><input id="campaign-hint" className="search-input" value={form.last_order_hint} disabled={!canEdit} onChange={(event) => setForm({ ...form, last_order_hint: event.target.value })} placeholder="Lần giao trước: 20kg House Blend." /></>}
          {activeTemplate && <small className="muted">Biến mẫu: {(activeTemplate.vars ?? []).join(", ") || "—"}</small>}
          {canEdit && <button className="action-button" type="submit" disabled={busy}>{selected ? "Lưu thay đổi" : "Tạo chiến dịch"}</button>}
        </form>

        {selected && <div style={{ display: "grid", gap: 10, marginTop: 18, borderTop: "1px solid var(--line)", paddingTop: 16 }}>
          <div className="alert-row"><span>Tiến độ</span><strong>{progress.done}/{progress.total} · còn {formatDuration(progress.minutes)}</strong></div>
          {selected.status === "sending" && <div className="alert-row"><span>Đang gửi nền</span><span className="muted">Còn {selected.pending_count} người nhận. Trang tự cập nhật mỗi 30 giây.</span></div>}
          {selected.status === "scheduled" && <div className="alert-row"><span>Hẹn bắt đầu</span><strong>{selected.scheduled_at} UTC</strong></div>}
          {!selected.test_sent_at && <div className="warning-box" role="status">Bắt buộc gửi thử trước khi bắt đầu. Mặc định tới {testTo || "email quản trị"}.</div>}
          <div className="toolbar" style={{ flexWrap: "wrap" }}>
            <button className="quiet-button" type="button" disabled={busy} onClick={() => void act("test", { to: testTo }, "Đã gửi thử.")}>Gửi thử</button>
            <button className="quiet-button" type="button" disabled={busy || !["draft", "scheduled"].includes(selected.status)} onClick={() => { setImportOpen((value) => !value); setError(""); }}>Nhập danh sách</button>
            <button className="quiet-button" type="button" disabled={busy || !selected.total_recipients} onClick={() => void download(`/api/dashboard/mail-campaigns/${selected.id}/export${statusFilter ? `?status=${statusFilter}` : ""}`, `epic-mail-campaign-${selected.id}.csv`).catch((e) => setError(e instanceof Error ? e.message : "Không xuất được tệp"))}>Xuất CSV</button>
            <button className="quiet-button" type="button" disabled={busy} onClick={() => void download("/api/dashboard/mail-campaigns/sample", "epic-mail-campaign-sample.csv").catch((e) => setError(e instanceof Error ? e.message : "Không tải được tệp mẫu"))}>Tải file mẫu</button>
            {["sending", "scheduled"].includes(selected.status) && <button className="quiet-button" type="button" disabled={busy} onClick={() => void act("pause", {}, "Đã tạm dừng sau thư hiện tại.")}>Tạm dừng</button>}
            {selected.status === "paused" && <button className="quiet-button" type="button" disabled={busy} onClick={() => void act("resume", {}, "Đã tiếp tục gửi.")}>Tiếp tục</button>}
            {selected.failed_count > 0 && <button className="quiet-button" type="button" disabled={busy} onClick={() => void act("retry-failed", {}, "Đã đưa các dòng lỗi trở lại hàng đợi.")}>Gửi lại lỗi</button>}
            {!["done", "cancelled"].includes(selected.status) && <button className="quiet-button" type="button" disabled={busy} onClick={() => { if (window.confirm("Huỷ chiến dịch? Các dòng chưa gửi sẽ dừng lại.")) void act("cancel", {}, "Đã huỷ chiến dịch."); }}>Huỷ</button>}
            <button className="quiet-button danger-button" type="button" disabled={busy || selected.status === "sending"} onClick={() => void removeCampaign()}>Xoá</button>
          </div>
          {["draft", "scheduled", "paused"].includes(selected.status) && <div style={{ display: "grid", gap: 8 }}>
            <label style={{ display: "flex", gap: 8, alignItems: "flex-start" }}><input type="checkbox" checked={confirmSend} onChange={(event) => setConfirmSend(event.target.checked)} /><span>Tôi đã xem lại mẫu, tiêu đề, nội dung và danh sách {selected.total_recipients.toLocaleString("vi-VN")} người nhận.</span></label>
            <label htmlFor="campaign-schedule">Hẹn giờ bắt đầu (để trống = gửi ngay)</label>
            <input id="campaign-schedule" type="datetime-local" className="search-input" value={scheduleAt} onChange={(event) => setScheduleAt(event.target.value)} />
            <button className="action-button" type="button" disabled={busy || !confirmSend || !selected.test_sent_at} onClick={() => void sendNow()}>{scheduleAt ? "Hẹn gửi chiến dịch" : "Bắt đầu gửi"}</button>
            <small className="muted">Mỗi lần gửi 1 email, cách nhau {Math.round((delivery?.spacing_seconds ?? 360) / 60)} phút. Có thể tạm dừng bất cứ lúc nào.</small>
          </div>}
        </div>}

        {selected && importOpen && <div style={{ display: "grid", gap: 10, marginTop: 18, borderTop: "1px solid var(--line)", paddingTop: 16 }}>
          <h3 style={{ margin: 0 }}>Nhập danh sách</h3>
          <label htmlFor="campaign-file">Tệp CSV hoặc Excel (.xlsx, tối đa 2 MB)</label>
          <input id="campaign-file" ref={fileRef} type="file" accept=".csv,text/csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onChange={(event) => { const file = event.target.files?.[0]; if (file) void readFile(file); }} />
          <label htmlFor="campaign-paste">Hoặc dán danh sách email (mỗi dòng một địa chỉ)</label>
          <textarea id="campaign-paste" className="search-input" style={{ minHeight: 90, padding: 10 }} value={importText} onChange={(event) => setImportText(event.target.value)} placeholder={"email@example.com\nkhach@example.com"} />
          <div className="toolbar"><button className="quiet-button" type="button" disabled={!importText.trim()} onClick={parsePasted}>Đọc nội dung dán</button>{importSource && <span className="muted">Nguồn: {importSource} · {importRows.length} dòng</span>}</div>
          {importHeaders.length > 0 && <div style={{ display: "grid", gap: 8 }}>
            <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit,minmax(180px,1fr))", gap: 10 }}>
              {([["email", "Email (bắt buộc)"], ["name", "Tên"], ["company", "Công ty / khách sạn"], ["phone", "Điện thoại"], ["locale", "Ngôn ngữ"]] as const).map(([key, label]) => (
                <div key={key}><label htmlFor={`map-${key}`}>{label}</label><select id={`map-${key}`} className="search-input" value={importMap[key] ?? -1} onChange={(event) => setImportMap({ ...importMap, [key]: Number(event.target.value) })}><option value={-1}>—</option>{importHeaders.map((header, index) => <option key={`${header}-${index}`} value={index}>{header || `Cột ${index + 1}`}</option>)}</select></div>
              ))}
            </div>
            <div className="alert-row"><span>Xem trước</span><strong>{preview.rows.length} hợp lệ · {preview.duplicates} trùng trong tệp · {preview.invalid} không hợp lệ</strong></div>
            {preview.missingEmailColumn && <div className="error-box" role="alert">Chọn cột chứa địa chỉ email.</div>}
            <div className="toolbar"><button className="action-button" type="button" disabled={busy || preview.missingEmailColumn || !preview.rows.length} onClick={() => void importRowsNow()}>Nhập {preview.rows.length} email</button><button className="quiet-button" type="button" onClick={() => { setImportOpen(false); setImportHeaders([]); setImportRows([]); setImportText(""); setImportSource(""); }}>Đóng</button></div>
          </div>}
        </div>}
      </section>
    </div>

    {selected && <section className="card" style={{ marginTop: 16 }}>
      <div className="toolbar" style={{ flexWrap: "wrap" }}>
        <h2 style={{ margin: 0 }}>Người nhận</h2>
        <span className="muted">{recipientTotal.toLocaleString("vi-VN")} dòng</span>
        <form className="toolbar" onSubmit={(event) => { event.preventDefault(); setRecipientPage(1); void loadRecipients(selected, 1); }}>
          <input className="search-input" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Tìm email…" aria-label="Tìm email trong chiến dịch" />
          <button className="quiet-button" type="submit">Tìm</button>
        </form>
      </div>
      <div role="tablist" aria-label="Lọc theo trạng thái" style={{ display: "flex", gap: 6, marginBottom: 12, overflowX: "auto" }}>
        {filters.map((filter) => <button key={filter.value || "all"} type="button" role="tab" aria-selected={statusFilter === filter.value} className={statusFilter === filter.value ? "action-button" : "quiet-button"} onClick={() => { setStatusFilter(filter.value); setRecipientPage(1); void loadRecipients(selected, 1, filter.value); }}>{filter.label}</button>)}
      </div>
      <div className="table-wrap">
        <table>
          <thead><tr><th>Email</th><th>Tên / công ty</th><th>Ngôn ngữ</th><th>Trạng thái</th><th>Lỗi</th><th>Đã gửi lúc</th><th /></tr></thead>
          <tbody>
            {recipients.map((row) => {
              const badge = toggleRecipientTone(row);
              return <tr key={row.id}>
                <td>{row.email}</td>
                <td>{[row.name, row.company].filter(Boolean).join(" · ") || "—"}</td>
                <td>{row.locale || selected.locale}</td>
                <td><span className="status" data-status={badge.tone}>{badge.label}</span></td>
                <td style={{ maxWidth: 240 }}>{row.error || "—"}</td>
                <td>{row.sent_at ? `${row.sent_at} UTC` : "—"}</td>
                <td style={{ whiteSpace: "nowrap" }}>
                  {row.status === "success" && <button className="quiet-button" type="button" disabled={busy} onClick={async () => { setBusy(true); try { const response = await apiFetch(`/api/dashboard/mail-campaigns/${selected.id}/recipients/${row.id}`, { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ status: "lead" }) }); const result = await response.json(); if (!response.ok) throw new Error(result.error || "Không cập nhật được"); setSelected(result.campaign); await loadRecipients(selected, recipientPage); } catch (e) { setError(e instanceof Error ? e.message : "Không cập nhật được"); } finally { setBusy(false); } }}>Đánh dấu đã phản hồi</button>}
                  {row.status === "lead" && <button className="quiet-button" type="button" disabled={busy} onClick={async () => { setBusy(true); try { const response = await apiFetch(`/api/dashboard/mail-campaigns/${selected.id}/recipients/${row.id}`, { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ status: "success" }) }); const result = await response.json(); if (!response.ok) throw new Error(result.error || "Không cập nhật được"); setSelected(result.campaign); await loadRecipients(selected, recipientPage); } catch (e) { setError(e instanceof Error ? e.message : "Không cập nhật được"); } finally { setBusy(false); } }}>Bỏ đánh dấu</button>}
                  {row.status === "failed" && <button className="quiet-button" type="button" disabled={busy} onClick={async () => { setBusy(true); try { const response = await apiFetch(`/api/dashboard/mail-campaigns/${selected.id}/recipients/${row.id}`, { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: "retry" }) }); const result = await response.json(); if (!response.ok) throw new Error(result.error || "Không cập nhật được"); setSelected(result.campaign); setNotice("Đã đưa dòng lỗi trở lại hàng đợi."); await loadRecipients(selected, recipientPage); } catch (e) { setError(e instanceof Error ? e.message : "Không cập nhật được"); } finally { setBusy(false); } }}>Gửi lại</button>}
                </td>
              </tr>;
            })}
            {!recipients.length && <tr><td colSpan={7} className="empty">{selected.total_recipients ? "Không có người nhận khớp bộ lọc." : "Chưa nhập người nhận. Dùng “Nhập danh sách” để bắt đầu."}</td></tr>}
          </tbody>
        </table>
      </div>
      <div className="toolbar">
        <button className="quiet-button" type="button" disabled={recipientPage <= 1 || busy} onClick={() => { const next = recipientPage - 1; setRecipientPage(next); void loadRecipients(selected, next); }}>Trước</button>
        <span className="muted">Trang {recipientPage}</span>
        <button className="quiet-button" type="button" disabled={busy || recipientPage * 50 >= recipientTotal} onClick={() => { const next = recipientPage + 1; setRecipientPage(next); void loadRecipients(selected, next); }}>Tiếp</button>
      </div>
    </section>}
  </>;
}
