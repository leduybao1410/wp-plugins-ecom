const MAX_RANGE_DAYS = 366;

function parseIsoDate(value) {
  if (typeof value !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
  const date = new Date(`${value}T00:00:00.000Z`);
  return Number.isNaN(date.getTime()) || date.toISOString().slice(0, 10) !== value ? null : date;
}

export function normalizeOverviewRange({ from, to }) {
  const start = parseIsoDate(from);
  const end = parseIsoDate(to);
  if (!start || !end) throw new Error("Enter valid dates for the reporting period.");
  if (start > end) throw new Error("The start date must be before or equal to the end date.");
  const days = Math.floor((end.getTime() - start.getTime()) / 86_400_000) + 1;
  if (days > MAX_RANGE_DAYS) throw new Error(`Choose a reporting period of ${MAX_RANGE_DAYS} days or less.`);
  return { from, to };
}

export function localToday(timeZone = "Asia/Ho_Chi_Minh", now = new Date()) {
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone, year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(now);
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]));
  return `${values.year}-${values.month}-${values.day}`;
}

export function overviewPresetRange(preset, today) {
  const date = parseIsoDate(today);
  if (!date) throw new Error("Enter a valid date for the reporting period.");
  const to = date.toISOString().slice(0, 10);
  if (preset === "today") return { from: to, to };
  if (preset === "month") return { from: `${to.slice(0, 7)}-01`, to };
  if (preset === "last7") {
    date.setUTCDate(date.getUTCDate() - 6);
    return { from: date.toISOString().slice(0, 10), to };
  }
  throw new Error("Choose a supported reporting period.");
}
