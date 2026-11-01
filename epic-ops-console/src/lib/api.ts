/**
 * Client-side replacement for the retired Next.js BFF (`/api/dashboard/*`).
 *
 * The old BFF attached the dashboard session, mapped a small allowlist of
 * browser paths onto `/wp-json/epic-admin/v1/*` and decoded the two base64
 * export payloads. All three jobs live here, so the ported components keep
 * their original URLs untouched.
 */
import { EXPORT_ROUTES, mapToWordPress } from "@/lib/route-map";

const API_ROOT = (window.EPIC_OPS?.apiRoot ?? "/wp-json/epic-admin/v1").replace(/\/$/, "");

function decodeBase64(value: string): Uint8Array {
  const binary = window.atob(value);
  const bytes = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index += 1) bytes[index] = binary.charCodeAt(index);
  return bytes;
}

/** A dashboard session is minted per page render; reload once to get a fresh one. */
function reloadOnceForExpiredSession() {
  try {
    const key = "epic-ops-reload";
    const last = Number(window.sessionStorage.getItem(key) ?? 0);
    if (Date.now() - last < 5000) return;
    window.sessionStorage.setItem(key, String(Date.now()));
  } catch {
    /* sessionStorage unavailable: reload anyway */
  }
  window.location.reload();
}

export async function apiFetch(input: string, init: RequestInit = {}): Promise<Response> {
  const queryIndex = input.indexOf("?");
  const rawPath = queryIndex === -1 ? input : input.slice(0, queryIndex);
  const search = queryIndex === -1 ? "" : input.slice(queryIndex + 1);
  const relative = rawPath.replace(/^\/api\/dashboard\/?/, "").replace(/^\/+/, "");
  const wordpressPath = mapToWordPress(relative, init.method ?? "GET");

  const headers = new Headers(init.headers);
  const token = window.EPIC_OPS?.token;
  if (token) headers.set("X-Epic-Admin-Session", token);
  if (init.body !== undefined && !headers.has("Content-Type")) headers.set("Content-Type", "application/json");

  const response = await fetch(`${API_ROOT}/${wordpressPath}${search ? `?${search}` : ""}`, {
    ...init,
    headers,
    credentials: "omit",
    cache: "no-store",
  });

  if (response.status === 401) reloadOnceForExpiredSession();

  const exportRoute = EXPORT_ROUTES.has(wordpressPath) || /^mail-campaigns\/(?:\d+\/export|sample)$/.test(wordpressPath);
  if (!exportRoute || !response.ok) return response;

  const payload = (await response.json()) as { filename?: string; content_type?: string; data_base64?: string };
  if (!payload.filename || !payload.data_base64) {
    return new Response(JSON.stringify({ error: "Invalid export response" }), {
      status: 502,
      headers: { "Content-Type": "application/json" },
    });
  }

  const contentType = payload.content_type ?? "text/csv; charset=utf-8";
  return new Response(new Blob([decodeBase64(payload.data_base64)], { type: contentType }), {
    status: 200,
    headers: {
      "Content-Type": contentType,
      "Content-Disposition": `attachment; filename="${payload.filename}"`,
      "Cache-Control": "no-store",
    },
  });
}
