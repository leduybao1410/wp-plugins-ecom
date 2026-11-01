/**
 * Pure helpers for the email-campaign screens.
 *
 * Kept free of React and fetch so the CSV import, status vocabulary and
 * scheduling rules are unit-tested by `npm run coverage` like the other
 * dashboard helpers. The WordPress adapter re-validates everything it
 * receives: nothing here is a security boundary.
 */

export const RECIPIENT_STATUSES = ["pending", "success", "failed", "lead"];

/** The badge text the operations team asked for. */
export const RECIPIENT_STATUS_LABELS = {
  pending: "PENDING",
  success: "SUCCESS",
  failed: "FAILED",
  lead: "ĐÃ PHẢN HỒI",
};

/** Longer wording for tables and screen readers. */
export const RECIPIENT_STATUS_NAMES = {
  pending: "Chờ gửi",
  success: "Đã gửi",
  failed: "Lỗi",
  lead: "Đã phản hồi",
};

export const CAMPAIGN_STATUS_LABELS = {
  draft: "Bản nháp",
  scheduled: "Đã hẹn giờ",
  sending: "Đang gửi",
  paused: "Tạm dừng",
  done: "Hoàn tất",
  cancelled: "Đã huỷ",
};

/** Map a recipient status onto an existing `.status[data-status]` token. */
export function statusBadge(status) {
  const key = RECIPIENT_STATUSES.includes(status) ? status : "pending";
  return {
    key,
    label: RECIPIENT_STATUS_LABELS[key],
    tone: key === "success" ? "sent" : key,
  };
}

export function campaignStatusBadge(status) {
  const key = Object.hasOwn(CAMPAIGN_STATUS_LABELS, status) ? status : "draft";
  const tone = key === "done" ? "sent" : key === "sending" ? "pending" : key === "paused" ? "on-hold" : key === "cancelled" ? "failed" : "draft";
  return { key, label: CAMPAIGN_STATUS_LABELS[key], tone };
}

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i;

export function isEmail(value) {
  return typeof value === "string" && EMAIL_PATTERN.test(value.trim());
}

/** Split one CSV line, honouring double quotes and escaped quotes. */
function splitLine(line, delimiter) {
  const cells = [];
  let cell = "";
  let quoted = false;

  for (let index = 0; index < line.length; index += 1) {
    const character = line[index];

    if (quoted) {
      if (character === '"' && line[index + 1] === '"') {
        cell += '"';
        index += 1;
        continue;
      }
      if (character === '"') {
        quoted = false;
        continue;
      }
      cell += character;
      continue;
    }

    if (character === '"') {
      quoted = true;
      continue;
    }

    if (character === delimiter) {
      cells.push(cell);
      cell = "";
      continue;
    }

    cell += character;
  }

  cells.push(cell);
  return cells.map((value) => value.trim());
}

/** Pick the delimiter that appears most often outside quotes on the header. */
export function detectDelimiter(text) {
  const firstLine = String(text ?? "").split(/\r?\n/, 1)[0] ?? "";
  const candidates = [",", ";", "\t"];
  let best = ",";
  let bestCount = -1;

  for (const candidate of candidates) {
    const count = firstLine.split(candidate).length - 1;
    if (count > bestCount) {
      best = candidate;
      bestCount = count;
    }
  }

  return best;
}

/**
 * Parse delimited text into a header row and data rows.
 *
 * @param {string} text CSV text, with or without a UTF-8 BOM.
 * @param {string} [delimiter] Optional delimiter override.
 * @returns {{headers: string[], rows: string[][]}}
 */
export function parseCsv(text, delimiter) {
  const clean = String(text ?? "").replace(/^\uFEFF/, "").replace(/\r\n?/g, "\n");
  const sep = delimiter ?? detectDelimiter(clean);
  const lines = clean.split("\n").filter((line) => line.trim() !== "");

  if (!lines.length) throw new Error("Tệp không có dữ liệu.");

  const headers = splitLine(lines[0], sep);
  const rows = lines.slice(1).map((line) => splitLine(line, sep));

  return { headers, rows };
}

const COLUMN_HINTS = {
  email: /e-?mail|thư điện tử/i,
  name: /^(name|full ?name|contact|người nhận|tên)/i,
  company: /company|công ty|hotel|khách sạn|property|organization|organisation/i,
  phone: /phone|điện thoại|tel\b|mobile|sđt|sdt/i,
  locale: /locale|language|ngôn ngữ|\blang\b/i,
};

/**
 * Guess which header is which. The UI shows the result and lets the operator
 * change every column before importing.
 *
 * @param {string[]} headers
 * @returns {{email: number|null, name: number, company: number, phone: number, locale: number}}
 */
export function guessColumnMap(headers) {
  const map = { email: null, name: -1, company: -1, phone: -1, locale: -1 };

  headers.forEach((header, index) => {
    for (const [key, pattern] of Object.entries(COLUMN_HINTS)) {
      if (map[key] === -1 || map[key] === null) {
        if (pattern.test(header)) {
          map[key] = index;
          return;
        }
      }
    }
  });

  if (map.email === null) {
    const fallback = headers.findIndex((header) => /mail/i.test(header));
    map.email = fallback >= 0 ? fallback : headers.length === 1 ? 0 : null;
  }

  return map;
}

/**
 * Turn mapped CSV rows into import payload rows, reporting what was skipped.
 *
 * @param {object} input
 * @param {string[][]} input.rows
 * @param {{email: number|null, name?: number, company?: number, phone?: number, locale?: number}} input.map
 * @param {"vi"|"en"} [input.defaultLocale]
 * @returns {{rows: object[], invalid: number, duplicates: number, missingEmailColumn: boolean, total: number}}
 */
export function buildImportRows({ rows, map, defaultLocale = "vi" }) {
  if (map.email === null || map.email === undefined || map.email < 0) {
    return { rows: [], invalid: 0, duplicates: 0, missingEmailColumn: true, total: rows?.length ?? 0 };
  }

  const seen = new Set();
  const output = [];
  let invalid = 0;
  let duplicates = 0;

  for (const row of rows ?? []) {
    if (!Array.isArray(row)) {
      invalid += 1;
      continue;
    }

    const email = String(row[map.email] ?? "").trim().toLowerCase();
    const emptyRow = row.every((cell) => String(cell ?? "").trim() === "");

    if (emptyRow) continue;

    if (!isEmail(email)) {
      invalid += 1;
      continue;
    }

    if (seen.has(email)) {
      duplicates += 1;
      continue;
    }

    seen.add(email);

    const locale = map.locale >= 0 ? String(row[map.locale] ?? "").trim().toLowerCase() : "";

    output.push({
      email,
      name: map.name >= 0 ? String(row[map.name] ?? "").trim().slice(0, 200) : "",
      company: map.company >= 0 ? String(row[map.company] ?? "").trim().slice(0, 200) : "",
      phone: map.phone >= 0 ? String(row[map.phone] ?? "").trim().slice(0, 50) : "",
      locale: ["vi", "en"].includes(locale) ? locale : defaultLocale,
    });
  }

  return { rows: output, invalid, duplicates, missingEmailColumn: false, total: output.length + invalid + duplicates };
}

/**
 * Validate the composer before it reaches the adapter.
 *
 * @param {object} input
 * @returns {{name: string, subject: string, body_text: string, template: string, locale: "vi"|"en"}}
 */
export function validateCampaignDraft(input = {}) {
  const name = typeof input.name === "string" ? input.name.trim() : "";
  const subject = typeof input.subject === "string" ? input.subject.trim() : "";
  const bodyText = typeof input.body_text === "string" ? input.body_text.trim() : "";
  const template = typeof input.template === "string" ? input.template.trim() : "";
  const locale = input.locale === "en" ? "en" : "vi";

  if (!name) throw new Error("Nhập tên chiến dịch.");
  if (name.length > 200) throw new Error("Tên chiến dịch tối đa 200 ký tự.");
  if (!subject) throw new Error("Nhập tiêu đề email.");
  if (subject.length > 255) throw new Error("Tiêu đề email tối đa 255 ký tự.");
  if (!bodyText) throw new Error("Nhập nội dung thư.");
  if (!template) throw new Error("Chọn một mẫu email đã duyệt.");

  return { name, subject, body_text: bodyText, template, locale };
}

/**
 * A start time must be in the future and inside a sane window.
 *
 * @param {string} value `datetime-local` value.
 * @param {number} [now] Milliseconds since epoch.
 * @returns {string} The same value, trimmed.
 */
export function validateSchedule(value, now = Date.now()) {
  const raw = typeof value === "string" ? value.trim() : "";

  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(raw)) throw new Error("Chọn ngày và giờ bắt đầu hợp lệ.");

  const when = new Date(raw).getTime();

  if (!Number.isFinite(when)) throw new Error("Chọn ngày và giờ bắt đầu hợp lệ.");
  if (when <= now + 60_000) throw new Error("Giờ bắt đầu phải sau hiện tại ít nhất một phút.");
  if (when > now + 366 * 24 * 60 * 60 * 1000) throw new Error("Chỉ có thể hẹn giờ trong vòng một năm.");

  return raw;
}

/**
 * Remaining work and a rough finish estimate for the campaign header.
 *
 * @param {{total_recipients?: number, sent_count?: number, failed_count?: number, lead_count?: number}} campaign
 * @param {number} [maxPerHour]
 */
export function campaignProgress(campaign = {}, maxPerHour = 10) {
  const total = Number(campaign.total_recipients ?? 0);
  const done = Number(campaign.sent_count ?? 0) + Number(campaign.failed_count ?? 0) + Number(campaign.lead_count ?? 0);
  const remaining = Math.max(0, total - done);
  const rate = Math.max(1, Number(maxPerHour) || 10);

  return {
    total,
    done,
    remaining,
    percent: total ? Math.min(100, Math.round((done / total) * 100)) : 0,
    minutes: remaining ? Math.ceil((remaining / rate) * 60) : 0,
  };
}

/** Human wording for the queue estimate. */
export function formatDuration(totalMinutes) {
  const minutes = Math.max(0, Math.round(Number(totalMinutes) || 0));

  if (minutes < 1) return "dưới 1 phút";
  if (minutes < 60) return `${minutes} phút`;

  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;

  return rest ? `${hours} giờ ${rest} phút` : `${hours} giờ`;
}
