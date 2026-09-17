# Plan: bulk email (one template → many subscribers)

Status: **built, in v1.2.0.** Phase 1 + Phase 2 from below, minus the token-based unsubscribe link (kept as manual delete for now, per decision below) — see "What shipped" at the bottom of this file for the as-built specifics.

## What "bulk email" means here

One admin composes a single subject + body once, picks a recipient set from the `epic_newsletter_subscribers` table, and sends that same email to everyone in the set. Not a full ESP: no analytics/opens tracking, no drip sequences, no A/B testing in v1.

## Recommended architecture

**New admin screen** — "Send Newsletter", a second submenu item under WooCommerce next to the existing "Newsletter Subscribers" list.

**Composer**
- Subject field + HTML body (a plain `wp_editor()` textarea is enough — no need for a page-builder).
- Merge tags: `{subscriber_email}`, `{site_title}`, `{unsubscribe_url}` (see below).
- Wrapped in the same WooCommerce email header/footer styling the two existing `WC_Email` classes use, via a new `Epic_Email_Newsletter_Broadcast` class, so a bulk send looks consistent with the confirmation/notification emails already live.
- "Send test to myself" button before committing to the real send.
- Recipient picker: All subscribers / by locale (vi / en, using the `locale` column already captured) / a manually pasted list of addresses. Selecting individual rows via checkboxes in the existing list table is a stretch goal — `class-list-table.php` currently has no bulk-action column by design (see its docblock); adding one is straightforward but is its own small piece of work.

**Sending mechanism — the part that actually needs care**

A naive "loop over every subscriber and call `wp_mail()` inline on page load" breaks in two ways once the list is more than roughly 50–100 addresses: the HTTP request times out before it finishes, and most hosts throttle or block bursts of outbound mail, so a chunk of sends will silently fail or get flagged as spam.

Recommended fix: use **Action Scheduler**, which is already bundled with WooCommerce (no new dependency) — enqueue one background action per small batch (e.g. 20 recipients), spaced a minute or two apart. This:
- survives page timeouts (each batch is its own request, run by WP-Cron),
- naturally throttles the send rate to something a normal SMTP setup can sustain,
- can be resumed/retried per batch if one fails, instead of an all-or-nothing send.

**New storage** — two small tables (same `$wpdb` + `dbDelta` pattern the subscriber table already uses):
- `epic_newsletter_campaigns` — id, subject, body, created_at, status (draft/sending/done).
- `epic_newsletter_campaign_recipients` — campaign_id, subscriber_id, status (pending/sent/failed), sent_at.

This is what makes a send resumable, gives an accurate "312 sent, 2 failed" report afterward, and lets the CSV export tooling just shipped be reused to export a campaign's delivery log the same way the subscriber list exports today.

**Unsubscribe (important, and currently missing)** — right now the *only* way off the list is an admin manually deleting a row in wp-admin. A real marketing send needs a self-serve, no-login unsubscribe link in every email: a signed token (HMAC of the subscriber id, using a server-side secret — not the same shared secret the REST route uses) resolved by a public, unauthenticated endpoint that marks the row unsubscribed. Recommend adding an `unsubscribed_at` column rather than hard-deleting, so history and delivery status are preserved and a "resubscribe" later isn't ambiguous.

**Safety rails**
- Capability gate (`manage_woocommerce`) + nonce on every action, matching every other screen in this plugin.
- A confirmation step ("You are about to email 1,240 subscribers — this can't be undone") before a real send starts.
- A "sending" lock (a transient/option) so a double form-submit can't start two overlapping campaigns to the same list.
- Failures logged via `wc_get_logger()`, same convention as the rest of the plugin.

## The constraint outside the plugin's control

Whatever queues the sends, actual deliverability at any real volume depends on the mail transport already configured for this WordPress install — plain PHP `mail()` or an unconfigured SMTP plugin will get rate-limited or spam-filtered well before a list of a few hundred addresses finishes. Worth confirming (or pairing this feature with) a transactional provider — Brevo, Postmark, Amazon SES, or similar — before relying on it for a real campaign. This is infrastructure the plugin can't fix from inside itself.

## Suggested build order

1. **Phase 1 (small lists, ships fast):** composer screen, send-to-all-or-by-locale, synchronous `wp_mail()` loop with a hard cap (e.g. refuse to run past 150 recipients), no unsubscribe token yet (rely on existing manual delete). Good enough while the subscriber count is small.
2. **Phase 2 (real scale / real marketing use):** Action Scheduler batching, the two new campaign/log tables, the token-based unsubscribe link, and per-campaign CSV export of delivery status. Do this before sending to a list large enough that Phase 1's synchronous loop would time out, or before treating this as an ongoing marketing channel rather than an occasional announcement.

## Open questions before writing code

- Roughly how many subscribers exist today / expected soon — decides whether Phase 1 alone is sufficient for a while.
- Is there already a configured SMTP/transactional provider on the site, or does that need setting up first?
- Bilingual body needed per the `locale` column, or is one template (like the other EPIC emails) Vietnamese-only?
- Self-serve unsubscribe link required now, or is manual removal still acceptable short-term?

## Decisions made (2026-08-22) and what shipped

Answered: build Phase 1 + Phase 2 together; bilingual content (VI required, EN optional per campaign); unsubscribe stays manual-delete for now (a "reply to unsubscribe" line is included in every campaign email, no token link yet); a transactional provider is already configured on the site.

Built into `epic-newsletter-subscription` v1.2.0:
- `includes/class-campaign-store.php` — `epic_newsletter_campaigns` + `epic_newsletter_campaign_recipients` tables. Recipient snapshot is chunked (200 rows/statement) and the `mark_sending()` transition uses a `WHERE status = 'draft'` guard so a double-click can't start the same campaign twice.
- `includes/class-broadcast-sender.php` — Action Scheduler batching (20 recipients/batch, 60s apart, filterable via `epic_newsletter_broadcast_batch_interval`), with a plain WP-Cron fallback if AS functions are somehow unavailable. Per-recipient locale picks `subject_en`/`body_en` if filled in, else falls back to the Vietnamese fields independently per field (a campaign can have an English subject with a Vietnamese body if only the subject was translated).
- `includes/class-email-newsletter-broadcast.php` + `templates/emails/newsletter-broadcast.php` (+ plain-text counterpart) — a `WC_Email` subclass with no settings-page presence (content is per-campaign, not per-site), reusing the store's standard email header/footer. Appends a fixed, locale-matched "reply to this email to unsubscribe" line to every campaign send.
- `includes/class-broadcast-admin.php` — new WooCommerce → Send Newsletter screen: compose (VI required, EN optional, `wp_editor()` bodies, all/VI/EN recipient picker) → draft → review screen (preview, recipient count, "send test" to any address, "send now" behind a confirmation checkbox) → background sending → per-campaign CSV/XLSX delivery-log export (extended `class-export.php` to serve both this and the existing subscriber-list export off shared generic writers).

Verified without a live WordPress install: PHP-linted all 24 plugin files; ran the actual `class-campaign-store.php` queries against an in-memory `$wpdb` stand-in (chunked-insert row counts, the `vi` filter correctly including `unknown`-locale subscribers, the sending-guard's atomicity, batch/counter bookkeeping, draft-only delete); ran `class-broadcast-sender.php`'s batch loop against that same store with a fake `WC_Email`, confirming batch-count math, per-field subject/body locale fallback, and error logging on a simulated send failure. Not verified: `wp_editor()` rendering, the real Action Scheduler functions, and real `WC_Email`/`wc_mail()` delivery — those need the actual WordPress+WooCommerce install this plugin already assumes.

Not built (deliberately deferred, per the "manual delete for now" decision): the token-based self-serve unsubscribe endpoint and `unsubscribed_at` column described earlier in this plan. Revisit if bulk sends become frequent/large enough that manual list hygiene stops being practical, or if a subscriber complains about not being able to unsubscribe via a direct link.

Still needed on the live site: activate/update the plugin (deactivate+reactivate or let the version-check on `plugins_loaded` run the new `Epic_Newsletter_Campaign_Store::install()`) so the two new tables get created, then compose and send-test a first campaign before relying on it for a real send.
