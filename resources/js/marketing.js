/**
 * Public site behaviour.
 *
 * Everything here is an enhancement. With the script blocked the menu links
 * are still in the page, the currency switch is still a form that posts, and
 * every page reads in full — nothing below is load-bearing.
 *
 * The one rule that matters: NOTHING IS EVER LEFT INVISIBLE. The reveal
 * styles are scoped to a `.js` class this file adds, there is a failsafe that
 * shows everything if the observer never fires, and a browser that asked for
 * reduced motion is given none at all rather than a faster version of it.
 */

const html = document.documentElement;
const stillMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

/* The `js` class is set inline in the head so the starting state is painted
   first; added again here only in case that block was stripped. The attribute
   is the handshake: the inline script removes `js` if this file never runs,
   so a page whose script failed shows everything rather than nothing. */
html.classList.add('js');
html.setAttribute('data-site-ready', '');

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

    // Escape closes it, and focus returns to the control that opened it —
    // otherwise a keyboard user is left in a sheet that is no longer there.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu.classList.contains('open')) {
            setOpen(false);
            burger.focus();
        }
    });

    // Following a link inside the sheet must not leave the body locked if the
    // browser restores this page from its back/forward cache.
    window.addEventListener('pageshow', () => setOpen(false));
}

// ── The header separates itself once the page moves ───────────────────────
const header = document.querySelector('header.site');

if (header) {
    let ticking = false;
    const onScroll = () => {
        if (ticking) return;
        ticking = true;
        // One class change per frame at most; scroll fires far faster than
        // the screen can redraw.
        requestAnimationFrame(() => {
            header.classList.toggle('is-scrolled', window.scrollY > 8);
            ticking = false;
        });
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
}

// ── Enter on scroll ───────────────────────────────────────────────────────

/** Everything worth arriving separately, in the order it appears. */
const REVEAL = [
    '.sec-head',
    '.grid > .card',
    '.steps > .step',
    '.flow-row',
    '.prices > .price',
    '.stats',
    '.faq',
    '.cmp-scroll',
    '.formcard',
    '.band > .wrap',
    '.shot',
];

/** Show everything, now, and stop trying to be clever about it. */
const revealAll = () => {
    document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-in'));
};

const setupReveal = () => {
    // Reduced motion, or a browser without IntersectionObserver: no reveal at
    // all. The elements were never hidden in the first place for the former,
    // and the latter gets the page as written.
    if (stillMotion.matches || !('IntersectionObserver' in window)) {
        return;
    }

    const groups = new Map();
    const hero = document.querySelector('.hero');

    REVEAL.forEach((selector) => {
        document.querySelectorAll(selector).forEach((el) => {
            if (el.hasAttribute('data-reveal')) return;
            // The hero plays on load, not on scroll — anything inside it is
            // already handled. Checked in code rather than with a `:not()`
            // containing a combinator, which is a Selectors Level 4 feature
            // that throws on older engines and would take the whole setup
            // down with it.
            if (hero && hero.contains(el)) return;
            el.setAttribute('data-reveal', '');

            // Siblings arrive as a row, one just after the next, capped so a
            // twelve-card grid does not take a second and a half to appear.
            const key = el.parentElement;
            const index = groups.get(key) ?? 0;
            groups.set(key, index + 1);
            if (index > 0) {
                el.style.setProperty('--reveal-delay', `${Math.min(index, 5) * 70}ms`);
            }
        });
    });

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-in');
                observer.unobserve(entry.target);
            });
        },
        // A little before it is on screen, so it is already there by the time
        // somebody looks at it rather than animating under their eyes.
        { rootMargin: '0px 0px -8% 0px', threshold: 0.01 },
    );

    document.querySelectorAll('[data-reveal]').forEach((el) => observer.observe(el));

    // The failsafe. If anything at all goes wrong — an observer that never
    // fires, a browser quirk, a print stylesheet — the page must not stay
    // half-empty. Two seconds, then everything is shown regardless.
    setTimeout(revealAll, 2000);

    // Printing must never lose content to an animation that has not run.
    window.addEventListener('beforeprint', revealAll);
};

// ── The hero, on load ─────────────────────────────────────────────────────
const setupHero = () => {
    const hero = document.querySelector('.hero');
    if (!hero) return;

    if (stillMotion.matches) {
        hero.classList.add('is-ready');
        return;
    }

    hero.querySelectorAll(':scope > .wrap > *').forEach((el, i) => {
        if (el.classList.contains('shot')) return; // has its own timing
        el.setAttribute('data-lift', '');
        el.style.setProperty('--reveal-delay', `${i * 60}ms`);
    });

    // Next frame, so the starting state is painted before the transition to
    // the finishing one begins. Without it the browser collapses both into
    // one style resolution and nothing moves.
    requestAnimationFrame(() => requestAnimationFrame(() => hero.classList.add('is-ready')));
};

// ── Figures that count up ─────────────────────────────────────────────────
const setupCounters = () => {
    const stats = document.querySelectorAll('.stat b');
    if (!stats.length || stillMotion.matches || !('IntersectionObserver' in window)) return;

    const run = (el) => {
        const target = parseInt(el.textContent.replace(/\D/g, ''), 10);
        // Only a plain whole number counts up. "90%" or "1,240" is left
        // exactly as written rather than being guessed at and rebuilt wrong.
        if (!Number.isFinite(target) || String(target) !== el.textContent.trim()) return;
        // Nothing below three animates. Watching a figure travel from zero to
        // one over the better part of a second is not a flourish, it is a
        // delay with a spinner's manners.
        if (target < 3) return;

        const started = performance.now();
        const ms = 900;

        const tick = (now) => {
            const t = Math.min((now - started) / ms, 1);
            // Ease out: fast at first, settling onto the number rather than
            // stopping dead on it.
            el.textContent = String(Math.round(target * (1 - Math.pow(1 - t, 3))));
            if (t < 1) requestAnimationFrame(tick);
            else el.textContent = String(target);
        };

        requestAnimationFrame(tick);
    };

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                run(entry.target);
                observer.unobserve(entry.target);
            });
        },
        { threshold: 0.6 },
    );

    stats.forEach((el) => observer.observe(el));
};

// ── Dismissible flash notice ──────────────────────────────────────────────
document.querySelectorAll('[data-dismiss]').forEach((button) => {
    button.addEventListener('click', () => button.closest('.notice')?.remove());
});

// ── Go ────────────────────────────────────────────────────────────────────
// Wrapped, and the catch shows everything. These are decorations; a browser
// that chokes on one of them must still be left with a readable page, not a
// column of invisible sections.
try {
    setupReveal();
    setupHero();
    setupCounters();
} catch (error) {
    revealAll();
    document.querySelector('.hero')?.classList.add('is-ready');
    if (console && console.warn) {
        console.warn('marketing: animations disabled —', error);
    }
}

// Somebody can turn reduced motion on while the page is open. Honour it the
// moment they do rather than only on the next load.
stillMotion.addEventListener?.('change', (e) => {
    if (e.matches) {
        revealAll();
        document.querySelector('.hero')?.classList.add('is-ready');
    }
});
