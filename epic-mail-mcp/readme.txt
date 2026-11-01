=== EPIC Mail MCP ===
Contributors: epiccoffee
Tags: mcp, abilities, email, smtp, wp-mail-smtp
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Test and send approved email templates through the site's existing WP Mail SMTP configuration, via authenticated WordPress MCP abilities.

== Description ==

This plugin adds MCP abilities that answer one question honestly — can this site send mail? — and then let an authenticated agent send a small set of reviewed templates.

It never configures a transport. Every message goes through `wp_mail()`, so WP Mail SMTP remains the single source of truth for the SMTP host, credentials, From address and debug logging. A second SMTP configuration would drift from WP Mail SMTP and silently bypass its logging.

Abilities in the `epic-mail-mcp` namespace:

* `get-mailer-status` — active mailer, From address, debug logging state, newest recorded error, allowlist size and remaining hourly quota.
* `send-test` — fixed short test message to an allowlisted address, returning the exact transport error on failure.
* `send-template` — render a registered template with placeholder substitution; `dry_run` returns the exact subject, HTML and text without sending.
* `get-send-log` — audit rows, one per send attempt including denied attempts.
* `set-allowlist` — replace the stored allowlist. Ignored when `EPIC_MAIL_MCP_ALLOW` is defined.

There is no ability that accepts a raw HTML body. A caller may only name a registered template. That is the difference between a tool and an abuse vector.

== Security ==

Every ability requires `manage_options`.

Recipients must match the allowlist: exact addresses or `*@domain` wildcards. The allowlist is read from the `EPIC_MAIL_MCP_ALLOW` constant first and from the `epic_mail_mcp_allow` option second. When both are empty, every send is denied with `epic_mail_mcp_recipient_blocked`.

Sends are limited per user to `EPIC_MAIL_MCP_MAX_PER_HOUR` (default 10) over a rolling hour, tracked in the `epic_mail_mcp_rl_{user_id}` transient.

The From address and From name always come from WP Mail SMTP. Only `Reply-To` is caller-controlled, and it must be a valid address.

Every attempt is written to `{prefix}epic_mail_log` — recipient, subject, template, status and error. Denied attempts are recorded too, because a stream of denials is the signal that something is probing.

`define( 'EPIC_MAIL_MCP_DISABLED', true );` unregisters every ability.

There are no attachments in this version.

== Configuration ==

In `wp-config.php`:

```php
define( 'EPIC_MAIL_MCP_ALLOW', 'ops@example.com,*@example.org' );
define( 'EPIC_MAIL_MCP_MAX_PER_HOUR', 10 );
```

Both constants are optional. Without `EPIC_MAIL_MCP_ALLOW`, use the `set-allowlist` ability.

== Templates ==

* `mail-test` — transport proof. Not content-reviewed.
* `hospitality-hotel-intro` (`vi`, `en`) — copy of `BD/hospitality-email/hotel-1.vi.html` and `hotel-1.html`, converted to PHP with `{{name}}`, `{{hotel_name}}`, `{{sender_name}}`, `{{sender_title}}`, `{{epic_email}}` and `{{phone}}` placeholders.

`hospitality-hotel-intro` requires `name` and `hotel_name`. `epic_email` defaults to the WP Mail SMTP From address; `sender_name` defaults to the site name.

Regenerate the hospitality templates from the editorial source with:

```
node scripts/build-hospitality-templates.js
```

== Deliverability ==

The transactional domain already carries order, wholesale, sample-request and newsletter mail. Do not use this plugin for cold outreach or bulk sending on that domain: the first casualty of a damaged reputation is order mail. Use a separate subdomain or an ESP with warm-up for volume outreach.

== Installation ==

1. Upload the `epic-mail-mcp` directory (or its zip) to `/wp-content/plugins/` and activate it.
2. Add `EPIC_MAIL_MCP_ALLOW` to `wp-config.php`, or call `set-allowlist`.
3. Call `get-mailer-status` over MCP to confirm the mailer and From address.
4. Call `send-test` to an allowlisted address.

== Changelog ==

= 1.0.0 =
* Initial release: mailer status, test send, approved templates with dry run, audit log, allowlist and rate limit.
