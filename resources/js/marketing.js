/**
 * Public site behaviour.
 *
 * Everything here is an enhancement. With the script blocked the menu links
 * are still in the page, the currency switch is still a form that posts, and
 * every page still works — nothing below is load-bearing.
 */

// ── Mobile menu ───────────────────────────────────────────────────────────
const burger = document.getElementById('burger');
const menu = document.getElementById('mmenu');

if (burger && menu) {
    const setOpen = (open) => {
        menu.classList.toggle('open', open);
        burger.setAttribute('aria-expanded', String(open));
        burger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        burger.querySelector('i').className = open ? 'fas fa-xmark' : 'fas fa-bars';
        // The page behind a full-screen sheet must not scroll under it.
        document.body.style.overflow = open ? 'hidden' : '';
    };

    burger.addEventListener('click', () => setOpen(!menu.classList.contains('open')));

    // Escape closes it, and focus goes back to the control that opened it —
    // otherwise a keyboard user is left in a sheet that is no longer there.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu.classList.contains('open')) {
            setOpen(false);
            burger.focus();
        }
    });

    // Following a link inside the sheet should not leave the body locked if
    // the browser restores this page from its back/forward cache.
    window.addEventListener('pageshow', () => setOpen(false));
}

// ── Dismissible flash notice ──────────────────────────────────────────────
document.querySelectorAll('[data-dismiss]').forEach((button) => {
    button.addEventListener('click', () => button.closest('.notice')?.remove());
});
