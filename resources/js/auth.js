/**
 * Auth pages: sign in, sign up, reset, and the demonstration door.
 *
 * No Alpine and no Livewire here — these pages are outside the SPA, and every
 * behaviour below is a progressive enhancement: with this file blocked the
 * forms still submit, still validate on the server, and still say what went
 * wrong. Nothing here is a check; the server does the checking.
 */

// ── Show / hide password ──────────────────────────────────────────────────
document.querySelectorAll('.a-eye').forEach((btn) => {
    btn.addEventListener('click', () => {
        const field = document.getElementById(btn.getAttribute('data-eye'));
        if (!field) return;

        const reveal = field.type === 'password';
        field.type = reveal ? 'text' : 'password';
        btn.querySelector('i').className = reveal ? 'fas fa-eye-slash' : 'fas fa-eye';
        btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    });
});

// ── Clear a field's error the moment it is edited ─────────────────────────
// A red border that survives being fixed is how a form ends up arguing with
// the screen. The message stays (the server said it, and only the server can
// withdraw it); the alarm stops.
document.querySelectorAll('.a-input.is-bad').forEach((input) => {
    input.addEventListener(
        'input',
        () => {
            input.classList.remove('is-bad');
            input.removeAttribute('aria-invalid');
        },
        { once: true },
    );
});

// ── Password strength, as a description rather than a verdict ─────────────
// Deliberately not a gate. The rule that decides is Password::min(6) on the
// server; this only tells somebody where they are.
const strengthOf = (value) => {
    if (!value) return { score: 0, word: '' };

    let score = 0;
    if (value.length >= 10) score++;
    if (value.length >= 14) score++;
    if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
    if (/\d/.test(value)) score++;
    if (/[^\w\s]/.test(value)) score++;

    // Below the server's own floor, say so plainly rather than scoring it.
    if (value.length < 6) {
        return { score: 1, word: 'Too short — at least 6 characters' };
    }

    const words = ['', 'Weak', 'Fair', 'Good', 'Strong', 'Very strong'];

    return { score: Math.max(2, score), word: words[Math.min(score, 5)] };
};

document.querySelectorAll('[data-strength-for]').forEach((input) => {
    const meter = document.getElementById(input.getAttribute('data-strength-for'));
    if (!meter) return;

    const bar = meter.querySelector('.a-strength-bar > span');
    const word = meter.querySelector('.a-strength-word');

    input.addEventListener('input', () => {
        const { score, word: label } = strengthOf(input.value);

        meter.hidden = input.value === '';
        meter.dataset.score = String(score);
        if (bar) bar.style.width = `${(score / 5) * 100}%`;
        if (word) word.textContent = label;
    });
});

// ── Confirmation field: say it here, not after a round trip ───────────────
document.querySelectorAll('[data-match]').forEach((confirm) => {
    const source = document.getElementById(confirm.getAttribute('data-match'));
    const note = document.getElementById(confirm.getAttribute('aria-describedby'));
    if (!source) return;

    const check = () => {
        // Silent until there is something to compare: a "does not match"
        // under an empty box is noise, not help.
        const mismatch = confirm.value !== '' && source.value !== confirm.value;

        confirm.classList.toggle('is-bad', mismatch);
        if (note) note.hidden = !mismatch;
    };

    confirm.addEventListener('input', check);
    source.addEventListener('input', check);
});

// ── One submission per press ──────────────────────────────────────────────
// Creating a hospital twice because somebody double-clicked is not a mistake
// they can undo themselves. Re-enabled on pageshow so the browser's back
// button never lands on a form that cannot be submitted again.
document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('[type="submit"]');
        if (!button || button.disabled) return;

        const busy = button.getAttribute('data-busy');
        if (busy) {
            button.innerHTML = `<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> ${busy}`;
        }
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
    });
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('[type="submit"][aria-busy="true"]').forEach((button) => {
        button.disabled = false;
        button.removeAttribute('aria-busy');
    });
});

// ── Demo accounts (the /test-login rail only) ─────────────────────────────
const accounts = document.querySelectorAll('[data-demo-email]');

accounts.forEach((button) => {
    button.addEventListener('click', () => {
        const email = document.getElementById('email');
        const password = document.getElementById('password');
        if (!email || !password) return;

        email.value = button.dataset.demoEmail;
        password.value = button.dataset.demoPassword || '';

        // Autofilled values do not fire `input`, so anything watching for one
        // — the cleared-error handler above — has to be told by hand.
        [email, password].forEach((field) => {
            field.classList.remove('is-bad');
            field.dispatchEvent(new Event('input', { bubbles: true }));
        });

        accounts.forEach((other) => {
            other.classList.toggle('is-on', other === button);
            other.setAttribute('aria-pressed', String(other === button));
        });

        // Focus the submit button so Enter signs straight in.
        document.querySelector('.a-btn')?.focus();
    });
});
