
* Phase 9, slice 6: `Contracts/StaffDirectory::withPermission()` lists the staff who hold a permission (names only), for "assign to" pick-lists.
* Phase 9, slice 7: `Contracts/AccountLocales` gives the language each account chose, for messages sent while the customer is away.
* Phase 10, slice 3b: creating an account (email, and the first phone sign-in) asks for the published policies through `Cms\Contracts\PolicyAcceptances`. `CustomerRegistration::registerNew` takes the release ids shown (null only for a guest making an account from their order, whose acceptances are carried over). Nothing published: nothing extra. Content Editor no longer holds `cms.policies.manage`.
