# EPIC Ops Console

The EPIC operations dashboard, served by WordPress itself at
`https://admin.epicroastery.coffee/ops`. This replaces the separate Next.js host
(`ops.epicroastery.coffee`) for day-to-day use: same origin, same WordPress
login, same business API.

## Landing page

A bare visit to the domain root (`/`) no longer renders the theme blog:

- anonymous → `302` to `wp-login.php?redirect_to=…/ops`, so signing in lands on the console;
- signed-in administrator → `302` to `/ops`;
- signed-in non-administrator (shop manager, customer) → left on the normal site.

Only the exact root path with an empty query string is affected. `/wp-admin`, `wp-login.php`, `/wp-json`, feeds, permalinks, `/?p=…` shortlinks and any other path keep normal WordPress behaviour. Disable it with the `epic_ops_redirect_home` filter or the `EPIC_OPS_DISABLE_HOME_REDIRECT` constant.

## How it works

- A rewrite rule sends `/ops` and `/ops/<path>` to `Epic_Ops_Console::dispatch()`.
- `dispatch()` requires a signed-in WordPress user with `manage_options`; anyone
  else is bounced through `wp-login.php` and returned to the requested path.
- `Epic_Ops_Session::mint()` creates the same opaque session row the adapter's
  OAuth exchange would have created, so every request to
  `/wp-json/epic-admin/v1/*` keeps using `X-Epic-Admin-Session` with its eight
  hour expiry, 30 minute idle timeout, `manage_options` re-check, password-reset
  revocation and audit trail.
- The React bundle is served from `dist/`, resolved through the Vite manifest so
  asset names stay hashed.
- No theme header/footer is loaded; the document is standalone with its own CSP,
  `no-store` headers and `noindex`.

## Build and deploy

```bash
cd wordpress-plugins/epic-ops-console
npm install
npm run build          # writes dist/ + dist/.vite/manifest.json
cd ..
zip -r epic-ops-console.zip epic-ops-console \
  -x 'epic-ops-console/node_modules/*' -x 'epic-ops-console/src/*' -x '*.DS_Store'
```

Upload with the `epic-plugin-manager/upload-plugin` ability
(`overwrite: true`, `activate: true`).

## Front-end source of truth

The components under `src/components` and helpers under `src/lib` are ports of
`admin-dashboard/src/*`. Differences are limited to:

- `next/link` and `next/navigation` → `src/lib/router.tsx` (a `pushState` router
  that hides the `/ops` prefix).
- `fetch("/api/dashboard/...")` → `apiFetch()` in `src/lib/api.ts`, which maps the
  old BFF paths onto `epic-admin/v1` and decodes the base64 export payloads.
- `LogoutButton` links to `wp_logout_url()` instead of the removed `/api/auth`.
- The dashboard shell, routing and auth gate live in `src/App.tsx` + PHP.

When `admin-dashboard/src` changes, port the same change here until the Next app
is retired.

## Requirements

- EPIC Admin Dashboard API plugin active (provides `Epic_Admin_Dashboard` and
  `Epic_Admin_Security`).
- Pretty permalinks enabled (`/wp-json/` reachable).
