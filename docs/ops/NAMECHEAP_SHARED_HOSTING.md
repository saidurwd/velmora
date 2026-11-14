# Running Velmora on Namecheap shared hosting (cPanel)

Written for the Admin. **Everything here marked "(check)" depends on your hosting plan and was not verifiable from outside; confirm it in cPanel.** What *was* verified: the build, install, upgrade and rollback scripts were rehearsed end to end on a throw-away folder and database (see "What was tested" at the end).

Shared hosting is a weaker home than a small VPS: no point-in-time database recovery, a shared outgoing IP address, no always-on worker. This guide makes the shop work within that, and says plainly where it is weaker.

## 0. Before you start: confirm the server can run the shop

In cPanel (check each):

| Need | Where | Required |
|---|---|---|
| MariaDB version | *MySQL® Databases* (version is shown) or *Select MySQL Version* | **MariaDB 11.4.5 or newer** (you said it is fine) |
| PHP version and extensions | *Select PHP Version* / *MultiPHP Manager* | **8.3 or newer**; extensions `mbstring intl pdo_mysql gd zip bcmath openssl fileinfo ctype curl xml` |
| SSH access | *SSH Access* (some plans need it switched on or requested) | Needed for `deploy.sh`. Without it, see "No SSH" below |
| Cron jobs | *Cron Jobs* | a job every **1 minute** (if the shortest allowed is 5 minutes, texts and order steps are up to 5 minutes late) |
| HTTPS | *SSL/TLS Status* (AutoSSL) | on, with "Force HTTPS Redirect" |

The **preflight** (`php artisan velmora:preflight`, run for you by the installer) re-checks the engine version, the collation, time periods, triggers (the protection that makes money and stock records append-only), CHECK constraints, folder permissions, file storage, the queue and the scheduler, and refuses to switch to a release if any line fails.

## 1. One-time set-up

1. **Database.** cPanel → *MySQL® Databases*: create a database and a user, give the user **ALL PRIVILEGES** (it must be allowed to create triggers). Note the full names (they carry your account prefix).
2. **PHP.** Choose PHP 8.3+ for the domain and tick the extensions above. Raise `memory_limit` to 256M if allowed.
3. **Upload the first release.** On your own computer: `bash deploy/cpanel/build-release.sh` (needs PHP, Composer, Node; builds `velmora-release-<commit>.zip`, about 22 MB, with no secrets). Upload the zip and `deploy/cpanel/deploy.sh` to your home folder (File Manager or SFTP).
4. **Secrets file.** Create `~/velmora-shared/.env` (File Manager). Start from `.env.example` and set at least:

```
APP_NAME=Velmora
APP_ENV=production
APP_DEBUG=false
APP_KEY=              # generate on your computer: php artisan key:generate --show   (keep a copy in a password manager AND offline: losing it makes saved credentials unreadable)
APP_URL=https://your-domain
DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<cpanel database name>
DB_USERNAME=<cpanel database user>
DB_PASSWORD=<its password>
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
QUEUE_RUN_FROM_SCHEDULER=true
MEDIA_DRIVER=local          # no S3 on shared hosting: product images as files
PRIVATE_DRIVER=local        # digital files, data copies, imports: files outside the web folder
MAIL_MAILER=smtp            # your cPanel mailbox settings (host, port, user, password, MAIL_FROM_ADDRESS)
SMS_DRIVER=log              # change only after SSL Wireless is set up (section 3)
PUSH_DRIVER=log
```

   Do **not** put the payment, SMS or Firebase secrets here by hand if you can avoid it: enter them on the staff *Integrations* screen (stored encrypted). Never paste any of them into chat.
5. **Install.** In SSH: `bash ~/deploy.sh install ~/velmora-release-<commit>.zip`. It unpacks to `~/velmora-releases/`, links `.env` and `storage` to `~/velmora-shared/`, runs the preflight, publishes the built assets and `index.php` to `~/public_html`, runs `php artisan migrate --force`, and switches `~/velmora` to the new release. Your code and `.env` live **outside** the web folder.
6. **Cron.** cPanel → *Cron Jobs*, every minute (use your PHP path; check *MultiPHP* or run `which php`; it is often like `/opt/cpanel/ea-php83/root/usr/bin/php`):

```
* * * * * /opt/cpanel/ea-php83/root/usr/bin/php /home/<cpanel-user>/velmora/artisan schedule:run >> /dev/null 2>&1
```

   This one line runs every scheduled task **and** drains the queue (`QUEUE_RUN_FROM_SCHEDULER=true`). Two minutes later run `php ~/velmora/artisan velmora:preflight`: the "Scheduler (cron)" line should turn OK.
7. **Starter catalogue (optional).** `php ~/velmora/artisan db:seed --class='Database\Seeders\BangladeshLifestyleSeeder' --force` adds the categories, attributes, brands, tags, menus and search spellings of a Bangladeshi lifestyle shop (see `docs/ops/LIFESTYLE_STARTER_CATALOGUE.md`; it can take about 20 seconds on shared hosting).
8. **First staff account.** `php ~/velmora/artisan velmora:create-super-admin you@yourdomain.com --name="Your Name"` (it asks for the password; see `OWNER_LAUNCH_CHECKLIST.md`).

## 2. Every upgrade, and going back

* Upgrade: build a new zip on your computer, upload it, `bash ~/deploy.sh install ~/velmora-release-<commit>.zip`. If the preflight fails, **nothing changes** and the old release stays live.
* Go back: `bash ~/deploy.sh rollback`. It switches to the previous release (the database is not touched: migrations only add things, so the old code works with it; if a release says "dropped" in the changelog, read `docs/runbooks/rollback.md` first).
* Releases accumulate in `~/velmora-releases/`; keep the last three and delete older ones by hand.

**No SSH?** The deploy needs a shell. Without one you can unpack the zip into `~/velmora` by File Manager, and run migrations from your own computer against the hosting database (cPanel → *Remote MySQL*: allow your computer's address, then `DB_HOST=<server> php artisan migrate --force` locally, over a trusted network, and remove the allowance afterwards). The preflight and `velmora:create-super-admin` also need a shell, so ask Namecheap to enable SSH.

## 3. Things that behave differently here

* **Texts (SSL Wireless) need a fixed server IP** that you whitelist in their portal. Shared hosting normally shares an IP that can change. Ask Namecheap about a **dedicated IP** add-on, find the address with `curl ifconfig.me` over SSH, and whitelist that one. Until then keep `SMS_DRIVER=log`: phone-code sign-in and text confirmations will not work (email sign-in and email confirmations do).
* **Payments (SSLCommerz)** work over HTTPS callbacks to `https://your-domain/api/v1/webhooks/payments/sslcommerz`; register that and the return pages in their dashboard (`DEPLOYMENT_AND_DEVOPS.md` §5.2). Start in the sandbox.
* **Push (Firebase)**: upload the service-account JSON **outside** the web folder (for example `~/velmora-shared/firebase.json`, permissions 600) and set `PUSH_DRIVER=fcm` and `FCM_SERVICE_ACCOUNT_FILE=/home/<user>/velmora-shared/firebase.json`.
* **Delays.** Messages, emails and order steps wait for the next minute's cron run (up to about a minute). Sign-in codes are sent from the request itself and are not delayed.
* **Visitor address.** If you put Cloudflare or another proxy in front, set `TRUSTED_PROXIES` as described in the checklist; otherwise leave it empty.
* **Disk and limits.** Product images, digital files, data copies and the log all live under `~/velmora-shared/storage`; watch your plan's disk quota. Check the PHP `max_execution_time` is at least 60 seconds (the queue runs up to 50 s per minute).
* **Moving to a VPS later** is straightforward: restore the database copy, set `MEDIA_DRIVER`/`PRIVATE_DRIVER` back to S3 (or keep local), and point DNS. Keep the backups and the `APP_KEY`.

## 4. Backups (this is the weakest part of shared hosting)

* `deploy/cpanel/backup-db.sh`: a daily database copy kept 14 days in `~/velmora-backups` (checked readable after writing). Create `~/.velmora-backup.cnf` (`chmod 600`) with the database user and password, then add a cron line: `10 2 * * * bash ~/backup-db.sh <database name>`.
* **Copy the newest file off the server** (download it, or use a cPanel remote backup destination). A backup that lives only on the same hosting account is lost with it.
* Also back up `~/velmora-shared/storage` (images, digital files) and **the `APP_KEY`**.
* **What you do not get:** point-in-time recovery. The most you can lose is up to a day (the interval between copies), not the 15 minutes the design document targets. That target must be **formally waived** for the shared-hosting phase (the readiness report lists it as a blocker until you decide). A restore is done as in `docs/runbooks/database-failover-restore.md`; practise it once.

### Importing a database copy (phpMyAdmin or the `mysql` command)
A copy made with `mysqldump` / `mariadb-dump` names the database user who made it on every trigger (`DEFINER=\`root\`@\`localhost\``). Imported as another user (always the case on cPanel), it stops at the first trigger, e.g. at `TRIGGER \`audit_events_no_update\``, because only an administrator may create a trigger for someone else. Remove that clause first; nothing else changes and the triggers then belong to your cPanel database user:

    sed -e '1{/enable the sandbox mode/d;}' -e 's#/\*!50017 DEFINER=`[^`]*`@`[^`]*`\*/ ##g' copy.sql > copy.import.sql

(The first expression drops the `/*M!999999\- enable the sandbox mode */` line that MariaDB 11.4+/12 tools put at the top, which older import tools reject.) `deploy/cpanel/backup-db.sh` already writes its copies without it. Import into an **empty** database. If the import then stops with error 1419 (binary logging), the host must allow triggers (`log_bin_trust_function_creators`); `php artisan velmora:preflight` checks this. A copy holds customers' personal data and password hashes: keep it out of the repository (`.gitignore` refuses `*.sql`) and delete local copies you no longer need.

## 5. What was tested

* `deploy/cpanel/build-release.sh`: builds a 22 MB zip with production dependencies only, no `.env`, no tests, no dev tools.
* `deploy/cpanel/deploy.sh`: first install, an upgrade and a rollback rehearsed in a throw-away home folder against a throw-away MariaDB 11.4: preflight gate, assets published, `index.php` outside-the-web-folder layout, pages and API answering, `.env` and private paths not downloadable. The first rehearsal found two real problems (the home page tried to reach S3, and the build ran as production), both fixed.
* The preflight and the cron-driven queue have automated tests; private files are proved to be reachable only by a signed, expiring link.
* **Not tested here (needs your account):** Namecheap's actual PHP/MariaDB/SSH/cron limits, the real cPanel file layout, HTTPS, mail, and the paths in section 1 step 6.
