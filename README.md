# Campus Notes (repaired version)

Security-review lab project. This is the **repaired** version of the intentionally vulnerable starter. Run it only on an isolated machine you control, with synthetic data and a disposable local database. Never expose it to the internet. Local HTTP and PHP's built-in server are for development only.

## Structure

```
public/            web root (only this folder is served)
  index.php        web controller: login, register, logout, note CRUD
  api.php          JSON API: list notes, update a note
  style.css        external stylesheet (lets the CSP block inline styles)
src/
  bootstrap.php    error handling, secure session, security headers
  config.php       settings read from environment variables
  Database.php     PDO connection (real prepared statements, no root fallback)
  Auth.php         register / login / logout / current user
  Csrf.php         CSRF token generation and checking
  NoteRepository.php  all note SQL, always scoped to the owner
  helpers.php      escaping, validation, redirects, JSON responses
templates/home.php HTML view (every dynamic value escaped with e())
bin/reset_lab_users.php  CLI: reset the two synthetic accounts to bcrypt
schema.sql         tables + synthetic users (bcrypt hashes)
```

No Composer packages are used (plain PHP 8 + PDO), so there is no `vendor/` folder or lock file.

## Local setup

1. PHP 8 with `pdo_mysql` (`php -m | grep pdo_mysql`) and a local MySQL/MariaDB server.
2. As a local DB admin, import `schema.sql` into a fresh server (it creates `notes_lab`). Review it first.
3. Create a dedicated account with SELECT, INSERT, UPDATE, DELETE only on `notes_lab`, then set the variables in your shell (see `.env.example`). Bash: `export LAB_DB_USER=... LAB_DB_PASSWORD=...`. PowerShell: `$env:LAB_DB_USER = '...'`. **The app refuses to start without `LAB_DB_USER`; it does not fall back to root.**
4. From this folder run: `php -S 127.0.0.1:8000 -t public` and open `http://127.0.0.1:8000`. Stop with Ctrl+C.
5. Synthetic test accounts (public, throwaway, never reuse):
   - `alex@example.test` / `LabOnly-Alex-123`
   - `sam@example.test` / `LabOnly-Sam-456`

   If you already imported the old MD5 schema, run `php bin/reset_lab_users.php` to switch those two accounts to bcrypt.

## API (same-origin, cookie session)

| Request | Result |
|---|---|
| `GET /api.php` | Your notes. Optional `?user_id=` must equal your own ID (else 403). |
| `GET /api.php?csrf=1` | `{"csrf_token": "..."}` for use on the next POST. |
| `POST /api.php` | JSON `{"id":1,"title":"...","body":"..."}` with headers `Content-Type: application/json` and `X-CSRF-Token`. |

Errors: 401 no session, 403 bad origin/CSRF/other user, 404 note missing or not yours, 400 malformed JSON, 413 too large, 415 wrong content type, 422 invalid fields.

## Known limitations (not production-ready)

- No HTTPS locally. For production: HTTPS, HSTS, and the session cookie `Secure` flag (set automatically when HTTPS is detected, untested here).
- No login rate limiting / lockout, and no password reset or email verification.
- No audit logging beyond PHP's error log; no admin features (the `role` column is stored but unused).
- `LAB_ALLOWED_ORIGINS` must be set correctly if you serve on another host or port.
