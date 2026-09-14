# Automatic Deployment — GitHub Actions → SSH → Hostinger

How pushing to `main` gets code onto the live Hostinger server, replacing
Hostinger's built-in GitHub deployment/import feature (disabled on this
account — "GitHub deployment is temporarily disabled while managing
clients").

```
git push origin main
       ↓
GitHub Actions: test job (PHP 8.3, Composer, PHPUnit)
       ↓ (only if tests pass)
GitHub Actions: deploy job
       ↓
rsync backend/ → Hostinger over SSH  (excludes .env, storage/, vendor/)
       ↓
ssh → composer install --no-dev · migrate --force · optimize:clear · optimize · storage symlink
       ↓
Done
```

The workflow lives at [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml).
It has two jobs: `test` (always runs, on every push and PR-less push to
`main`) and `deploy` (runs only after `test` passes, and only on `main`).
**If a test fails, the workflow stops there — nothing is ever synced to the
server.**

---

## 1. What gets inspected/decided, and why

This setup was written after reading the actual repo, not assumed:

| Fact | Value | Where confirmed |
|---|---|---|
| PHP version | **8.3** | `backend/composer.json` → `"php": "^8.3"` |
| Laravel version | 13 | `backend/composer.json` |
| Production branch | **`main`** | existing branches on both GitHub remotes |
| Test runner | PHPUnit 12, fully in-memory (sqlite `:memory:`, array cache/session, sync queue) | `backend/phpunit.xml` |
| Queue | **`sync`** in production — no persistent worker exists or is possible on Hostinger shared hosting (`exec`/`proc_open` disabled, so `queue:work` can't run as a daemon) | live `.env`, `docs/DEPLOYMENT.md` |
| Redis / Meilisearch | Configured for caching in prod `.env`, but **not required for the app to boot or for tests to pass** — Scout defaults to the DB-only `collection` driver (`SCOUT_DRIVER` isn't set) | `backend/config/scout.php`, `backend/.env` |
| Horizon | Not installed | `composer.json` |
| Frontend build | **Not needed in CI.** The real theme (`backend/public/theme/app.css`/`app.js`) is a pre-built static bundle already committed to git, built from the repo-root `src/app.js` + `vite.config.js`. `backend/`'s own Vite/`resources/js`/`resources/css` setup is unused in production — only Laravel's stock `welcome.blade.php` references it, and that page isn't part of this app | `backend/resources/views/layouts/app.blade.php` loads `theme/app.*`; grep of `@vite` usage |
| Scheduler | **Required** — old-jewellery bidding auto-close (every 5 min) and wallet-credit expiry (daily) depend on it | `backend/bootstrap/app.php` → `withSchedule()` |
| `storage:link` | **Fails on Hostinger** — the command shells out via `exec()`/`proc_open`, which shared hosting disables | `docs/DEPLOYMENT.md` (local-only file, not in git) |
| Uploaded media location | `storage/app/private/original-images` (product/banner/old-jewellery photos & videos) and `storage/app/public` | `backend/config/filesystems.php` |

Because of the frontend-build finding, the workflow does **not** run `npm
install`/`npm run build` — there is nothing for it to build that the site
actually serves. If you ever do change `src/app.js` / `src/app.css` (the
repo-root theme source), you still rebuild and commit the result locally
before pushing, the same way this project already works:

```bash
cd /path/to/estele-jewellery      # repo root, not backend/
npm run build
cp dist/app.css dist/app.js backend/public/theme/
git add backend/public/theme/app.css backend/public/theme/app.js dist/
git commit -m "..."
```

---

## 2. One-time setup

Do these once, in order. Nothing here runs automatically — GitHub Actions
only starts working after every step below is complete.

### 2.1 Generate a dedicated SSH deploy key

Don't reuse your personal SSH key. Generate one just for this workflow, on
your own machine (not on the server):

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy@estele-jewellery" -f ~/.ssh/estele_deploy_key -N ""
```

This creates two files:
- `~/.ssh/estele_deploy_key` — the **private** key. This goes into the
  `HOSTINGER_SSH_KEY` GitHub secret (step 2.5) and nowhere else. Never
  commit it, paste it into chat, or email it.
- `~/.ssh/estele_deploy_key.pub` — the **public** key. This goes on the
  Hostinger server (next step).

### 2.2 Authorize the public key on Hostinger

**Find your SSH details** in hPanel: **Advanced → SSH Access** (or
**Hosting → Manage → Advanced → SSH Access** depending on the hPanel
layout). You'll see:

- **Host / IP** — e.g. `123.45.67.89`
- **Port** — Hostinger shared hosting typically uses a non-standard port
  like `65002`, not the default `22`
- **Username** — your hosting account username, e.g. `u123456789`

Add the public key to the server's `authorized_keys`. Either:

**Option A — `ssh-copy-id`** (simplest, asks for your existing password once):
```bash
ssh-copy-id -i ~/.ssh/estele_deploy_key.pub -p <PORT> <USERNAME>@<HOST>
```

**Option B — manual**, if `ssh-copy-id` isn't available:
```bash
cat ~/.ssh/estele_deploy_key.pub | ssh -p <PORT> <USERNAME>@<HOST> \
  "mkdir -p ~/.ssh && chmod 700 ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
```

**Verify it works before continuing:**
```bash
ssh -i ~/.ssh/estele_deploy_key -p <PORT> <USERNAME>@<HOST> "echo connected"
```
You should see `connected` with **no password prompt**. If it still asks
for a password, the key wasn't added correctly — fix this before moving on,
since GitHub Actions has no way to answer an interactive password prompt.

### 2.3 Find the Laravel deployment path

SSH in and locate the exact directory that contains `artisan` and
`composer.json` — this is what `HOSTINGER_DEPLOY_PATH` must point to.

```bash
ssh -p <PORT> <USERNAME>@<HOST>
find ~/domains -maxdepth 4 -name artisan 2>/dev/null
```

A path of this shape on Hostinger typically looks like:
```
domains/<your-domain>/public_html/<app-folder>
```

The **absolute** path is typically `/home/<USERNAME>/domains/...` — either
the absolute form or the `~`-relative form (`domains/...`, without a
leading slash, resolved from your SSH login's home directory) works, since
the workflow only ever does `cd "$DEPLOY_PATH"` over an already-authenticated
SSH session. Confirm which form you're using and use it consistently.

**Do not point this at the repo root or at `public/`.** It must be the
directory that directly contains `artisan`, `composer.json`, `app/`,
`bootstrap/`, `storage/`, and `public/` as a subdirectory — the workflow
rsyncs into this path and runs `php artisan ...` from inside it.

### 2.4 Configure the subdomain's document root

The live domain/subdomain must serve `public/` inside the Laravel project,
**not** the project root — otherwise `.env`, `app/`, `storage/`, and every
other non-public file become directly downloadable over HTTP.

In hPanel: **Domains** (or **Subdomains**) → find the domain/subdomain
serving this app → **Manage** → set/confirm the document root as:

```
domains/<your-domain>/public_html/<app-folder>/public
```

i.e. exactly `<HOSTINGER_DEPLOY_PATH>/public`, never
`<HOSTINGER_DEPLOY_PATH>` itself. If this is already configured correctly
(it should be, on an already-working site), you're only verifying it —
don't change it if it already points at `.../public`.

**Verify from outside:**
```bash
curl -sI https://your-domain/.env
# expect: 404 (Laravel's router doesn't serve it) — NOT the raw file contents.
# If you see APP_KEY= or DB_PASSWORD= in the response body, the document
# root is wrong. Fix it immediately before proceeding.
```

### 2.5 Create the GitHub Secrets

In the GitHub repo (whichever one you're deploying from — `origin` or
`hostinger`, see [§ 5](#5-two-github-remotes--which-one-deploys)) go to
**Settings → Secrets and variables → Actions → New repository secret**, and
add each of these:

| Secret name | Value | Example |
|---|---|---|
| `HOSTINGER_HOST` | The SSH host/IP from step 2.2 | `123.45.67.89` |
| `HOSTINGER_PORT` | The SSH port from step 2.2 | `65002` (Hostinger's typical non-default SSH port — confirm your own in hPanel) |
| `HOSTINGER_USERNAME` | The SSH username from step 2.2 | `u123456789` |
| `HOSTINGER_SSH_KEY` | The **entire contents** of the private key file (`~/.ssh/estele_deploy_key`), including the `-----BEGIN ...-----` / `-----END ...-----` lines | (paste full file) |
| `HOSTINGER_DEPLOY_PATH` | The path from step 2.3 | `domains/<your-domain>/public_html/<app-folder>` |

To copy the private key cleanly:
```bash
cat ~/.ssh/estele_deploy_key
```
Copy the **entire output**, starting from `-----BEGIN OPENSSH PRIVATE
KEY-----` through `-----END OPENSSH PRIVATE KEY-----` inclusive, into the
`HOSTINGER_SSH_KEY` secret value.

None of these five values are ever written to any file in this repository.
GitHub encrypts secrets at rest and only exposes them to workflow runs as
masked environment values — they never appear in logs (GitHub automatically
redacts a secret's exact value if it would otherwise be printed).

**This project's production `.env` (APP_KEY, DB credentials, Razorpay keys,
mail credentials, etc.) is never touched by this workflow at all** — it
isn't a GitHub secret, isn't read by CI, and the rsync step explicitly
excludes `.env` from ever being overwritten (see `deploy.yml`'s `--exclude
'.env'`). Nothing about this deployment setup requires putting any
application secret into GitHub.

### 2.6 Set up the Hostinger cron job (scheduler)

**This is separate from the GitHub Actions deploy and only needs doing
once** — it is not part of the workflow because it's server-side cron
configuration, not a deployment step, and running it wouldn't make sense on
every deploy.

Without this, the old-jewellery 3-hour bidding auto-close and 10-day
wallet-credit expiry jobs never run — requests get stuck in `bidding_active`
forever and wallet credits never expire.

In hPanel: **Advanced → Cron Jobs → Create a new Cron Job**:

- **Schedule**: every minute (`* * * * *`)
- **Command**: the **absolute** path to `artisan` inside your deployment
  path — Hostinger's cron UI generally needs an absolute path regardless of
  which form you used for the `HOSTINGER_DEPLOY_PATH` secret:
  ```
  php /home/<USERNAME>/domains/<your-domain>/public_html/<app-folder>/artisan schedule:run >> /dev/null 2>&1
  ```
  e.g.
  ```
  php /home/u123456789/domains/example.com/public_html/your-app/artisan schedule:run >> /dev/null 2>&1
  ```

Confirm the cron entry exists and is enabled — it's easy to have deploys
working correctly while the scheduler was never wired up, and the symptom
(stuck bidding requests, wallet credits that never expire) isn't obviously
connected to "cron isn't running" unless you know to check.

---

## 3. Testing the first deployment

1. Push a trivial, safe change to `main` (or use **Actions → Deploy to
   Hostinger → Run workflow** for a manual `workflow_dispatch` run without
   a new commit).
2. Open the **Actions** tab in GitHub and watch the run. The `test` job
   should go green first; `deploy` starts only after it does.
3. Watch the `deploy` job's **"Run post-deploy commands on Hostinger"**
   step — it prints a labeled `==` line before each command
   (`Verifying...`, `Installing Composer dependencies...`,
   `Running database migrations...`, etc.), so a failure tells you exactly
   which step it happened on.
4. After a green run, verify from the browser or `curl`:
   ```bash
   curl -o /dev/null -s -w "%{http_code}\n" https://<your-live-domain>/admin/login
   # expect 200

   curl -o /dev/null -s -w "%{http_code}\n" https://<your-live-domain>/admin/vendors
   # expect 302 (redirects to login — route exists and is protected)
   ```
5. Confirm nothing under `storage/app` was touched: SSH in and check that
   existing product images / old-jewellery media are still present and the
   same size/count as before the deploy.
6. Confirm `.env` on the server is byte-identical to before (e.g. `md5sum
   .env` before and after, or just check `APP_KEY`/`DB_PASSWORD` are
   unchanged).

Only once all of the above check out should this be trusted for routine use.

---

## 4. Troubleshooting failed deployments

| Symptom | Likely cause | Fix |
|---|---|---|
| `test` job fails | An actual test regression, or the ephemeral `.env`/`APP_KEY` step failed | Read the PHPUnit failure output in the Actions log — it's the same suite you can run locally with `php -d memory_limit=1G vendor/bin/phpunit --no-progress` from `backend/`. Fix the code or the test, don't skip the job. |
| `deploy` job fails at **"Trust the Hostinger host key"** | Wrong `HOSTINGER_HOST` or `HOSTINGER_PORT` secret, or the server is unreachable | Double check the values against hPanel → SSH Access. Test manually: `ssh-keyscan -p <PORT> -H <HOST>`. |
| Fails at **"Sync application files to Hostinger"** with `Permission denied (publickey)` | The public key isn't in the server's `authorized_keys`, or `HOSTINGER_SSH_KEY` doesn't match the key you authorized, or `HOSTINGER_USERNAME` is wrong | Re-run step 2.2's verification command locally with the same key. If that fails too, the key isn't authorized server-side — redo step 2.2. |
| Fails at **"Sync application files..."** with `rsync: command not found` (on the remote side) | Extremely rare on Hostinger, but some minimal shared-hosting shells lack `rsync` | SSH in and check `which rsync`. If missing, contact Hostinger support — this is a hosting-environment gap, not something the workflow can work around. |
| Fails at **"Run post-deploy commands on Hostinger"**, step `Verifying this is the intended Laravel project root` | `HOSTINGER_DEPLOY_PATH` is wrong — doesn't point at the directory containing `artisan` | Redo step 2.3, fix the `HOSTINGER_DEPLOY_PATH` secret, re-run. |
| Fails at `Confirming production .env is present` | `.env` is genuinely missing on the server (fresh install, or it was accidentally deleted) | This is a deliberate safety stop — the workflow refuses to run `migrate`/`optimize` against a project with no `.env`, since Laravel would use defaults/fail unpredictably. Restore `.env` manually over SFTP/SSH first (it is **never** something this workflow creates or restores for you), then re-run. |
| Fails at `php artisan migrate --force` | A real migration error — bad SQL, a missing column a migration expects, a lock timeout | Read the exact error in the Actions log. Fix the migration in code, push again. **Never** manually run `migrate:fresh`/`migrate:refresh`/`db:wipe` to "fix" this on production — those drop data. If a migration is genuinely broken, write a new corrective migration instead. |
| `composer install --no-dev` prints a `proc_open`/`Process` error but the step still shows green | Expected — Hostinger disables `exec`/`proc_open`, so `composer`'s post-install `@php artisan package:discover` hook throws a cosmetic error even though the autoload files are written correctly. The workflow already tolerates this (`\|\| true` on that command) — this is not a real failure. | No action needed if the *overall* step succeeds. Only investigate further if `vendor/autoload.php` is actually missing/stale after deploy. |
| Site shows the old code after a green deploy | Stale OPcache on the server (if OPcache is enabled and not reset), or you're checking the wrong domain | `php artisan optimize:clear` (already run every deploy) clears Laravel's own caches; OPcache is separate and PHP-level — most Hostinger PHP-FPM pools recycle it automatically on file mtime change, but if not, restarting PHP is a hosting-panel action (**PHP → Restart PHP** in hPanel), not something SSH/artisan controls. |
| Uploaded images/videos stop showing after a deploy | The `public/storage` symlink is missing or broken | The workflow already checks for and (re)creates this only when absent — see the `Ensuring the public storage symlink exists` step. If it still shows broken, SSH in and check `ls -la public/storage` — if it points at a wrong/stale target, remove and recreate it manually: `rm public/storage && ln -s ../storage/app/public public/storage`. |
| Old-jewellery bidding never closes / wallet credits never expire, even though deploys succeed | The Hostinger cron job (§2.6) isn't set up or isn't running | Check hPanel → Cron Jobs — this is unrelated to the GitHub Actions deploy and must be configured once, separately. |
| A deploy needs to be stopped/rolled back mid-run | — | Cancel the run from the **Actions** tab. Because `rsync` only ever adds/updates files matching the current commit (never deletes `.env`/`storage`/`vendor`), a cancelled or even a fully-completed bad deploy is recoverable by pushing a revert commit and letting the workflow run again — there is no destructive step that can't be corrected by deploying forward again. |

---

## 5. Two GitHub remotes — which one deploys?

This repo currently pushes to two GitHub remotes:

```
origin      → https://github.com/tcongsinfotech/jewellery.git
hostinger   → https://github.com/ayushman1413/estele-jewellery.git
```

(The `hostinger` remote name is a leftover from before this CI/CD setup —
it is **just another GitHub repo**, not Hostinger's own git integration,
which is the disabled feature this document replaces.)

The `.github/workflows/deploy.yml` file only runs inside whichever GitHub
repository it's pushed to — a workflow file doesn't "know about" or trigger
across a different remote. If both `origin` and `hostinger` receive pushes
to `main` (as they currently do), **both repos will independently try to
deploy** once the GitHub Secrets from §2.5 are added to each. That means:

- If you want only one source of truth deploying, add the secrets to
  **only one** of the two GitHub repos, and treat pushes to the other as
  a plain backup/mirror.
- If you deliberately want either repo's `main` to deploy (e.g. two
  collaborators pushing to different remotes), add the same five secrets
  to both repos — they'll both deploy to the same
  `HOSTINGER_DEPLOY_PATH`, which is safe (idempotent) but means a push to
  either remote triggers a real production deploy.

Decide this deliberately before adding secrets to more than one repo.

---

## 6. What this workflow deliberately does NOT do

- **Does not run `npm install`/`npm run build`** — see §1; the site's real
  CSS/JS is pre-built and already committed.
- **Does not touch `storage/app`, `storage/app/public`, or the production
  `.env`** — enforced by rsync excludes, verified empirically (see the
  commit that introduced this workflow for the exact rsync test).
- **Does not run `migrate:fresh`, `migrate:refresh`, or `db:wipe`** — only
  `migrate --force`, which only applies new migrations and never drops
  existing tables/data.
- **Does not restart a queue worker** — there isn't one to restart;
  production uses `QUEUE_CONNECTION=sync` because Hostinger shared hosting
  cannot keep a `queue:work` daemon alive (see §1 and §2.6).
- **Does not configure the Hostinger cron/scheduler** — that's a one-time,
  server-side setup step documented in §2.6, deliberately kept out of the
  per-deploy workflow.
- **Does not modify any other project on the Hostinger account** — the
  workflow only ever touches `HOSTINGER_DEPLOY_PATH` (via `cd` on the
  remote shell and as rsync's sole destination), never anything at a
  sibling path.
