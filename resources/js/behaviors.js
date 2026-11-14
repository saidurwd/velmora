// Small behaviours the pages ask for with data attributes. They replace inline handlers (onsubmit="...", onchange="...") and inline scripts, which the
// Content-Security-Policy refuses to run (script-src 'self'). Every one is an enhancement: the page still works, in its plain form, without JavaScript.
//
//   <form data-confirm="Delete this?">            asks first; "Cancel" stops the submit
//   <select|input data-autosubmit>                 sends its form as soon as it changes
//   <select data-bulk-action>                      the bulk bar: the chosen action is where the form posts to
//   <input type=checkbox data-bulk-all="form-id">  ticks / unticks every ids[] box that belongs to that form
//   <form data-goto-base="/x" data-goto-field="f"> opens /x/<value of field f> instead of submitting
//   <button data-print>                            opens the browser's print dialog

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    const message = form.dataset.confirm;
    if (message !== undefined && !window.confirm(message)) {
        event.preventDefault();
        return;
    }

    if (form.dataset.gotoBase !== undefined) {
        event.preventDefault();
        const value = (form.elements[form.dataset.gotoField ?? '']?.value ?? '').trim();
        if (value) window.location.assign(`${form.dataset.gotoBase}/${encodeURIComponent(value)}`);
    }
});

document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('[data-print]')) window.print();
});

document.addEventListener('change', (event) => {
    const el = event.target;
    if (!(el instanceof HTMLElement)) return;

    if (el.hasAttribute('data-autosubmit') && el.form) {
        el.form.submit();
    }

    if (el.hasAttribute('data-bulk-action') && el instanceof HTMLSelectElement && el.form) {
        el.form.action = el.value;
    }

    if (el.dataset.bulkAll !== undefined && el instanceof HTMLInputElement) {
        const formId = el.dataset.bulkAll;
        document.querySelectorAll(`input[name="ids[]"][form="${CSS.escape(formId)}"]`).forEach((box) => {
            box.checked = el.checked;
        });
    }
});

// The bulk bar starts with the first action chosen: point the form at it (the browser may also restore an earlier choice after "back").
document.querySelectorAll('select[data-bulk-action]').forEach((select) => {
    if (select.form) select.form.action = select.value;
});

// ---- Menus built from <details> (they open and close without any script; this only makes them behave like menus) ----
// In a [data-menu] bar only one group is open at a time; a click elsewhere or Escape closes what is open (also for a [data-popover], such as the phone menu).
const openMenus = () => document.querySelectorAll('[data-menu] details[open], details[data-popover][open]');

document.addEventListener('toggle', (event) => {
    const details = event.target;
    if (!(details instanceof HTMLDetailsElement) || !details.open) return;
    const bar = details.closest('[data-menu]');
    if (bar) bar.querySelectorAll('details[open]').forEach((other) => { if (other !== details) other.open = false; });
}, true);

document.addEventListener('click', (event) => {
    openMenus().forEach((details) => { if (!details.contains(event.target)) details.open = false; });
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    openMenus().forEach((details) => {
        const hadFocus = details.contains(document.activeElement);
        details.open = false;
        if (hadFocus) details.querySelector('summary')?.focus();
    });
});

// With a mouse, a group in the bar also opens when pointed at and closes when left. (Touch and keyboard keep the plain click / Enter.)
if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    document.querySelectorAll('[data-menu] details').forEach((details) => {
        const item = details.parentElement;
        item.addEventListener('mouseenter', () => { details.open = true; });
        item.addEventListener('mouseleave', () => { details.open = false; });
        // a mouse click on the heading would close what the pointer just opened: keep it open instead
        details.querySelector('summary')?.addEventListener('click', (event) => {
            if (event.detail > 0) { event.preventDefault(); details.open = true; }
        });
    });
}

// ---- Carousels: a scroll-snap row; the two buttons scroll one slide. Shown only here, because without scripts they would do nothing. ----
document.querySelectorAll('[data-carousel]').forEach((carousel) => {
    const track = carousel.querySelector('[data-carousel-track]');
    const controls = carousel.querySelector('[data-carousel-controls]');
    if (!track || !controls) return;
    controls.hidden = false;
    const rtl = getComputedStyle(track).direction === 'rtl';
    const step = (direction) => track.scrollBy({ left: direction * (rtl ? -1 : 1) * track.clientWidth, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    controls.querySelector('[data-carousel-prev]')?.addEventListener('click', () => step(-1));
    controls.querySelector('[data-carousel-next]')?.addEventListener('click', () => step(1));
});

// ---- Quantity steppers ([data-stepper], the qty-stepper component): − and + keep to data-min, data-max and data-step; the arrow keys do the same in the box. ----
// Typing is left alone (a Bangla digit or an over-large number is the server's to judge, with a friendly message); only the buttons and arrows snap the number.
const asciiDigits = (text) => text.replace(/[০-৯]/g, (digit) => String('০১২৩৪৫৬৭৮৯'.indexOf(digit)));
document.querySelectorAll('[data-stepper]').forEach((box) => {
    const input = box.querySelector('input');
    const less = box.querySelector('[data-stepper-dec]');
    const more = box.querySelector('[data-stepper-inc]');
    if (!input || !less || !more) return;
    const min = Math.max(1, parseInt(box.dataset.min, 10) || 1);
    const step = Math.max(1, parseInt(box.dataset.step, 10) || 1);
    const max = box.dataset.max ? parseInt(box.dataset.max, 10) : Infinity;
    const read = () => { const n = parseInt(asciiDigits(input.value.trim()), 10); return Number.isFinite(n) ? n : min; };
    // the nearest allowed amount: min, min + step, min + 2·step, ... and never above max
    const snap = (n) => {
        let q = min + Math.round((Math.max(min, n) - min) / step) * step;
        while (q > max && q - step >= min) q -= step;
        return q;
    };
    const sync = () => {
        const n = read();
        less.disabled = n <= min;
        more.disabled = n + step > max;
        input.setAttribute('aria-valuenow', String(n));
    };
    const change = (direction) => {
        const n = read();
        const next = snap(direction > 0 ? (n < min ? min : n + step) : n - step);
        input.value = String(next);
        sync();
    };

    input.setAttribute('role', 'spinbutton');
    input.setAttribute('aria-valuemin', String(min));
    if (Number.isFinite(max)) input.setAttribute('aria-valuemax', String(max));
    less.hidden = false;
    more.hidden = false;
    less.addEventListener('click', () => change(-1));
    more.addEventListener('click', () => change(1));
    input.addEventListener('input', sync);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            change(event.key === 'ArrowUp' ? 1 : -1);
        }
    });
    sync();
});

// A <details data-open-desktop> (the listing filters) is a closed drawer on phones and simply open on large screens.
if (window.matchMedia('(min-width: 64rem)').matches) {
    document.querySelectorAll('details[data-open-desktop]').forEach((details) => { details.open = true; });
}
