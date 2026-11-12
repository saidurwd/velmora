# Owner's launch checklist

Things only the owner (or the person running the server) can do. Nothing here is done by the code; each line says where it is set and how to know it worked. Never paste a secret into chat, a ticket, git or a screenshot.

## 1. The first administrator (do this first)

On the server, in the app folder, as the user that runs the app:

```
php artisan velmora:create-super-admin you@yourdomain.com --name="Your Name"
```

It asks for a password (at least 10 characters, not guessable) and creates the only kind of account that can create the others. No default login exists anywhere. Open `/admin/login`, sign in, and set up two-step verification when asked (keep the recovery codes somewhere safe, offline). Then create staff accounts from the staff screen with the narrowest role that fits. Use a work email you control; if you lose both the password and the recovery codes, the command above with `--force` makes another Super Admin.

## 2. On the server (production)

| Item | Where | Done when |
|---|---|---|
| Queue worker running all the time | `QUEUE_CONNECTION=redis` (or `database`) + a process manager (Supervisor / systemd) running `php artisan queue:work` | an order confirmation text is delivered within a minute |
| Scheduler | one cron line: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1` | the Notifications health banner shows no stuck messages; unpaid online orders expire |
| Maintenance mode that works on every server | `APP_MAINTENANCE_DRIVER=cache` with a **shared** cache (`CACHE_STORE=redis`) | `php artisan down` takes effect on all servers |
| Safe settings | `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` | the site does not show error details |
| App key | `APP_KEY` | kept in a password manager **and** an offline copy. Losing it makes every saved credential, stored push token and signed link unreadable; changing it signs everyone out |
| Abandoned-cart reminders (optional) | `CART_RECOVERY_ENABLED=true` | off by default; turn on only after the SMS provider works |
| Real texts | `SMS_DRIVER=sslwireless`, `SSLW_SMS_API_TOKEN`, `SSLW_SMS_SID` (or save them encrypted in the admin Integrations screen) | the Integrations screen's SMS test passes. The server's IP must be whitelisted in SSL Wireless's portal first |
| Real payments | `PAYMENT_SSLCOMMERZ_STORE_ID`, `PAYMENT_SSLCOMMERZ_STORE_PASSWORD`; start with `PAYMENT_SSLCOMMERZ_SANDBOX=true` | a sandbox order is paid and shows "paid". Live needs `APP_ENV=production`, `PAYMENT_SSLCOMMERZ_SANDBOX=false` **and** `PAYMENTS_ALLOW_LIVE=true`; do that only after the sandbox passes |
| App push messages | `PUSH_DRIVER=fcm`, `FCM_SERVICE_ACCOUNT_FILE=/absolute/path/outside/web/root.json` (from the Firebase console: Project settings, Service accounts) | an order status change shows on a test phone. Without it, production pushes are recorded as failing on the Notifications delivery list |
| Backups | per `docs/architecture/DEPLOYMENT_AND_DEVOPS.md` | a restore has been tried once |

## 3. Decisions and content only you can give

| Item | What is needed |
|---|---|
| Five policy pages (returns, privacy, terms, shipping, refunds) | the real text, in English and Bangla, entered in the CMS pages screen and assigned as policies. The seeded text is a placeholder. Have it read by someone qualified |
| Tax | the shop is not VAT-registered, so tax is off (`tax.enabled=false`). When it registers, a tax adviser must confirm rates and invoice (Mushak) wording before anyone turns it on. Nothing is hard-coded |
| Guest orders and later sign-up | decide whether a guest who later registers with the same phone number should get their old guest orders attached to the account. Until you decide, they are not attached (the safe default) |
| Staff accessibility check | someone walks through `docs/qa/PHASE8_STAFF_SCREENS_A11Y_CHECKLIST.md` with the keyboard and a screen reader and ticks it |
| Courier automation | Steadfast, then Pathao: save each courier's official API documents under `docs/vendor/<courier>/` and say go; the adapters are built from those pages only. Until then parcels are booked by hand |
| Recognised revenue reporting | waits for your accountant's confirmation |

## 4. Before the first real customer

1. Place one real cash order and one sandbox-paid order yourself, in English and Bangla; cancel and refund one.
2. Confirm the confirmation text and email arrive and the order appears on the staff screen.
3. Download the data copy of your own test account and delete it, to see both work.
4. Check the Money today and Notifications banners are quiet.
