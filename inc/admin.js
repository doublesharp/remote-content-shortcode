/* Administrator helpers for Remote Content Shortcode. */
(function () {
    'use strict';

    function setup() {
        document.querySelectorAll('[data-rcs-copy]').forEach(function (button) {
            if (button.getAttribute('data-rcs-ready')) { return; }
            var field = document.getElementById(button.getAttribute('aria-controls'));
            var status = document.getElementById(button.getAttribute('data-status'));
            var actions = button.closest('.rcs-copy-actions');
            if (!field || !status || !actions) { return; }
            button.setAttribute('data-rcs-ready', 'true');
            var busy = false;

            button.addEventListener('click', async function () {
                if (busy) { return; }
                busy = true;
                button.setAttribute('aria-busy', 'true');
                status.textContent = '';
                try {
                    if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
                        throw new Error('Clipboard unavailable');
                    }
                    await navigator.clipboard.writeText(field.value);
                    status.textContent = button.getAttribute('data-success');
                } catch (error) {
                    var details = field.closest && field.closest('details');
                    if (details) { details.open = true; }
                    field.focus();
                    field.select();
                    status.textContent = button.getAttribute('data-fallback');
                } finally {
                    busy = false;
                    button.removeAttribute('aria-busy');
                }
            });
            actions.hidden = false;
        });
    }

    document.addEventListener('rcs:output', setup);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
}());
