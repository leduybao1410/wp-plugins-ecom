# Publishing to WordPress (admin.epicroastery.coffee)

## Auth

Two ways to authenticate against the WP REST API here, both already proven to work on this site:

1. **JWT** (`POST {WC_URL}/wp-json/jwt-auth/v1/token` with `username`/`password` form fields → returns a `token`, sent afterwards as `Authorization: Bearer <token>`). This is what the existing bi-weekly origin-spotlight automation uses, with the real WP account login.
2. **HTTP Basic Auth with a WordPress Application Password** (`Authorization: Basic base64(username:app_password)`, or simply `curl -u "username:app password"` — curl handles the encoding). This skill's automation was set up with a **dedicated Application Password** named `epic-content-writer-automation`, scoped to one WP user and revocable independently from the account's main password from wp-admin → Users → Profile → Application Passwords, without touching the site login. Prefer this method for anything this skill runs — it's simpler (no token step) and safer to rotate.

Either way, the WP site URL is `WC_URL` (already `https://admin.epicroastery.coffee` in `website/.env` on the user's machine — reuse that var name for consistency with the rest of the codebase, don't invent a new one).

**Important environment note:** if you're running from the cloud sandbox (e.g. a scheduled/triggered session, not through the user's own machine), Python's `requests`/`urllib` TLS handshake gets rejected by this host's WAF (`SSLEOFError`). **Always use `curl`** for the actual HTTPS call — Python is fine for local JSON parsing/building once you already have the response text in hand.

## Creating the VI + EN pair as Drafts

No multi-language plugin is installed on this WP — each "topic" is two sibling posts sharing a base slug: `{slug}` is the Vietnamese/master post, `{slug}-en` is the English sibling. This is exactly the convention the existing origin-spotlight `/news` automation already uses, and `src/lib/news.ts` on the frontend already knows how to pair them up by this suffix — don't invent a different pairing scheme.

Minimal request per post:

```bash
curl -s -u "USERNAME:APP_PASSWORD" -X POST "$WC_URL/wp-json/wp/v2/posts" \
  --data-urlencode "title=<post title>" \
  --data-urlencode "slug=<slug or slug-en>" \
  --data-urlencode "content=<full HTML/markdown body>" \
  --data-urlencode "excerpt=<meta description>" \
  --data-urlencode "status=draft" \
  --data-urlencode "categories[]=<category id from SKILL.md table>" \
  --data-urlencode "tags[]=<tag id>" \
  --data-urlencode "featured_media=<media id from references/images.md>"
```

`featured_media` sets the post's featured image (uses an id already uploaded to the Media Library — see `references/images.md` for the per-pillar pool). This is in addition to, not instead of, embedding an `<img>` tag inline in the `content` HTML itself — every post needs both: the inline photo for visual interest in the body, and the featured image for the post thumbnail/social preview.

(`--data-urlencode` repeated for each tag id; WP also accepts a JSON body with `Content-Type: application/json` if you'd rather build the payload in Python and pipe it to `curl -d @payload.json` — either works, just make sure the network call itself goes through curl.)

Default `status=draft` unless the user explicitly asked for auto-publish in the current conversation. A draft is visible in wp-admin for the user to review, edit, and publish manually.

## Resolving tag IDs

Tags should be reused where they already fit:

```bash
curl -s "$WC_URL/wp-json/wp/v2/tags?search=<name>"
```

If nothing matches, create it:

```bash
curl -s -u "USERNAME:APP_PASSWORD" -X POST "$WC_URL/wp-json/wp/v2/tags" \
  --data-urlencode "name=<tag name>"
```

## Reading what's already posted (for the rotation logic in SKILL.md)

```bash
curl -s "$WC_URL/wp-json/wp/v2/posts?categories=84,85,86,87,88,89&per_page=100&_fields=id,slug,categories,status"
```

This is a public GET (no auth needed) and includes drafts only if the request is authenticated — so if you need drafts counted too (to avoid re-picking a topic that's already drafted but not yet published), send it with the same `-u "USERNAME:APP_PASSWORD"` credential.

## A quirk of this WP install: PHP warnings leak into REST responses

Occasionally (seen while building this skill) a `wp/v2/posts` POST that actually succeeds still returns raw PHP `Warning:` HTML *before* the JSON body — a naive `json.load()` on the raw response throws `JSONDecodeError` even though the post was created. Separately, the connection can also just drop and return a genuinely empty body (transient). Both look identical to a script. Handle this by (1) stripping everything before the first `{`/`[` before parsing JSON, and (2) never blindly retrying a POST on an empty response — check `GET /wp-json/wp/v2/posts?slug=<slug>&status=any` first, since a blind retry can create a duplicate post with an auto-suffixed slug (`-2`) if the original request actually landed. `scripts/publish_post.sh` already implements both.

## `scripts/publish_post.sh`

A ready-made wrapper around the above — see that file for usage. It takes the VI and EN title/content/excerpt as arguments (or files), the category id, and a comma-separated tag list, resolves tag ids (creating any that don't exist), posts both as drafts, and prints the resulting edit URLs.
