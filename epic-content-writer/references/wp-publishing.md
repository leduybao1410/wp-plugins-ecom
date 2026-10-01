# Publishing to WordPress (admin.epicroastery.coffee)

## Auth

Two ways to authenticate against the WP REST API here, both already proven to work on this site:

1. **JWT** (`POST {WC_URL}/wp-json/jwt-auth/v1/token` with `username`/`password` form fields → returns a `token`, sent afterwards as `Authorization: Bearer <token>`). This is what the existing bi-weekly origin-spotlight automation uses, with the real WP account login.
2. **HTTP Basic Auth with a WordPress Application Password** (`Authorization: Basic base64(username:app_password)`, or simply `curl -u "username:app password"` — curl handles the encoding). This skill's automation was set up with a **dedicated Application Password** named `epic-content-writer-automation`, scoped to one WP user and revocable independently from the account's main password from wp-admin → Users → Profile → Application Passwords, without touching the site login. Prefer this method for anything this skill runs — it's simpler (no token step) and safer to rotate.

Either way, the WP site URL is `WC_URL` (already `https://admin.epicroastery.coffee` in `website/.env` on the user's machine — reuse that var name for consistency with the rest of the codebase, don't invent a new one).

**Important environment note:** if you're running from the cloud sandbox (e.g. a scheduled/triggered session, not through the user's own machine), Python's `requests`/`urllib` TLS handshake gets rejected by this host's WAF (`SSLEOFError`). **Always use `curl`** for the actual HTTPS call — Python is fine for local JSON parsing/building once you already have the response text in hand.

## Creating the VI master + 6 locale siblings as Drafts

No multi-language plugin is installed on this WP — each "topic" is **7 sibling posts sharing a base slug**: `{slug}` is the Vietnamese/master post, and `{slug}-en`, `{slug}-ru`, `{slug}-hi`, `{slug}-zh`, `{slug}-ko`, `{slug}-ja` are the English, Russian, Hindi, Chinese (Simplified), Korean, and Japanese siblings. This is exactly the convention the existing origin-spotlight `/news` automation already uses, and `src/lib/news.ts` on the frontend already knows how to pair them up by this suffix — don't invent a different pairing scheme and don't skip a locale.

| Locale | Slug suffix | Script |
|---|---|---|
| English | `-en` | `scripts/publish_post.sh` (VI + EN pair) |
| Russian | `-ru` | `scripts/publish_translation.sh --locale ru` |
| Hindi | `-hi` | `scripts/publish_translation.sh --locale hi` |
| Chinese (Simplified) | `-zh` | `scripts/publish_translation.sh --locale zh` |
| Korean | `-ko` | `scripts/publish_translation.sh --locale ko` |
| Japanese | `-ja` | `scripts/publish_translation.sh --locale ja` |

All 7 share the same category and tags; each may reuse the same `featured_media` id (or a different photo from the same pillar pool for visual variety). Localize the full body (headings, prose, excerpt, title, internal links) per locale — the same HTML structure, just translated.

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

For Codex scheduled runs, use `python3 scripts/with_wp_env.py -- <command>` to load only `WC_URL`, `WP_USER`, and the WordPress password variable from `website/.env` into the child process. Example: `python3 scripts/with_wp_env.py -- bash scripts/publish_post.sh ...`. The wrapper never prints credentials; do not source the full `.env` file in a command or log.

Default `status=draft` unless the user explicitly asked for auto-publish in the current conversation. A draft is visible in wp-admin for the user to review, edit, and publish manually.

## Publishing one scheduled seven-language set

The Codex Tuesday/Thursday schedule has explicit user authorization to publish one complete set per week after the editorial gates in `SKILL.md` pass. Keep `publish_post.sh` and `publish_translation.sh` draft-first; the scheduled publisher operates only on the exact Tuesday manifest and defaults to a dry run.

```bash
python3 scripts/publish_topic_set.py --manifest reports/scheduled/YYYY-MM-DD-<base-slug>.json
python3 scripts/publish_topic_set.py --manifest reports/scheduled/YYYY-MM-DD-<base-slug>.json --apply
```

The publisher loads `WC_URL`, `WP_USER`, and `WP_APP_PASSWORD` or `WP_PASSWORD` from the environment or `website/.env`. It requires a recent manifest and exactly seven IDs/slugs; confirms all posts are drafts in one EPIC category, with body content and both inline/featured images; then updates status via authenticated `curl`. It publishes the set sequentially because WordPress REST has no atomic seven-post operation. If any publish or public verification step fails, it returns changed posts to draft and verifies rollback, reporting any IDs that need attention. On success it waits for the storefront's 60-second WordPress cache, then checks all seven public URLs, self-canonical links, and sitemap entries. Never print credentials or include them in reports.

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

## Reading the archive for topic selection and duplicate checks

```bash
curl -s "$WC_URL/wp-json/wp/v2/posts?categories=84,85,86,87,88,89&per_page=100&_fields=id,slug,categories,status"
```

Use this inventory to compare query intent with existing content and to count master topics toward the rolling seven-day cap. Paginate through `X-WP-TotalPages`. Public requests include published posts; use the configured authenticated request when drafts must be included in the cap check. Never print or commit credentials.

## A quirk of this WP install: PHP warnings leak into REST responses

Occasionally (seen while building this skill) a `wp/v2/posts` POST that actually succeeds still returns raw PHP `Warning:` HTML *before* the JSON body — a naive `json.load()` on the raw response throws `JSONDecodeError` even though the post was created. Separately, the connection can also just drop and return a genuinely empty body (transient). Both look identical to a script. Handle this by (1) stripping everything before the first `{`/`[` before parsing JSON, and (2) never blindly retrying a POST on an empty response — check `GET /wp-json/wp/v2/posts?slug=<slug>&status=any` first, since a blind retry can create a duplicate post with an auto-suffixed slug (`-2`) if the original request actually landed. `scripts/publish_post.sh` already implements both.

## `scripts/publish_post.sh`

A ready-made wrapper around the above — see that file for usage. It takes the VI and EN title/content/excerpt as arguments (or files), the category id, and a comma-separated tag list, resolves tag ids (creating any that don't exist), posts both as drafts, and prints the resulting edit URLs.

## Safe content updates and backfills

Use `scripts/update_post_content.py` for an existing article. It only changes the post body; it leaves title, excerpt, slug, category, tags, featured image, and status untouched. The command is preview-only unless `--apply` is supplied. It requires the post ID and the `modified_gmt` value captured during editorial review, checks that value before updating, and checks it again immediately before the write. It saves the original REST post JSON under `~/.local/share/epic-content-writer/backups/` before the write and verifies the body plus slug, status, category, tags, title, excerpt, and featured image after the write.

```bash
python3 scripts/update_post_content.py --inventory
python3 scripts/update_post_content.py --post-id 123 --expected-modified-gmt '2026-09-20T12:30:00' --content-file revised.html
python3 scripts/update_post_content.py --post-id 123 --expected-modified-gmt '2026-09-20T12:30:00' --content-file revised.html --apply
```

For a restore, use `--restore-backup <backup.json>` with the current expected `modified_gmt`; preview first, then pass `--apply`. Inventory exports include all posts visible to the authenticated user in categories 84–89, sort by ascending post ID to keep pagination stable, and abort if IDs repeat across pages. They preserve the REST response for review. The script uses `curl` for HTTPS and reads credentials from the environment or `website/.env` without printing them. Never add the inventory or backup output to Git.

For bulk edits, review and update by topic, in batches of no more than five topics. Save a dated report under `reports/` with MCP source/date, article ID and slug, price snapshots or an explicit “not applicable/pending” status, metadata/status checks, and backup paths. Re-fetch MCP product data before each batch. If a post's `modified_gmt` changes after review, refresh and re-review it instead of forcing the old edit. Keep drafts as drafts and published posts published; the updater omits status from its write payload.
