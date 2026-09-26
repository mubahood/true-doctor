// The picture check on public forms: "new picture" without losing what was typed
// elsewhere on the form, and a fresh picture after the browser's back button
// (a spent picture must never be shown again).
export function initHumanChecks(root = document) {
    root.querySelectorAll('[data-human-check]').forEach((box) => {
        const img = box.querySelector('[data-hc-img]');
        const id = box.querySelector('[data-hc-id]');
        const answer = box.querySelector('input[name="human_answer"]');
        const button = box.querySelector('[data-hc-new]');

        const fresh = async () => {
            button?.classList.add('is-spinning');
            try {
                const res = await fetch(box.dataset.fresh, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!res.ok) return;
                const data = await res.json();
                id.value = data.id;
                img.src = data.src;
                if (answer) {
                    answer.value = '';
                    answer.focus();
                }
            } finally {
                button?.classList.remove('is-spinning');
            }
        };

        button?.addEventListener('click', fresh);
        answer?.addEventListener('input', () => {
            answer.value = answer.value.toUpperCase().replace(/\s+/g, '');
        });
        // Restored from the back/forward cache: that picture has been spent.
        window.addEventListener('pageshow', (e) => {
            if (e.persisted) fresh();
        });
    });
}
