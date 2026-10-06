# OWASP ZAP: results and accepted alerts

`scripts/security-scan.sh zap` (CI job `security`, or `make security-scan
what=zap` against a running stack) signs in as a dedicated test Director
General through the real password and TOTP flow
(`scripts/zap/session.php`, never in production), then runs the plan in
`scripts/zap/plan.yaml`:

- requests the main pages, then spiders the application with GET only (no
  form posts, so nothing is changed);
- passive scan of every response;
- active scan limited to read-only GET routes with query parameters
  (`/search`, `/incidents`, `/threats`, `/flashpoints` lists), at most 10
  minutes.

The build fails on any **high**-risk alert not listed in
`scripts/zap/accepted.txt`. Medium and low alerts are printed for review.
Reports (`zap-report.json`, `zap-report.html`) are uploaded as the
`security-reports` artifact.

## Latest local run (6 October 2026, ZAP 2.17.0, Phase 8B)

No high-risk alerts.

| Risk | ID | Alert | Outcome |
|---|---|---|---|
| Medium | 90003 | Sub Resource Integrity attribute missing | **Fixed.** Laravel's built-in `/up` health page loaded Bunny Fonts and the Tailwind CDN script, an air-gap violation. Replaced by `App\Http\Controllers\HealthController` (plain text, no session); covered by `HealthCheckTest`. |
| Low | 10017 | Cross-domain JavaScript source file inclusion | **Fixed.** Same cause: the old `/up` page. |
| Medium | 10055 | CSP: `style-src 'unsafe-inline'` | **Accepted.** Scripts are nonce-only (`script-src 'self' 'nonce-…'`, no `unsafe-inline`, no `unsafe-eval`). Styles need `unsafe-inline` because the toast library (sonner) and Radix's scroll lock (react-style-singleton) inject `<style>` elements without a nonce. Inline styles cannot run script; the residual risk is CSS-based data exfiltration, which `default-src 'self'` and `img-src 'self' data: blob:` limit to the same origin. Revisit if those libraries gain nonce support. |
| Low | 10055 | CSP notice: `report-uri` is deprecated in favour of `report-to` | **Accepted.** `report-uri` is still the only reporting directive Firefox honours; `report-to` needs the Reporting API endpoint configuration. Add `report-to` alongside it when browser support is uniform. |
| Low | 10010 | Cookie without HttpOnly: `XSRF-TOKEN` | **Accepted, by design.** Laravel's CSRF token cookie must be readable by the frontend, which sends it back as the `X-XSRF-TOKEN` header. It holds only the CSRF token (encrypted), is `SameSite=Lax`, and is `Secure` when served over HTTPS. The session cookie is HttpOnly. |
| Informational | 10109 | Modern web application | Informational: the app is a single-page application (Inertia). |
| Informational | 10112 | Session management response identified | Informational: ZAP recognised the session cookie it was given. |

## Adding an accepted alert

Only after review, and never for a high-risk alert without the client's
security officer's agreement: add the plugin ID to
`scripts/zap/accepted.txt` with a one-line reason, and a row above with
the full reasoning and the date.
