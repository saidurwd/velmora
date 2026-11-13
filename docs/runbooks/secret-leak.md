# A secret may have leaked

**Secret** = a password, the app key, a database/Redis password, the payment gateway store password, the SMS token, the Firebase service-account file, a staff recovery code, an API/session token.

1. **Contain first, investigate after.** Rotate the secret before you know whether it was misused.
2. Rotate by kind (the owner or server administrator does the change; record who and when):
   * **Gateway store password / SMS token / Firebase service account**: create a new one in the provider's portal, put it in the secret store (never the repository), deploy, then disable the old one. For the gateway, pause online payments for the swap.
   * **Database / Redis passwords**: change on the server, update the environment, restart the app and workers.
   * **A staff password or recovery codes**: staff member resets password; an admin disables the account until then (`/admin/staff`), and ends their sessions.
   * **A customer's session or an app sign-in**: the customer can end sessions in their account; an admin can disable the account; for many customers, ask a developer to end sessions (`api:` tokens are single-use refresh tokens: ending the sign-in family stops them).
   * **`APP_KEY`**: changing it signs **everyone** out, makes saved integration credentials unreadable (the app falls back to the server's own settings), and invalidates stored push tokens. Only do it if the key itself leaked; keep the old key available for a short time if encrypted data must be re-saved.
3. Search for use: audit log (`/admin/audit`), the gateway/provider dashboards, access logs. Note anything unexpected.
4. If personal data may have been exposed, tell the owner **the same day**: Bangladeshi and customer-trust obligations apply and the owner decides on notification, with counsel.
5. Find out how it leaked (committed file, chat, screenshot, laptop) and close that hole. The repository is scanned for secret patterns; a new secret in git history needs the history cleaned *and* the secret rotated.
