import { normalizeOverviewRange } from "./overview-query.js";

export const POSTHOG_HOST = "https://us.posthog.com";
export const POSTHOG_PROJECT_ID = "715536";
export const POSTHOG_DASHBOARD_URL = "https://us.posthog.com/project/715536/dashboard/2313977";
export const ANALYTICS_HOST = "epicroastery.coffee";
export const ANALYTICS_VIEWS = Object.freeze(["overview", "campaigns", "funnel", "checkout", "content", "quality", "reconcile"]);

const DAY_MS = 86_400_000;

function number(value) {
  if (value === null || value === undefined || value === "") return 0;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

function nullableNumber(value) {
  if (value === null || value === undefined || value === "") return null;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
}

function nullableRatio(numerator, denominator) {
  const top = nullableNumber(numerator);
  const bottom = nullableNumber(denominator);
  if (top === null || bottom === null || bottom <= 0) return null;
  return top / bottom;
}

export function ratio(numerator, denominator) {
  return nullableRatio(numerator, denominator);
}

export function percentChange(current, previous) {
  const next = nullableNumber(current);
  const base = nullableNumber(previous);
  if (next === null || base === null || base === 0) return null;
  return (next - base) / base;
}

export function previousAnalyticsRange({ from, to }) {
  const range = normalizeOverviewRange({ from, to });
  const start = new Date(`${range.from}T00:00:00.000Z`);
  const end = new Date(`${range.to}T00:00:00.000Z`);
  const days = Math.round((end.getTime() - start.getTime()) / DAY_MS) + 1;
  const previousEnd = new Date(start.getTime() - DAY_MS);
  const previousStart = new Date(previousEnd.getTime() - (days - 1) * DAY_MS);
  return { from: previousStart.toISOString().slice(0, 10), to: previousEnd.toISOString().slice(0, 10) };
}

// Every query is scoped to the validated ISO range and to the storefront host,
// which keeps out probe batches that never carried `$host`.
export function analyticsDateFilter({ from, to }) {
  const range = normalizeOverviewRange({ from, to });
  return [
    `timestamp >= toDateTime('${range.from} 00:00:00', 'Asia/Ho_Chi_Minh')`,
    `timestamp < toDateTime('${range.to} 00:00:00', 'Asia/Ho_Chi_Minh') + INTERVAL 1 DAY`,
    `properties.$host = '${ANALYTICS_HOST}'`,
  ].join("\n  AND ");
}

const LOCAL_DAY = "toDate(toTimeZone(timestamp, 'Asia/Ho_Chi_Minh'))";

function overviewSql(range) {
  return `SELECT ${LOCAL_DAY} AS day,
       countIf(event = '$pageview') AS pageviews,
       uniqIf(person_id, event = '$pageview') AS visitors,
       uniqIf(properties.$session_id, event = '$pageview') AS sessions,
       countIf(event = 'purchase') AS orders,
       sumIf(toFloat(properties.value), event = 'purchase') AS revenue,
       countIf(event = 'begin_checkout') AS checkouts,
       countIf(event = 'checkout_error') AS errors
FROM events
WHERE ${analyticsDateFilter(range)}
GROUP BY day
ORDER BY day`;
}

function campaignsSql(range) {
  return `SELECT properties.promotion_id AS promotion_id,
       properties.creative_slot AS slot,
       countIf(event = 'view_promotion') AS impressions,
       countIf(event = 'select_promotion') AS clicks,
       countIf(event = 'promotion_dismiss') AS dismissals,
       countIf(event = 'purchase') AS orders,
       sumIf(toFloat(properties.value), event = 'purchase') AS revenue
FROM events
WHERE ${analyticsDateFilter(range)}
  AND event IN ('view_promotion', 'select_promotion', 'promotion_dismiss', 'purchase')
  AND properties.promotion_id IS NOT NULL
GROUP BY promotion_id, slot
ORDER BY revenue DESC, impressions DESC
LIMIT 100`;
}

function checkoutErrorsSql(range) {
  return `SELECT properties.error_code AS error_code,
       properties.field_group AS field_group,
       count() AS count
FROM events
WHERE ${analyticsDateFilter(range)}
  AND event = 'checkout_error'
GROUP BY error_code, field_group
ORDER BY count DESC
LIMIT 50`;
}

function checkoutLatencySql(range) {
  return `SELECT quantile(0.75)(toFloat(properties.duration_ms)) AS latency_p75,
       count() AS samples
FROM events
WHERE ${analyticsDateFilter(range)}
  AND event = 'checkout_latency'`;
}

function checkoutContextSql(range) {
  return `SELECT countIf(event = 'begin_checkout') AS checkouts,
       countIf(event = 'checkout_error') AS errors
FROM events
WHERE ${analyticsDateFilter(range)}`;
}

function pagesSql(range) {
  return `SELECT coalesce(properties.$pathname, properties.$current_url) AS path,
       count() AS views,
       uniq(person_id) AS visitors
FROM events
WHERE ${analyticsDateFilter(range)}
  AND event = '$pageview'
GROUP BY path
ORDER BY views DESC
LIMIT 20`;
}

function sourcesSql(range) {
  return `SELECT properties.$utm_source AS source,
       properties.$utm_campaign AS campaign,
       count() AS pageviews,
       uniq(person_id) AS visitors
FROM events
WHERE ${analyticsDateFilter(range)}
  AND event = '$pageview'
GROUP BY source, campaign
ORDER BY visitors DESC
LIMIT 20`;
}

function productsSql(range) {
  return `SELECT JSONExtractString(item, 'item_id') AS item_id,
       JSONExtractString(item, 'item_name') AS item_name,
       countIf(event = 'add_to_cart') AS add_to_carts,
       countIf(event = 'purchase') AS purchases,
       sumIf(toFloat(properties.value), event = 'purchase') AS revenue
FROM events
ARRAY JOIN JSONExtractArrayRaw(properties.items) AS item
WHERE ${analyticsDateFilter(range)}
  AND event IN ('add_to_cart', 'purchase')
GROUP BY item_id, item_name
ORDER BY add_to_carts DESC
LIMIT 20`;
}

function qualitySql(range, groupByDay) {
  return `SELECT ${groupByDay ? `${LOCAL_DAY} AS day,` : ""}
       quantile(0.75)(toFloat(properties.$web_vitals_LCP_value)) AS lcp_p75,
       quantile(0.75)(toFloat(properties.$web_vitals_INP_value)) AS inp_p75,
       quantile(0.75)(toFloat(properties.$web_vitals_CLS_value)) AS cls_p75,
       count() AS samples
FROM events
WHERE ${analyticsDateFilter(range)}
  AND event = '$web_vitals'${groupByDay ? "\nGROUP BY day\nORDER BY day" : ""}`;
}

function reconcileSql(range) {
  return `SELECT ${LOCAL_DAY} AS day,
       countIf(event = 'purchase') AS orders,
       sumIf(toFloat(properties.value), event = 'purchase') AS revenue
FROM events
WHERE ${analyticsDateFilter(range)}
GROUP BY day
ORDER BY day`;
}

function funnelQuery(range) {
  return {
    kind: "FunnelsQuery",
    series: [
      { kind: "EventsNode", event: "view_item" },
      { kind: "EventsNode", event: "add_to_cart" },
      { kind: "EventsNode", event: "begin_checkout" },
      { kind: "EventsNode", event: "purchase" },
    ],
    dateRange: { date_from: range.from, date_to: range.to },
    filterTestAccounts: true,
    properties: [{ key: "$host", value: [ANALYTICS_HOST], operator: "exact", type: "event" }],
  };
}

function sqlQuery(name, sql) {
  return { name, body: { query: { kind: "HogQLQuery", query: sql } } };
}

/**
 * Describes every PostHog request a view needs. The route only ever passes a
 * validated view id and ISO dates here; no SQL or project id comes from the client.
 */
export function buildAnalyticsPlan(view, rangeInput) {
  const range = normalizeOverviewRange(rangeInput);
  if (!ANALYTICS_VIEWS.includes(view)) throw new Error("Choose a supported analytics view.");
  if (view === "overview") {
    const previous = previousAnalyticsRange(range);
    return {
      view,
      range,
      previousRange: previous,
      queries: [sqlQuery("current", overviewSql(range)), sqlQuery("previous", overviewSql(previous))],
    };
  }
  if (view === "campaigns") return { view, range, queries: [sqlQuery("campaigns", campaignsSql(range))] };
  if (view === "funnel") return { view, range, queries: [{ name: "funnel", body: { query: funnelQuery(range) } }] };
  if (view === "checkout") {
    return {
      view,
      range,
      queries: [
        sqlQuery("errors", checkoutErrorsSql(range)),
        sqlQuery("latency", checkoutLatencySql(range)),
        sqlQuery("context", checkoutContextSql(range)),
      ],
    };
  }
  if (view === "content") {
    return {
      view,
      range,
      queries: [sqlQuery("pages", pagesSql(range)), sqlQuery("sources", sourcesSql(range)), sqlQuery("products", productsSql(range))],
    };
  }
  if (view === "quality") {
    return {
      view,
      range,
      queries: [sqlQuery("daily", qualitySql(range, true)), sqlQuery("overall", qualitySql(range, false))],
    };
  }
  return { view, range, queries: [sqlQuery("daily", reconcileSql(range))] };
}

/** Normalizes the two shapes PostHog returns for HogQL: rows-as-arrays and rows-as-objects. */
export function parseHogqlRows(payload) {
  const results = payload?.results;
  if (!Array.isArray(results)) return [];
  if (!results.length) return [];
  if (results.every((row) => row && typeof row === "object" && !Array.isArray(row))) return results;
  const columns = Array.isArray(payload.columns) ? payload.columns : [];
  return results.map((row) => {
    if (!Array.isArray(row)) return {};
    return Object.fromEntries(columns.map((column, index) => [column, row[index]]));
  });
}

function firstRow(payload) {
  return parseHogqlRows(payload)[0] ?? {};
}

function emptyTotals() {
  return { pageviews: 0, visitors: 0, sessions: 0, orders: 0, revenue: 0, checkouts: 0, errors: 0 };
}

function mapOverviewDaily(payload) {
  return parseHogqlRows(payload).map((row) => ({
    day: row.day ? String(row.day).slice(0, 10) : "",
    pageviews: number(row.pageviews),
    visitors: number(row.visitors),
    sessions: number(row.sessions),
    orders: number(row.orders),
    revenue: number(row.revenue),
    checkouts: number(row.checkouts),
    errors: number(row.errors),
  }));
}

function summarizeOverview(daily) {
  const totals = daily.reduce((sum, row) => ({
    pageviews: sum.pageviews + row.pageviews,
    visitors: sum.visitors + row.visitors,
    sessions: sum.sessions + row.sessions,
    orders: sum.orders + row.orders,
    revenue: sum.revenue + row.revenue,
    checkouts: sum.checkouts + row.checkouts,
    errors: sum.errors + row.errors,
  }), emptyTotals());
  return {
    ...totals,
    cvr: nullableRatio(totals.orders, totals.visitors),
    aov: nullableRatio(totals.revenue, totals.orders),
    error_rate: nullableRatio(totals.errors, totals.checkouts),
  };
}

const OVERVIEW_METRICS = ["pageviews", "visitors", "sessions", "orders", "revenue", "checkouts", "errors", "cvr", "aov", "error_rate"];

export function mapOverview(currentPayload, previousPayload) {
  const daily = mapOverviewDaily(currentPayload);
  const totals = summarizeOverview(daily);
  const previous = previousPayload ? summarizeOverview(mapOverviewDaily(previousPayload)) : null;
  const deltas = previous
    ? Object.fromEntries(OVERVIEW_METRICS.map((metric) => [metric, percentChange(totals[metric], previous[metric])]))
    : {};
  return { daily, totals, previous, deltas };
}

export function mapCampaigns(payload) {
  const rows = parseHogqlRows(payload)
    .map((row) => {
      const impressions = number(row.impressions);
      const clicks = number(row.clicks);
      const dismissals = number(row.dismissals);
      const revenue = number(row.revenue);
      return {
        promotion_id: row.promotion_id ? String(row.promotion_id) : "(không mã)",
        slot: row.slot ? String(row.slot) : "—",
        impressions,
        clicks,
        dismissals,
        orders: number(row.orders),
        revenue,
        ctr: nullableRatio(clicks, impressions),
        dismiss_rate: nullableRatio(dismissals, impressions),
        rpm: impressions > 0 ? (revenue / impressions) * 1000 : null,
      };
    })
    .filter((row) => row.impressions > 0 || row.orders > 0 || row.revenue > 0);
  const totals = rows.reduce((sum, row) => ({
    impressions: sum.impressions + row.impressions,
    clicks: sum.clicks + row.clicks,
    dismissals: sum.dismissals + row.dismissals,
    orders: sum.orders + row.orders,
    revenue: sum.revenue + row.revenue,
  }), { impressions: 0, clicks: 0, dismissals: 0, orders: 0, revenue: 0 });
  return {
    rows,
    totals: {
      ...totals,
      ctr: nullableRatio(totals.clicks, totals.impressions),
      dismiss_rate: nullableRatio(totals.dismissals, totals.impressions),
      rpm: totals.impressions > 0 ? (totals.revenue / totals.impressions) * 1000 : null,
    },
  };
}

const FUNNEL_LABELS = {
  view_item: "Xem sản phẩm",
  add_to_cart: "Thêm vào giỏ",
  begin_checkout: "Bắt đầu thanh toán",
  purchase: "Mua hàng",
};

function funnelRawSteps(payload) {
  if (Array.isArray(payload?.results)) return payload.results;
  if (Array.isArray(payload?.result?.steps)) return payload.result.steps;
  if (Array.isArray(payload?.steps)) return payload.steps;
  return [];
}

export function mapFunnel(payload) {
  const events = ["view_item", "add_to_cart", "begin_checkout", "purchase"];
  const raw = funnelRawSteps(payload)
    .filter((step) => step && typeof step === "object")
    .slice()
    .sort((left, right) => number(left.order) - number(right.order));
  const steps = events.map((event, index) => {
    const match = raw.find((step) => step.order === index || step.event === event || step.action_id === event)
      ?? raw[index]
      ?? {};
    return { event, label: FUNNEL_LABELS[event], count: number(match.count) };
  });
  const first = steps[0]?.count ?? 0;
  let previous = null;
  const withRates = steps.map((step) => {
    const conversion = first > 0 ? step.count / first : null;
    const dropoff = previous !== null && previous > 0 ? 1 - step.count / previous : null;
    previous = step.count;
    return { ...step, conversion, dropoff };
  });
  const last = withRates[withRates.length - 1]?.count ?? 0;
  return { steps: withRates, first, last, overall: first > 0 ? last / first : null };
}

export function mapCheckout({ errors, latency, context }) {
  const rows = parseHogqlRows(errors).map((row) => ({
    error_code: row.error_code ? String(row.error_code) : "(không mã)",
    field_group: row.field_group ? String(row.field_group) : "—",
    count: number(row.count),
  }));
  const latencyRow = firstRow(latency);
  const contextRow = firstRow(context);
  const errorsTotal = rows.reduce((sum, row) => sum + row.count, 0);
  const checkouts = number(contextRow.checkouts);
  return {
    errors: rows,
    errors_total: errorsTotal,
    checkouts,
    error_rate: nullableRatio(errorsTotal, checkouts),
    latency_p75: nullableNumber(latencyRow.latency_p75),
    latency_samples: number(latencyRow.samples),
  };
}

export function mapContent({ pages, sources, products }, productsAvailable = true) {
  return {
    pages: parseHogqlRows(pages).map((row) => ({
      path: row.path ? String(row.path) : "/",
      views: number(row.views),
      visitors: number(row.visitors),
    })),
    sources: parseHogqlRows(sources).map((row) => ({
      source: row.source ? String(row.source) : "(trực tiếp)",
      campaign: row.campaign ? String(row.campaign) : "",
      pageviews: number(row.pageviews),
      visitors: number(row.visitors),
    })),
    products: parseHogqlRows(products).map((row) => ({
      item_id: row.item_id ? String(row.item_id) : "",
      item_name: row.item_name ? String(row.item_name) : "",
      add_to_carts: number(row.add_to_carts),
      purchases: number(row.purchases),
      revenue: number(row.revenue),
    })).filter((row) => row.item_id || row.item_name),
    products_available: productsAvailable,
  };
}

function mapQualityRows(payload) {
  return parseHogqlRows(payload).map((row) => ({
    day: row.day ? String(row.day).slice(0, 10) : "",
    lcp_p75: nullableNumber(row.lcp_p75),
    inp_p75: nullableNumber(row.inp_p75),
    cls_p75: nullableNumber(row.cls_p75),
    samples: number(row.samples),
  }));
}

export function mapQuality({ daily, overall }) {
  const overallRow = firstRow(overall);
  return {
    daily: mapQualityRows(daily),
    overall: {
      lcp_p75: nullableNumber(overallRow.lcp_p75),
      inp_p75: nullableNumber(overallRow.inp_p75),
      cls_p75: nullableNumber(overallRow.cls_p75),
      samples: number(overallRow.samples),
    },
  };
}

export function mapReconcile({ daily }, woocommerce) {
  const rows = parseHogqlRows(daily).map((row) => ({
    day: row.day ? String(row.day).slice(0, 10) : "",
    orders: number(row.orders),
    revenue: number(row.revenue),
  }));
  const posthog = {
    revenue: rows.reduce((sum, row) => sum + row.revenue, 0),
    orders: rows.reduce((sum, row) => sum + row.orders, 0),
  };
  const woo = {
    revenue: nullableNumber(woocommerce?.revenue),
    orders: nullableNumber(woocommerce?.orders),
    currency: woocommerce?.currency ? String(woocommerce.currency) : "VND",
    available: Boolean(woocommerce && woocommerce.revenue !== undefined && woocommerce.revenue !== null),
  };
  const revenueDifference = woo.revenue === null ? null : posthog.revenue - woo.revenue;
  return {
    daily: rows,
    posthog,
    woocommerce: woo,
    difference: {
      revenue: revenueDifference,
      revenue_pct: woo.revenue ? revenueDifference / woo.revenue : null,
      orders: woo.orders === null ? null : posthog.orders - woo.orders,
    },
  };
}

/**
 * Maps the per-query PostHog payloads into the shape the dashboard renders.
 * A missing payload degrades to empty data instead of failing the whole view.
 */
export function mapAnalyticsView(view, payloads, extra = {}) {
  if (view === "overview") return mapOverview(payloads.current, payloads.previous);
  if (view === "campaigns") return mapCampaigns(payloads.campaigns);
  if (view === "funnel") return mapFunnel(payloads.funnel);
  if (view === "checkout") return mapCheckout(payloads);
  if (view === "content") return mapContent(payloads, extra.productsAvailable !== false);
  if (view === "quality") return mapQuality(payloads);
  if (view === "reconcile") return mapReconcile(payloads, extra.woocommerce);
  throw new Error("Choose a supported analytics view.");
}

const RANGE_ERROR = /date|period/i;

export function validateAnalyticsRequest({ authenticated, sameOrigin, view, from, to }) {
  if (!authenticated) return { status: 401, error: "Sign in required" };
  if (!sameOrigin) return { status: 403, error: "Invalid request origin" };
  if (view === "health") return { status: 200, range: null };
  if (!ANALYTICS_VIEWS.includes(view)) return { status: 400, error: "Choose a supported analytics view." };
  try {
    return { status: 200, range: normalizeOverviewRange({ from, to }) };
  } catch (error) {
    const message = error instanceof Error && RANGE_ERROR.test(error.message)
      ? error.message
      : "Enter valid dates for the reporting period.";
    return { status: 400, error: message };
  }
}

/**
 * Small in-process cache. One dashboard process serves every admin, so 60s of
 * shared cache is enough to stay under PostHog's query budget.
 */
export function createAnalyticsCache({ ttlMs = 60_000, now = () => Date.now() } = {}) {
  const store = new Map();
  return {
    read(key) {
      const entry = store.get(key);
      if (!entry) return null;
      const age = now() - entry.at;
      return { value: entry.value, age, fresh: age < ttlMs };
    },
    write(key, value) {
      store.set(key, { value, at: now() });
    },
    clear() {
      store.clear();
    },
    get size() {
      return store.size;
    },
  };
}

export function analyticsCacheKey(view, range) {
  return `${view}:${range?.from ?? ""}:${range?.to ?? ""}`;
}
