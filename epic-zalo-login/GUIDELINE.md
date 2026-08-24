# Guideline: "Sign in with Zalo" on WordPress (epic-zalo-login)

Status: **v0.1.0 (sketch).** Code is PHP-linted and the OAuth flow was revalidated against a live production integration (the ONTO owner-web's Zalo login). It has **not** yet been run against a real WordPress + Zalo app — see "Before you ship" at the bottom.

This document is the full operational guideline for setting up Zalo Login (OAuth v4) on a WordPress site. The plugin does what "Sign in with Google" does, but with Zalo accounts.

---

## 1. What the plugin does

- Renders a "Sign in with Zalo" button anywhere via the `[epic_zalo_login]` shortcode.
- Implements the Zalo Login v4 flow **server-side**: OAuth 2.0 with PKCE (the app secret never reaches the browser).
- On first login, auto-creates a WordPress user and stores the Zalo numeric ID in user meta (`epic_zalo_id`), so the same Zalo account always maps to the same WordPress user — no duplicate accounts.
- Stores the Zalo avatar URL (`epic_zalo_avatar`) for themes that want it.
- Uses nonce-based `state` (CSRF protection) and transient-stored PKCE verifiers.

## 2. Files

| File | Purpose |
| --- | --- |
| `epic-zalo-login.php` | Main plugin file — hooks, shortcode, `admin-post.php` routes. |
| `includes/class-settings.php` | Settings → Zalo Login screen (App ID, Secret Key, auto-create toggle) and the exact Callback URL. |
| `includes/class-oauth.php` | The Zalo OAuth v4 client — PKCE, authorize URL, token exchange, profile fetch. |
| `includes/class-login.php` | Login button, callback handler, Zalo-ID → WordPress-user mapping. |

## 3. Prerequisites

1. **A Zalo developer account** — sign in at https://developers.zalo.me (a normal Zalo account works; you verify your phone number).
2. **A Zalo app** created under "Quản lý ứng dụng" (App Management).
3. **Zalo Login approval** — the app must be submitted and approved by Zalo before it can be used in production. Development/testing with your own account works before approval.
4. **A WordPress site** — this plugin needs only core WordPress (no WooCommerce dependency).

## 4. Zalo-side setup (developers.zalo.me)

### 4.1 Create the app
1. developers.zalo.me → **Quản lý ứng dụng** → **Tạo ứng dụng** (Create app).
2. Choose the app type appropriate to a login use case ("Ứng dụng Web" / website app if prompted).
3. After creation you'll see the app's detail screen with:
   - **App ID** (`app_id`) — a numeric ID.
   - **App Secret Key** (`secret_key`) — click reveal to copy. Treat it like a password; it authenticates every server-to-server call.

### 4.2 Configure "Đăng nhập" (Login)
Open the app's **Đăng nhập** section and whitelist the redirect URL:

1. Install & activate the plugin on WordPress first, so the callback URL exists.
2. Open **Settings → Zalo Login** in wp-admin and copy the **Callback URL** shown there. It looks like:
   `https://yourdomain.com/wp-admin/admin-post.php?action=epic_zalo_callback`
3. Paste that exact string into the Zalo app's **Callback URL** field under **Đăng nhập**.
4. Save. Zalo matches the redirect URI **exactly** (scheme, host, path, query) — a trailing slash or an http/https mismatch will cause an `invalid redirect_uri` error at login.

### 4.3 Request approval
- Submit the app for **review/approval** before real users log in.
- Default profile fields returned on login are `id`, `name`, `picture` — these need no extra permission.
- **Sensitive fields (phone number, email, birthday) require separate scope approval** from Zalo. This plugin does not depend on them; it works with just `id/name/picture` and leaves `user_email` empty when Zalo provides none (the site admin can fill it in later).
- During development, logins from accounts authorized on the app work without full approval.

### 4.4 Where the values live
Keep **App ID** and **App Secret Key** in WordPress only — **Settings → Zalo Login**. The secret is stored as a WP option and is only ever sent in an HTTP header (`secret_key`) directly to Zalo's token endpoint; it never appears in URLs, HTML, or client-side code.

## 5. WordPress-side setup

1. Upload the `epic-zalo-login` folder to `/wp-content/plugins/` (or install the zip via Plugins → Add New).
2. **Activate** the plugin.
3. **Settings → Zalo Login**:
   - Paste **App ID**.
   - Paste **App Secret Key**.
   - Choose whether to **create users automatically** (recommended on, so new Zalo users get a WordPress account on first login; off = only already-linked accounts can sign in).
4. Save. The screen also shows the **Callback URL** you must have whitelisted in §4.2.

## 6. Putting the button on the site

Insert the shortcode anywhere a button is wanted:

```
[epic_zalo_login]
```

Common placements:
- On the login/registration page.
- Inside any page/post content.
- In a widget area (via a shortcode widget).

Logged-in visitors see their display name + a log-out link instead of the button. If the plugin is not yet configured, the shortcode renders a short "not configured" note (no crash).

## 7. How the flow works (for debugging/maintenance)

```
Visitor clicks button
  → GET admin-post.php?action=epic_zalo_login   (nonce-checked)
  → plugin generates code_verifier (43 chars), stores it in a transient
    keyed by state, sends only the SHA-256 hash as code_challenge
  → 302 to https://oauth.zaloapp.com/v4/permission?app_id=…&redirect_uri=…&code_challenge=…&state=…
  → Zalo consent screen → user approves
  → browser returns to callback URL with ?code=…&state=…
  → plugin verifies state (nonce), deletes the transient
  → POST https://oauth.zaloapp.com/v4/access_token
      header: secret_key: <App Secret Key>
      body: code, app_id, grant_type=authorization_code, code_verifier
  → GET https://graph.zalo.me/v2.0/me?fields=id,picture,name
      header: access_token: <access token>
  → plugin looks up WordPress user by epic_zalo_id meta
      → found:  log them in
      → missing: auto-create (unless disabled) and link
  → wp_set_auth_cookie() → redirect to site home
```

Endpoint reference (matches Zalo's official docs):

| Step | Endpoint |
| --- | --- |
| Authorize (redirect user) | `https://oauth.zaloapp.com/v4/permission` |
| Exchange code → token | `https://oauth.zaloapp.com/v4/access_token` (POST) |
| Profile | `https://graph.zalo.me/v2.0/me` |

Constraints worth knowing:
- The authorization `code` is single-use and expires after **10 minutes**.
- The access token is valid for **1 hour**; it is used once, on the spot, and is **not stored** — no refresh-token handling is needed for login.
- PKCE `code_challenge` = `Base64URL(SHA-256(ASCII(code_verifier)))`, base64 **without** padding.

## 8. Testing checklist

Before announcing it to real users:

- [ ] App created and Login configured at developers.zalo.me.
- [ ] Callback URL whitelisted and **exactly** matching Settings → Zalo Login.
- [ ] App ID + Secret Key saved in the plugin settings.
- [ ] Click the button from a fresh (incognito) browser → Zalo consent screen appears.
- [ ] Approve → returned to the site → logged in, no error.
- [ ] Log out, click again → same WordPress user is reused (no duplicate account).
- [ ] A second Zalo account → creates a second, distinct WordPress user.
- [ ] User list shows the new users with display names (and avatars stored under `epic_zalo_avatar`).
- [ ] Verify `wp-admin` shows `epic_zalo_id` meta on those users (Users → the user → bottom of the profile, or via a user-meta plugin).
- [ ] Revoking/expired code path: wait >10 min between consent and callback → clean "session expired" error, no crash.

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `invalid redirect_uri` at consent | Callback URL in Zalo ≠ plugin's callback URL (protocol/host/path/query mismatch) | Copy it verbatim from Settings → Zalo Login |
| Login succeeds but user already exists differently | User was previously registered via email/Google | Link is only by Zalo ID; connect accounts manually in wp-admin if needed |
| "No account is linked to this Zalo account" | Auto-create disabled and Zalo ID not previously linked | Enable auto-create, or pre-link the meta manually |
| `Zalo returned no access token` / `invalid code` | Code expired (>10 min) or reused | Start a fresh login |
| Button shows "not configured" | App ID / Secret Key missing | Fill in Settings → Zalo Login |
| `secret_key` errors on token call | Wrong/typo'd Secret Key | Re-copy from the Zalo app's "Secret key" section |
| Empty user email | Zalo profile has no email scope | Optional — admin can add email later |

## 10. Security notes

- The **App Secret Key** never leaves the server: it's sent only as a header to Zalo's token endpoint. Don't put it in client-side JS, page source, or any plugin option that is printed.
- PKCE verifiers live in transients (default 10 min TTL), keyed by the per-login `state`.
- `state` is a WP nonce — verified on the callback, preventing CSRF on the login route (the plugin is stricter here than some production integrations, which skip state verification).
- WP user accounts created via Zalo get a random password and can't be taken over without going through Zalo; the Zalo ID meta is the source of truth for that account's identity.
- If you ever rotate the Zalo App Secret, users are unaffected (the secret is only used at login time), but update the plugin settings immediately.

## 11. Before you ship

This is a v0.1.0 **sketch**. It has not yet been exercised on a live WordPress + Zalo app. Before treating it as production-ready:

1. Install it on the target site and complete §8 against **real** Zalo credentials (a test app or the approved production app).
2. Confirm the Callback URL end-to-end on the site's real domain (not localhost) — this is the most common integration failure point.
3. If you plan to collect phone numbers/emails from Zalo, request those scopes and extend `class-oauth.php`'s `fields` param (`id,name,picture` today) and `find_or_create_user()` to map them.
4. Consider an error-notice page instead of `wp_die()` for a friendlier UX, and/or honoring a "redirect back to original page" query param instead of always redirecting to the site home.
