# EPIC Admin Dashboard API — security release 0.1.8

The private ops BFF remains the only cross-host client of `epic-admin/v1`. Browser requests from other origins are rejected and this namespace does not emit CORS permission. Other storefront namespaces are unchanged.

Opaque sessions and authorized PKCE grants are bound to the WordPress password and a per-user revocation epoch. Password resets/changes and WordPress destroy-all-sessions revoke ops access. The nonce-protected Settings → EPIC Admin Dashboard button revokes the current administrator's ops sessions. Existing tokens from earlier versions require sign-in again.

Schema migration preserves business records and adds `auth_version` to sessions/grants plus `epic_admin_limits`. The migration marker is recorded only after the required columns/table exist. Atomic minute limits are 10 per source / 60 global for grant creation and 30 per source / 120 global for exchange. Source fingerprints require the existing client secret; storage failures deny authentication. Expired security records are cleaned hourly.

Private API responses are no-store. Initial HSTS lasts 300 seconds on the HTTPS admin host. Login/wp-admin enforce framing, object and base restrictions; the stricter WordPress script policy is initially report-only because core/plugin inline scripts require compatibility work. MFA enrollment is an account configuration step and is not claimed by this release.

Standalone behavior test: `php tests/security.test.php`. Real WordPress/MySQL test: run `tests/integration.php` only in the explicitly guarded disposable `epic-security-release-wp` environment. Production packages exclude the tests directory.

The authoritative workspace memory is `agents/plugins-rag.md`; a dated snapshot is included under `docs/plugins-rag.md` for release review.
