# cPanel → Cloudflare R2 Backups

Daily database + file backups for PHP/Laravel apps on shared cPanel hosting, shipped
straight to [Cloudflare R2](https://www.cloudflare.com/developer-platform/products/r2/)
— **no SSH access, no installed binaries, no paid backup add-on required.**

## Why this exists

Shared/reseller cPanel hosting is cheap, but it has two problems if that's your only
copy of client data: the host can lock or terminate the account (billing dispute,
abuse flag on a shared IP, anything), and shared plans usually give you a cron
scheduler and a PHP runtime — not a shell, not `rclone`, and often not even
`shell_exec()`. This project works within exactly those constraints: everything
below runs as a plain PHP script triggered by a cPanel Cron Job.

## What it does

- **Dumps every configured database** — pure PHP over PDO (schema via
  `SHOW CREATE TABLE`, data streamed row-by-row so large tables don't blow memory).
  No `mysqldump` binary, no `shell_exec()`, no `exec()` — works even on hosts that
  disable them outright.
- **Zips every configured project directory**, with excludes (`vendor/`,
  `node_modules/`, log/cache dirs) so you're not backing up things `composer
  install`/`npm install` can regenerate.
- **Uploads everything to Cloudflare R2** via a small hand-rolled S3 SigV4 signer
  over cURL — no AWS SDK, no Composer dependency, one self-contained file.
- **Refuses to upload a broken dump.** `mysqldump`-style output always ends with a
  `-- Dump completed on ...` marker on a clean finish; its absence (timeout, dropped
  DB connection, disk full) means the dump was cut short, so it's discarded instead
  of silently shipped.
- **Won't let runs pile up on each other** — a lock file skips a run if the
  previous one is still working, instead of stacking concurrent copies.
- **Retention is one Cloudflare setting, not custom code** — an R2 Object Lifecycle
  Rule expires anything older than N days automatically, which is enough to stay
  inside R2's free tier (10GB storage, zero egress fees) indefinitely for a typical
  handful of small-to-medium apps.
- **Comes with a restore/verification script** you run on your own machine to list,
  download, and actually test-restore a backup — because an untested backup is just
  an assumption.

## Requirements

Just a standard PHP hosting stack: PHP 8.1+ with the `pdo_mysql`, `zip`, and `curl`
extensions — all present by default on basically every cPanel PHP hosting plan.

## Setup

1. **Upload** [`backup-to-r2.php`](backup-to-r2.php) and
   [`backup-config.sample.php`](backup-config.sample.php) somewhere **outside
   `public_html`** on the cPanel account, e.g. `/home/youruser/backup-scripts/`.
2. **Copy the config**: `cp backup-config.sample.php backup-config.php`, fill in your
   databases, project directories, and R2 credentials, then `chmod 600
   backup-config.php`. This file holds real secrets — it's already in
   `.gitignore` and should never be committed.
3. **Create an R2 bucket** (Cloudflare dashboard → R2 → Create bucket) and an API
   token scoped to it with Object Read & Write access.
4. **Add a lifecycle rule** on the bucket: Settings → Object Lifecycle Rules → Add
   rule → "Delete objects after N days" (7 is a sane default). This is what keeps
   storage — and cost — bounded, with no code involved.
5. **Add the cPanel Cron Job**, once a day:
   ```
   0  0  *  *  *  php -q /home/youruser/backup-scripts/backup-to-r2.php >> /home/youruser/backup-scripts/cron.log 2>&1
   ```
   Use the full path to your PHP CLI binary if bare `php` isn't on cron's `PATH`
   (check what your existing Laravel scheduler cron entry uses, if you have one —
   it'll be something like `/opt/cpanel/ea-php81/root/usr/bin/php`).
6. **Test it** before trusting it: temporarily set the cron to run every few
   minutes, watch the log file (the path set in `backup-config.php`'s `log_file`)
   fill in with `Dumped ...` / `Zipped ...` / `Uploaded ->` lines and no `ERROR`
   lines, then switch the schedule back to once daily.

Running more than one project/database/cPanel account on one bucket? Give each its
own `key_prefix` in `backup-config.php` (e.g. `siteA/`, `siteB/`) so their backups
don't collide.

## Restoring / verifying a backup

Run this **on your own machine**, not the server — that's where you actually have a
full shell. Copy your `backup-config.php` down next to
[`restore-from-r2.php`](restore-from-r2.php) and reuse it:

```bash
php restore-from-r2.php list                     # everything in the bucket
php restore-from-r2.php list db/mydatabase        # one database's history
php restore-from-r2.php latest mydatabase         # download the most recent copy
php restore-from-r2.php get files/myapp/2026-08-29_000000.zip
```

Downloads land in `restored/`. To actually prove a backup works, not just that the
file exists:

```bash
gunzip -c restored/mydatabase_....sql.gz | mysql -u root -p -e "CREATE DATABASE restore_test"
gunzip -c restored/mydatabase_....sql.gz | mysql -u root -p restore_test
unzip restored/myapp_....zip -d restored/myapp_check/
```

Worth doing this every so often — it's the difference between "we have backups" and
"we know the backups work."

## Security notes

- `backup-config.php` holds live database passwords and an R2 secret key. Keep it
  outside `public_html`, `chmod 600` it, and never commit it — `.gitignore` already
  excludes it.
- Scope the R2 API token to the one bucket this uses, not full account access.
- Database credentials are passed via PDO's constructor within the same PHP
  process — never via a CLI flag — so they never show up in `ps aux` output on a
  shared host.

## License

MIT — see [LICENSE](LICENSE).
