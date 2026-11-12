/**
 * Pure helpers for the Sự kiện module: draft mapping, validation, payloads and
 * display formatting. Kept free of React and `fetch` so the same rules run in
 * the dashboard, in `/ops` and in the unit tests.
 *
 * Time handling: the dashboard edits site-local time (`Asia/Ho_Chi_Minh`) in a
 * `datetime-local` input. Every request sends an explicit `+07:00` offset so
 * WordPress never has to guess which timezone the administrator meant.
 */

export const EVENT_LOCALES = ["vi", "en"];
export const EVENT_LOCALE_LABELS = { vi: "Tiếng Việt", en: "English" };
export const EVENT_TIMEZONE = "Asia/Ho_Chi_Minh";
export const EVENT_TIMEZONE_OFFSET = "+07:00";

export const QUESTION_TYPES = ["short_text", "long_text", "single_choice", "multi_choice"];
export const QUESTION_TYPE_LABELS = {
  short_text: "Trả lời ngắn",
  long_text: "Trả lời dài",
  single_choice: "Chọn 1 đáp án",
  multi_choice: "Chọn nhiều đáp án",
};

export const EVENT_STATUSES = ["draft", "published", "archived"];
export const EVENT_STATUS_LABELS = { draft: "Bản nháp", published: "Đã xuất bản", archived: "Đã lưu trữ" };

export const REGISTRATION_STATUSES = ["confirmed", "waitlisted", "cancelled", "attended", "no_show"];
export const REGISTRATION_STATUS_LABELS = {
  confirmed: "Đã xác nhận",
  waitlisted: "Danh sách chờ",
  cancelled: "Đã huỷ",
  attended: "Đã tham dự",
  no_show: "Không đến",
};

export const MAX_QUESTIONS = 20;
export const MAX_OPTIONS = 20;
export const MAX_CAPACITY = 100000;

/** `2026-11-05T18:00:00Z` -> `2026-11-05T01:00` (site-local, for the input). */
export function toLocalInput(iso) {
  if (!iso) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: EVENT_TIMEZONE,
    year: "numeric", month: "2-digit", day: "2-digit",
    hour: "2-digit", minute: "2-digit", hour12: false,
  }).formatToParts(date);
  const get = (type) => parts.find((part) => part.type === type)?.value ?? "";
  const hour = get("hour") === "24" ? "00" : get("hour");
  return `${get("year")}-${get("month")}-${get("day")}T${hour}:${get("minute")}`;
}

/** `2026-11-05T18:00` (site-local) -> `2026-11-05T18:00:00+07:00`. */
export function toOffsetIso(local) {
  const value = String(local ?? "").trim();
  if (!value) return "";
  if (/[zZ]$|[+-]\d{2}:?\d{2}$/.test(value)) return value;
  const normalised = value.length === 16 ? `${value}:00` : value;
  return `${normalised}${EVENT_TIMEZONE_OFFSET}`;
}

export function emptyTranslation() {
  return { title: "", summary: "", description: "" };
}

export function emptyEventDraft() {
  return {
    id: 0,
    slug: "",
    status: "draft",
    starts_at: "",
    ends_at: "",
    location_name: "",
    location_address: "",
    map_url: "",
    cover_media_id: 0,
    cover_url: "",
    gallery_visible: false,
    capacity: "",
    waitlist_enabled: false,
    registration_enabled: true,
    registration_closes_at: "",
    revision: 0,
    translations: { vi: emptyTranslation(), en: emptyTranslation() },
    questions: [],
    gallery: [],
    stats: null,
    missing_locales: EVENT_LOCALES.slice(),
  };
}

export function eventDraftFromRecord(record) {
  const base = emptyEventDraft();
  if (!record) return base;
  const translations = {};
  for (const locale of EVENT_LOCALES) {
    const source = record.translations?.[locale] ?? {};
    translations[locale] = {
      title: source.title ?? "",
      summary: source.summary ?? "",
      description: source.description ?? "",
    };
  }
  return {
    ...base,
    id: Number(record.id ?? 0),
    slug: record.slug ?? "",
    status: record.status ?? "draft",
    starts_at: toLocalInput(record.starts_at),
    ends_at: toLocalInput(record.ends_at),
    location_name: record.location_name ?? "",
    location_address: record.location_address ?? "",
    map_url: record.map_url ?? "",
    cover_media_id: Number(record.cover_media_id ?? 0),
    cover_url: record.cover_url ?? "",
    gallery_visible: Boolean(record.gallery_visible),
    capacity: record.capacity === null || record.capacity === undefined ? "" : String(record.capacity),
    waitlist_enabled: Boolean(record.waitlist_enabled),
    registration_enabled: record.registration_enabled !== false,
    registration_closes_at: toLocalInput(record.registration_closes_at),
    revision: Number(record.revision ?? 0),
    translations,
    questions: Array.isArray(record.questions) ? record.questions.map(questionDraftFrom) : [],
    gallery: Array.isArray(record.gallery) ? record.gallery : [],
    stats: record.stats ?? null,
    missing_locales: Array.isArray(record.missing_locales) ? record.missing_locales : EVENT_LOCALES.slice(),
  };
}

/** Payload for `POST /events` and `PATCH /events/{id}`. */
export function eventSavePayload(draft) {
  const translations = {};
  for (const locale of EVENT_LOCALES) {
    const row = draft.translations?.[locale] ?? emptyTranslation();
    translations[locale] = {
      title: String(row.title ?? "").trim(),
      summary: String(row.summary ?? "").trim(),
      description: normaliseDescriptionHtml(row.description),
    };
  }
  const capacity = String(draft.capacity ?? "").trim();
  const hasCapacity = capacity !== "" && Number.isFinite(Number(capacity)) && Number(capacity) >= 1;
  return {
    expected_revision: Number(draft.revision ?? 0),
    slug: String(draft.slug ?? "").trim(),
    starts_at: toOffsetIso(draft.starts_at),
    ends_at: toOffsetIso(draft.ends_at),
    location_name: String(draft.location_name ?? "").trim(),
    location_address: String(draft.location_address ?? "").trim(),
    map_url: String(draft.map_url ?? "").trim(),
    cover_media_id: Number(draft.cover_media_id ?? 0),
    gallery_visible: Boolean(draft.gallery_visible),
    capacity: hasCapacity ? Math.trunc(Number(capacity)) : null,
    waitlist_enabled: hasCapacity && Boolean(draft.waitlist_enabled),
    registration_enabled: Boolean(draft.registration_enabled),
    registration_closes_at: toOffsetIso(draft.registration_closes_at),
    translations,
  };
}

/**
 * Description input accepts either HTML or plain text. Plain text (no tags) is
 * escaped and split into paragraphs so a copy-pasted social post renders as
 * real paragraphs instead of one blob; anything that already contains a tag is
 * passed through untouched and sanitised server-side with wp_kses_post.
 */
export function normaliseDescriptionHtml(value) {
  const text = String(value ?? "");
  if (!text.trim()) return "";
  if (/<[a-z][^>]*>/i.test(text)) return text;
  const escaped = text
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
  return escaped
    .split(/\n{2,}/)
    .map((block) => {
      const lines = block.split("\n").map((line) => line.trim()).filter(Boolean);
      return lines.length ? `<p>${lines.join("<br>")}</p>` : "";
    })
    .filter(Boolean)
    .join("");
}

/**
 * Preview-only scrub for unsaved input: `<script>`, inline `on*` handlers and
 * `javascript:` URLs are dropped so the preview cannot execute anything before
 * the server sanitises the saved value.
 */
export function sanitizePreviewHtml(value) {
  return String(value ?? "")
    .replace(/<script\b[\s\S]*?<\/script>/gi, "")
    .replace(/\son[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, "")
    .replace(/(href|src)\s*=\s*("|')\s*javascript:[^"']*\2/gi, '$1="#"');
}

export function eventCompleteness(draft) {
  const filled = [];
  const missing = [];
  for (const locale of EVENT_LOCALES) {
    const row = draft?.translations?.[locale] ?? {};
    const complete = Boolean(String(row.title ?? "").trim() && String(row.summary ?? "").trim() && String(row.description ?? "").trim());
    (complete ? filled : missing).push(locale);
  }
  return { filled, missing };
}

/** @returns {string[]} human-readable problems; empty means valid. */
export function validateEventDraft(draft, options = {}) {
  const errors = [];
  const { filled, missing } = eventCompleteness(draft);
  if (!filled.length) errors.push("Điền tên, mô tả ngắn và mô tả cho ít nhất một ngôn ngữ.");
  const start = toOffsetIso(draft.starts_at);
  const end = toOffsetIso(draft.ends_at);
  if (!start) errors.push("Chọn thời gian bắt đầu.");
  if (!end) errors.push("Chọn thời gian kết thúc.");
  if (start && end && new Date(end) <= new Date(start)) errors.push("Thời gian kết thúc phải sau thời gian bắt đầu.");
  const closes = toOffsetIso(draft.registration_closes_at);
  if (closes && start && new Date(closes) > new Date(start)) errors.push("Hạn đóng đăng ký phải bằng hoặc trước giờ bắt đầu.");
  const capacity = String(draft.capacity ?? "").trim();
  if (capacity !== "") {
    const value = Number(capacity);
    if (!Number.isFinite(value) || value < 1 || value > MAX_CAPACITY) errors.push(`Sức chứa phải là số từ 1 đến ${MAX_CAPACITY}, hoặc để trống nếu không giới hạn.`);
  }
  if (options.publishing) {
    if (missing.length) errors.push(`Dịch đủ ${EVENT_LOCALES.length} ngôn ngữ trước khi xuất bản (còn thiếu: ${missing.join(", ")}).`);
    if (!Number(draft.cover_media_id)) errors.push("Tải lên ảnh thumbnail trước khi xuất bản.");
  }
  if (draft.questions?.length > MAX_QUESTIONS) errors.push(`Tối đa ${MAX_QUESTIONS} câu hỏi.`);
  errors.push(...validateQuestions(draft.questions ?? []));
  return Array.from(new Set(errors));
}

export function emptyQuestion(type = "short_text") {
  return {
    id: 0,
    type,
    required: false,
    min_choices: 0,
    max_choices: 0,
    translations: {
      vi: { label: "", help_text: "", options: [] },
      en: { label: "", help_text: "", options: [] },
    },
  };
}

export function questionDraftFrom(question) {
  const base = emptyQuestion(question?.type ?? "short_text");
  if (!question) return base;
  const translations = {};
  for (const locale of EVENT_LOCALES) {
    const row = question.translations?.[locale] ?? {};
    translations[locale] = {
      label: row.label ?? question.label ?? "",
      help_text: row.help_text ?? question.help_text ?? "",
      options: (row.options ?? question.options ?? []).map((option) => ({ value: option.value ?? "", label: option.label ?? "" })),
    };
  }
  return {
    ...base,
    id: Number(question.id ?? 0),
    type: question.type ?? "short_text",
    required: Boolean(question.required),
    min_choices: Number(question.min_choices ?? 0),
    max_choices: Number(question.max_choices ?? 0),
    translations,
  };
}

export function isChoiceQuestion(type) {
  return type === "single_choice" || type === "multi_choice";
}

export function validateQuestions(questions) {
  const errors = [];
  (questions ?? []).forEach((question, index) => {
    const label = `${index + 1}`;
    const viLabel = String(question.translations?.vi?.label ?? "").trim();
    const enLabel = String(question.translations?.en?.label ?? "").trim();
    if (!viLabel && !enLabel) { errors.push(`Câu hỏi ${label}: nhập nội dung câu hỏi.`); return; }
    if (!isChoiceQuestion(question.type)) return;
    const options = (question.translations?.vi?.options ?? question.translations?.en?.options ?? []).filter((option) => String(option.label ?? "").trim());
    if (options.length < 2) { errors.push(`Câu hỏi ${label}: cần ít nhất 2 lựa chọn.`); return; }
    if (options.length > MAX_OPTIONS) { errors.push(`Câu hỏi ${label}: tối đa ${MAX_OPTIONS} lựa chọn.`); return; }
    const values = options.map((option) => String(option.value ?? "").trim()).filter(Boolean);
    if (new Set(values).size !== values.length) errors.push(`Câu hỏi ${label}: mã lựa chọn bị trùng.`);
    const min = Number(question.min_choices ?? 0);
    const max = Number(question.max_choices ?? 0);
    if (min > options.length) errors.push(`Câu hỏi ${label}: số lựa chọn tối thiểu vượt quá số đáp án.`);
    if (max && max < min) errors.push(`Câu hỏi ${label}: số lựa chọn tối đa phải lớn hơn hoặc bằng mức tối thiểu.`);
    if (max > options.length) errors.push(`Câu hỏi ${label}: số lựa chọn tối đa vượt quá số đáp án.`);
    if (question.type === "single_choice" && min > 1) errors.push(`Câu hỏi ${label}: câu chọn 1 không thể yêu cầu nhiều hơn 1 đáp án.`);
  });
  return errors;
}

/** ASCII, stable option key derived from a label (`Hương vị` -> `huong-vi`). */
export function slugifyOption(value) {
  return String(value ?? "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/đ/gi, "d")
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 64);
}

/** Payload for `PUT /events/{id}/questions`. */
export function questionsPayload(questions, revision) {
  return {
    expected_revision: Number(revision ?? 0),
    questions: (questions ?? []).map((question) => ({
      id: Number(question.id ?? 0),
      type: question.type,
      required: Boolean(question.required),
      min_choices: Number(question.min_choices ?? 0),
      max_choices: Number(question.max_choices ?? 0),
      translations: Object.fromEntries(EVENT_LOCALES.map((locale) => {
        const row = question.translations?.[locale] ?? {};
        return [locale, {
          label: String(row.label ?? "").trim(),
          help_text: String(row.help_text ?? "").trim(),
          options: (row.options ?? [])
            .map((option) => ({
              value: slugifyOption(option.value),
              label: String(option.label ?? "").trim(),
            }))
            .filter((option) => option.value && option.label),
        }];
      })),
    })),
  };
}

export function eventListQuery({ status = "", search = "", page = 1, per_page = 25 } = {}) {
  const params = new URLSearchParams();
  if (status) params.set("status", status);
  if (search) params.set("search", search);
  params.set("page", String(page));
  params.set("per_page", String(per_page));
  return params.toString();
}

export function registrationListQuery({ eventId, status = "", channel = "", search = "", page = 1, per_page = 25 }) {
  const params = new URLSearchParams();
  if (status) params.set("status", status);
  if (channel) params.set("channel", channel);
  if (search) params.set("search", search);
  params.set("page", String(page));
  params.set("per_page", String(per_page));
  return `/${eventId}/registrations?${params.toString()}`;
}

export function registrationStatusLabel(status) {
  return REGISTRATION_STATUS_LABELS[status] ?? status ?? "";
}

export function registrationStatusTone(status) {
  if (status === "confirmed" || status === "attended") return "approved";
  if (status === "waitlisted") return "pending";
  return "failed";
}

export function contactLabel(registration) {
  if (!registration) return "";
  if (registration.email) return registration.email;
  if (registration.phone) return registration.phone;
  return "—";
}

export function contactChannelLabel(registration) {
  if (!registration) return "";
  return registration.contact_channel === "phone" ? "SĐT" : "Email";
}

/** "Còn 12/40 chỗ" / "Không giới hạn" / "Đã đầy · 6 chờ". */
export function capacityLabel(event) {
  if (!event) return "";
  const stats = event.stats ?? null;
  const capacity = event.capacity ?? stats?.capacity ?? null;
  if (capacity === null || capacity === undefined) return "Không giới hạn";
  const taken = stats ? Number(stats.taken ?? stats.confirmed ?? 0) : 0;
  const waitlisted = Number(stats?.waitlisted ?? 0);
  const remaining = Math.max(0, Number(capacity) - taken);
  const base = remaining > 0 ? `Còn ${remaining}/${capacity} chỗ` : `Đã đầy · ${capacity} chỗ`;
  return waitlisted > 0 ? `${base} · ${waitlisted} chờ` : base;
}

export function eventTimelineLabel(state) {
  return { upcoming: "Sắp diễn ra", ongoing: "Đang diễn ra", ended: "Đã kết thúc" }[state] ?? state ?? "";
}

export function eventTimelineTone(state) {
  return { upcoming: "pending", ongoing: "approved", ended: "done" }[state] ?? "pending";
}

/** `18:00 – 21:00, 05/11/2026` in site-local time. */
export function formatEventRange(startsAt, endsAt, locale = "vi") {
  if (!startsAt || !endsAt) return "";
  const start = new Date(startsAt);
  const end = new Date(endsAt);
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return "";
  const time = new Intl.DateTimeFormat(locale === "en" ? "en-GB" : "vi-VN", { timeZone: EVENT_TIMEZONE, hour: "2-digit", minute: "2-digit", hour12: false });
  const day = new Intl.DateTimeFormat(locale === "en" ? "en-GB" : "vi-VN", { timeZone: EVENT_TIMEZONE, day: "2-digit", month: "2-digit", year: "numeric" });
  const sameDay = day.format(start) === day.format(end);
  return sameDay ? `${time.format(start)} – ${time.format(end)}, ${day.format(start)}` : `${time.format(start)} ${day.format(start)} – ${time.format(end)} ${day.format(end)}`;
}

/** Browser-side downscale before upload; keeps the 6 MB BFF limit comfortable. */
export const IMAGE_MAX_EDGE = 1600;
export const IMAGE_QUALITY = 0.82;

export function mediaUploadPayload({ filename, mime, data_base64: dataBase64 = "", kind = "gallery", caption = "" }) {
  return { filename, mime, data_base64: dataBase64, kind, caption };
}
