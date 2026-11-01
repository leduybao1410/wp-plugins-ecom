/**
 * Headless smoke test for the /ops bundle.
 *
 * Serves the built `dist/` output from a throwaway HTTP server, stubs the
 * WordPress business API with realistic response shapes, and walks every module
 * in the sidebar. Any React runtime error, missing component or broken route
 * fails the run.
 *
 * Run from the plugin folder:
 *   NODE_PATH=/Volumes/data/Work/EPIC/admin-dashboard/node_modules \
 *     node tests/smoke.browser.mjs
 */
import { createServer } from "node:http";
import { readFile } from "node:fs/promises";
import { extname, join, normalize } from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);
const { chromium } = require("playwright");

const dist = fileURLToPath(new URL("../dist/", import.meta.url));
const manifest = JSON.parse(await readFile(join(dist, ".vite/manifest.json"), "utf8"));
const entry = manifest["src/main.tsx"];
const types = { ".js": "text/javascript", ".css": "text/css", ".woff2": "font/woff2" };

const emptyPage = { items: [], total: 0, page: 1, per_page: 25, total_pages: 0 };

function stubFor(pathname) {
  if (pathname.endsWith("/overview")) {
    return { from: "2026-10-01", to: "2026-10-30", orders: 12, revenue: 4200000, fulfillment_backlog: 2, low_stock: 1, pending_leads: 3, pending_reviews: 0, currency: "VND" };
  }
  if (pathname.endsWith("/health")) {
    return { wordpress: true, woocommerce: true, plugins: { "epic-product-mcp": true, "epic-admin-dashboard": true } };
  }
  if (pathname.endsWith("/support/summary")) {
    return { conversations: 4, turns: 18, outcomes: { answered: 12, fallback: 3, error: 1, incomplete: 2 }, averageDurationMs: 1840 };
  }
  if (pathname.endsWith("/audit")) return emptyPage;
  return emptyPage;
}

const server = createServer(async (request, response) => {
  const url = new URL(request.url, "http://127.0.0.1");
  if (url.pathname === "/ops" || url.pathname.startsWith("/ops/")) {
    const config = {
      base: "/ops",
      apiRoot: "/stub-api",
      token: "smoke-token",
      logoutUrl: "/wp-login.php?action=logout",
      user: { id: 1, name: "Smoke Admin", email: "smoke@example.com" },
      version: "smoke",
    };
    const html = `<!doctype html><html lang="vi"><head><meta charset="utf-8" />
<link rel="stylesheet" href="/dist/${entry.css[0]}" />
<script>window.EPIC_OPS=${JSON.stringify(config)};</script>
</head><body><div id="epic-ops-root"></div>
<script type="module" src="/dist/${entry.file}"></script></body></html>`;
    response.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
    response.end(html);
    return;
  }
  if (url.pathname.startsWith("/stub-api/")) {
    response.writeHead(200, { "Content-Type": "application/json" });
    response.end(JSON.stringify(stubFor(url.pathname)));
    return;
  }
  const safe = normalize(url.pathname).replace(/^(\.\.[/\\])+/, "");
  const relative = safe
    .replace(/^\/wp-content\/plugins\/epic-ops-console\/dist\/?/, "")
    .replace(/^\/dist\/?/, "");
  try {
    const body = await readFile(join(dist, relative));
    response.writeHead(200, { "Content-Type": types[extname(safe)] ?? "application/octet-stream" });
    response.end(body);
  } catch {
    response.writeHead(404);
    response.end("not found");
  }
});

await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
const origin = `http://127.0.0.1:${server.address().port}`;

const browser = await chromium.launch();
const page = await browser.newPage();
const problems = [];
page.on("pageerror", (error) => problems.push(`pageerror: ${error.message}`));
page.on("console", (message) => {
  if (message.type() === "error") problems.push(`console: ${message.text()}`);
});
page.on("response", (response) => {
  if (response.status() >= 400) problems.push(`http ${response.status()}: ${response.url()}`);
});

await page.goto(`${origin}/ops`, { waitUntil: "networkidle" });
await page.waitForSelector(".admin-shell", { timeout: 10000 });

const modules = [
  ["/", "Tổng quan"],
  ["/orders", "Đơn hàng"],
  ["/shipments", "Vận chuyển"],
  ["/products", "Sản phẩm"],
  ["/customers", "Khách hàng"],
  ["/support/conversations", "Hội thoại hỗ trợ"],
  ["/leads", "Khách tiềm năng"],
  ["/wholesale-orders", "Bán sỉ"],
  ["/content", "Nội dung"],
  ["/marketing", "Tiếp thị"],
  ["/reviews", "Đánh giá"],
  ["/finance", "Tài chính"],
  ["/settings", "Cài đặt kinh doanh"],
];

for (const [href] of modules) {
  const expected = href === "/" ? "/ops" : `/ops${href}`;
  await page.evaluate((path) => document.querySelector(`.sidebar a[href="${path}"]`).click(), expected);
  await page.waitForFunction((path) => window.location.pathname === path, expected, { timeout: 5000 });
  await page.waitForSelector(".sidebar a", { timeout: 5000 });
  const heading = await page.locator(".content h1").first().textContent().catch(() => "");
  if (!heading) problems.push(`no heading after navigating to ${expected}`);
}

// Deep link: WordPress serves the same shell for every /ops path, the router
// must resolve it without a client navigation.
await page.goto(`${origin}/ops/orders`, { waitUntil: "networkidle" });
await page.waitForSelector(".admin-shell", { timeout: 10000 });
const deepHeading = await page.locator(".content h1").first().textContent().catch(() => "");
if (!deepHeading) problems.push("deep link /ops/orders rendered no heading");
const active = await page.locator('.sidebar a[aria-current="page"]').first().getAttribute("href").catch(() => null);
if (active !== "/ops/orders") problems.push(`deep link highlight wrong: ${active}`);

await browser.close();
server.close();

if (problems.length) {
  console.error("SMOKE FAILED");
  for (const problem of problems) console.error(`- ${problem}`);
  process.exit(1);
}
console.log(`SMOKE OK — ${modules.length} modules rendered, no console/page/http errors`);
