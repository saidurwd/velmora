# Phase 8 — manual accessibility check of the staff screens

The staff screens sit behind two-factor sign-in, which the automated browser tests cannot pass (no code library is used for staff in tests). The customer pages are
checked automatically (`e2e/phase8.spec.js`, axe at phone width, WCAG 2.2 AA). The staff screens below are checked by a person with this list, in **English and Bangla**,
at phone width (360 px) and on a desktop, using the keyboard only and a screen reader (VoiceOver or NVDA), before Phase 8 is called finished in production.

Screens (all under `/admin/bookings`): bookings list, one booking, calendar; setup → services, one service, people and rooms, one person or room (hours, days off),
holidays, cancellation policies, one policy; and the digital screens added earlier (files, rights, refund policies).

For each screen:

- [ ] Every control can be reached and used with the keyboard alone, in a sensible order; the focus is always visible.
- [ ] Every input has a visible label (not only a placeholder); every table has a caption (hidden is fine) and column headers with `scope`.
- [ ] Error and success messages are announced (they are `role="status"` / `role="alert"`) and say what to do.
- [ ] Buttons and links are at least 44 × 44 px on a phone; nothing is hidden behind the cookie banner or the keyboard.
- [ ] Colour is never the only sign (a status badge always has words).
- [ ] The page makes sense at 200% zoom with no sideways scrolling, apart from wide tables, which scroll inside their own box.
- [ ] Bangla text is complete (no English left over, no raw key such as `bookings::…`) and numbers and dates read correctly.
- [ ] Destructive actions (cancel booking, record a no-show, remove a day off) say clearly what they will do, and ask for a reason where the rules need one.

Record who checked, when, and what was found below. Findings are fixed before production use, or written down here with a reason.

| Date | Checked by | Screens | Findings / fixes |
|---|---|---|---|
|  |  |  |  |
