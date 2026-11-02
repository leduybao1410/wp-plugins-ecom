/**
 * Pure helpers for the administrator-managed FAQ module.
 *
 * Kept framework-free so the same file serves the Next dashboard and the
 * WordPress-hosted `/ops` console (epic-ops-console).
 */

export const FAQ_LOCALES = ["vi", "en", "ru", "hi", "zh", "ko", "ja"];

export const FAQ_LOCALE_LABELS = {
  vi: "Tiếng Việt",
  en: "English",
  ru: "Русский",
  hi: "हिन्दी",
  zh: "中文",
  ko: "한국어",
  ja: "日本語",
};

export const FAQ_GROUPS = ["home", "coffee", "visit", "contact", "roastery", "wholesale", "assistant"];

export const FAQ_GROUP_LABELS = {
  home: "Trang chủ",
  coffee: "Cà phê",
  visit: "Ghé thăm",
  contact: "Liên hệ",
  roastery: "Xưởng rang",
  wholesale: "Bán sỉ",
  assistant: "Chỉ cho trợ lý (không hiện trên trang)",
};

export const FAQ_GROUP_PATHS = {
  home: "",
  coffee: "coffee",
  visit: "visit",
  contact: "contact",
  roastery: "roastery",
  wholesale: "wholesale",
  assistant: "contact",
};

export const FAQ_MAX_QUESTION = 300;
export const FAQ_MAX_ANSWER = 2000;

const EMPTY_TRANSLATIONS = () =>
  Object.fromEntries(FAQ_LOCALES.map((locale) => [locale, { question: "", answer: "" }]));

export function emptyFaqDraft() {
  return {
    id: 0,
    group: "home",
    position: 0,
    site_visible: true,
    bot_visible: true,
    revision: 0,
    status: "draft",
    missing_locales: [...FAQ_LOCALES],
    translations: EMPTY_TRANSLATIONS(),
  };
}

/** @param {Record<string, unknown> | null | undefined} record */
export function faqDraftFromRecord(record) {
  if (!record) return emptyFaqDraft();
  const translations = EMPTY_TRANSLATIONS();
  const source = record.translations && typeof record.translations === "object" ? record.translations : {};
  for (const locale of FAQ_LOCALES) {
    const entry = source[locale];
    if (!entry || typeof entry !== "object") continue;
    translations[locale] = {
      question: typeof entry.question === "string" ? entry.question : "",
      answer: typeof entry.answer === "string" ? entry.answer : "",
    };
  }
  return {
    id: Number(record.id) || 0,
    group: typeof record.group === "string" ? record.group : "home",
    position: Number(record.position) || 0,
    site_visible: record.site_visible !== false,
    bot_visible: record.bot_visible !== false,
    revision: Number(record.revision) || 0,
    status: typeof record.status === "string" ? record.status : "draft",
    missing_locales: Array.isArray(record.missing_locales) ? record.missing_locales : [],
    translations,
  };
}

/** @param {ReturnType<typeof emptyFaqDraft>} draft */
export function faqCompleteness(draft) {
  const filled = [];
  const missing = [];
  for (const locale of FAQ_LOCALES) {
    const entry = draft.translations?.[locale] ?? { question: "", answer: "" };
    if (entry.question.trim() && entry.answer.trim()) filled.push(locale);
    else missing.push(locale);
  }
  return { filled, missing };
}

/**
 * @param {ReturnType<typeof emptyFaqDraft>} draft
 * @param {{ publishing?: boolean }} [options]
 * @returns {string[]}
 */
export function validateFaqDraft(draft, options = {}) {
  const errors = [];
  if (!FAQ_GROUPS.includes(draft.group)) errors.push("Chọn một nhóm FAQ hợp lệ.");
  let anyContent = false;
  for (const locale of FAQ_LOCALES) {
    const entry = draft.translations?.[locale] ?? { question: "", answer: "" };
    const question = entry.question.trim();
    const answer = entry.answer.trim();
    if (!question && !answer) continue;
    anyContent = true;
    if (!question || !answer) {
      errors.push(`${FAQ_LOCALE_LABELS[locale]}: cần cả câu hỏi và câu trả lời.`);
      continue;
    }
    if (question.length > FAQ_MAX_QUESTION) errors.push(`${FAQ_LOCALE_LABELS[locale]}: câu hỏi vượt ${FAQ_MAX_QUESTION} ký tự.`);
    if (answer.length > FAQ_MAX_ANSWER) errors.push(`${FAQ_LOCALE_LABELS[locale]}: câu trả lời vượt ${FAQ_MAX_ANSWER} ký tự.`);
  }
  if (!anyContent) errors.push("Cần ít nhất một ngôn ngữ có câu hỏi và câu trả lời.");
  if (options.publishing) {
    const { missing } = faqCompleteness(draft);
    if (missing.length) errors.push(`Phải dịch đủ 7 ngôn ngữ trước khi xuất bản. Còn thiếu: ${missing.map((locale) => FAQ_LOCALE_LABELS[locale]).join(", ")}.`);
  }
  return errors;
}

/** @param {ReturnType<typeof emptyFaqDraft>} draft */
export function faqSavePayload(draft) {
  const translations = {};
  for (const locale of FAQ_LOCALES) {
    const entry = draft.translations?.[locale] ?? { question: "", answer: "" };
    translations[locale] = { question: entry.question.trim(), answer: entry.answer.trim() };
  }
  return {
    group: draft.group,
    position: Number(draft.position) || 0,
    site_visible: draft.site_visible !== false,
    bot_visible: draft.bot_visible !== false,
    revision: Number(draft.revision) || 0,
    translations,
  };
}

/** @param {string} group @param {string} locale */
export function faqGroupUrl(group, locale) {
  const path = FAQ_GROUP_PATHS[group] ?? "";
  return `/${locale}${path ? `/${path}` : ""}`;
}

/** @param {{ status?: string, group?: string, lang?: string, search?: string, page?: number, per_page?: number }} params */
export function faqListQuery(params = {}) {
  const query = new URLSearchParams();
  if (params.status) query.set("status", params.status);
  if (params.group) query.set("group", params.group);
  if (params.lang) query.set("lang", params.lang);
  if (params.search?.trim()) query.set("search", params.search.trim());
  query.set("page", String(Math.max(1, Number(params.page) || 1)));
  query.set("per_page", String(Math.max(1, Number(params.per_page) || 25)));
  return query.toString();
}

/** @param {{ item_count?: number, bytes?: number, truncated?: boolean, version?: string } | null} bundle */
export function faqBundleSummary(bundle) {
  if (!bundle) return { label: "Chưa tải gói ngữ cảnh", tone: "pending", truncated: false };
  const count = Number(bundle.item_count) || 0;
  const bytes = Number(bundle.bytes) || 0;
  return {
    label: `${count} FAQ · ${bytes.toLocaleString("vi-VN")} byte${bundle.truncated ? " · đã cắt bớt" : ""}`,
    tone: bundle.truncated ? "pending" : "approved",
    truncated: Boolean(bundle.truncated),
    version: typeof bundle.version === "string" ? bundle.version : "",
  };
}

/** @param {Record<string, unknown>} item */
export function faqDisplayTitle(item) {
  const question = typeof item.question === "string" ? item.question.trim() : "";
  if (question) return question;
  const translations = item.translations && typeof item.translations === "object" ? item.translations : {};
  for (const locale of ["vi", "en", ...FAQ_LOCALES]) {
    const entry = translations[locale];
    if (entry && typeof entry.question === "string" && entry.question.trim()) return entry.question.trim();
  }
  return `FAQ #${item.id}`;
}
