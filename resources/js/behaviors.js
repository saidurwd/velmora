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
