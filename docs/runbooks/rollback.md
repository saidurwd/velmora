# A release went wrong

**Principle:** schema changes follow *expand, migrate, contract* and are forward-only, so the previous version of the code still works on the new schema. That is why **rolling the code back is safe**, and rolling the database back normally is not needed.

1. Decide fast: is it a safety problem (wrong money, exposed data)? Then **pause online payments** and go to maintenance first, then roll back.
2. Deploy the previous release (the tagged version before). Run `php artisan queue:restart`. Do **not** run migrations backwards unless the release notes say so and a developer is present.
3. If the last release included a *contract* migration (dropping a column or index), the previous code may need it: check `CHANGELOG.md` for "dropped"; ask a developer before rolling back across it.
4. Clear caches the release changed (`php artisan cache:clear` only if told; config/route/view caches are rebuilt by the deploy script).
5. Run the smoke checks: home page, a product, add to cart, checkout page, staff sign-in, *System health* green, one small cash order.
6. Write what failed. The fix goes in as a new release with a test that fails without it.

**API apps:** a rollback must not remove anything apps already use. `php artisan api:compat` runs in CI; if a rollback would make an older version of the API incompatible, ask first.
