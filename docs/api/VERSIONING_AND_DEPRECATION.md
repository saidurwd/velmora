# API versioning, changes and deprecation (v1)

This is the promise the server makes to apps already in people's hands. It is enforced: `php artisan api:compat` compares `openapi.json` with the frozen baseline `baseline/openapi.v1.json` and CI fails on anything an existing app could trip on (`app/Modules/Platform/Support/Api/OpenApiCompatibility.php` lists the rules, tests prove each).

## The version

The version is in the path: `/api/v1`. A new major version (`/api/v2`) exists only when something cannot be done compatibly; v1 then keeps working through the notice period below. Within v1 nothing is ever renamed, removed or narrowed without the process below.

## What can change without notice (apps must tolerate it)

* New endpoints; new optional request fields and query parameters.
* New fields in answers, and new values in an answer's status-like fields (order status, payment state, …). Apps ignore unknown fields and treat unknown values as "other".
* New error `code`s (apps fall back to the HTTP status and show `title` / `detail`), new response headers, new success statuses.
* Looser rules (a longer maximum, a field that used to be required becoming optional **in a request**), better wording, new languages, performance changes, rate-limit numbers.

## What is breaking (never without notice)

Removing or renaming a path, method, field, parameter, header or error code; making an optional request field required or adding a required one; narrowing what a request accepts (a type, the allowed values); making an always-present answer field optional or nullable, or changing its type; a public call starting to need a sign-in; changing what an existing success status means; changing how tokens, the refresh rule or idempotency work.

## The process for a breaking change

1. **Prefer adding.** A new field/endpoint next to the old one, then deprecate the old one.
2. **Announce** in `docs/api/CHANGELOG.md` ("Deprecated") and mark the operation `deprecated: true` in the document.
3. **Signal at runtime:** the deprecated call answers with `Deprecation: true` and `Sunset: <HTTP date>` headers (RFC 9745 / RFC 8594) and a `Link: <…>; rel="successor-version"` header when there is a replacement.
4. **Wait at least 6 months** from the announcement to the sunset date, and at least until the share of installed apps still calling it is negligible (we read this from `X-App-Version` in the request logs). For a security fix the notice may be shorter, and the changelog says why.
5. **Remove** on or after the sunset date, in a release that lists it under "Removed". Until then the call keeps working exactly as before.
6. **Minimum app version:** `api.app.min_supported_version` (per platform) is how the server retires a whole old app build: calls from older builds answer 426 `app.upgrade_required`. It is raised only after the sunset date of everything that build depends on, with the store release available.

## The baseline

`baseline/openapi.v1.json` is the document as shipped to the stores. `api:compat` compares the working document with it. When a breaking change has gone through the process above, the sunset has passed, and the change is to be released, a maintainer runs `php artisan api:compat --freeze` in the same commit as the removal and says so in the changelog. Freezing is a release decision; it is never done to make a failing check pass.

## Files

* `openapi.json` — the contract (generated: `php artisan scramble:export`).
* `velmora.postman_collection.json`, `velmora.postman_environment.json` — generated: `php artisan api:collection`.
* `baseline/openapi.v1.json` — the frozen reference.
* `MOBILE_INTEGRATION_GUIDE.md` — how to use it. `CHANGELOG.md` — what changed.
