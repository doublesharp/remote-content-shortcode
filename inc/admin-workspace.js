/* Progressive enhancement: native POST remains available without this editor. */
(function () {
    'use strict';
    function setup() {
        var workspace = document.querySelector('.rcs-workspace');
        if (!workspace || !window.fetch || !window.FormData || !window.DOMParser || !window.AbortController) { return; }
        var form = workspace.querySelector('form.rcs-builder');
        var preview = document.getElementById('rcs-preview-panel');
        var output = document.getElementById('rcs-shortcode-panel');
        var feedback = document.getElementById('rcs-editor-feedback');
        var revision = 0, requestId = 0;
        var messages = window.rcsEditorMessages;
        if (!form || !preview || !output || !feedback || !messages) { return; }
        function fingerprint() {
            return JSON.stringify(Array.from(new FormData(form).entries()).filter(function (pair) {
                return pair[0] !== '_wpnonce' && pair[0] !== '_wp_http_referer';
            }));
        }
        var previewKey = preview.querySelector('iframe') ? fingerprint() : null;
        var codeKey = output.querySelector('textarea') ? fingerprint() : null;
        function status(panel, value, state) {
            panel.dataset.state = state;
            panel.querySelector('[role="status"]').textContent = value;
        }
        function stale() {
            var key = fingerprint();
            if (previewKey !== null && previewKey !== key) { status(preview, messages.stalePreview, 'stale'); }
            if (codeKey !== null && codeKey !== key) {
                status(output, messages.staleCode, 'stale');
                var copied = output.querySelector('#rcs-copy-status');
                if (copied) { copied.textContent = ''; }
            }
            var copy = output.querySelector('[data-rcs-copy]');
            if (copy) { copy.disabled = codeKey !== key; }
        }
        function changed() { revision++; stale(); }
        form.addEventListener('input', changed);
        form.addEventListener('change', changed);
        function replaceContents(target, source) {
            target.replaceChildren.apply(target, Array.from(source.childNodes));
        }
        function fitCode() {
            var field = output.querySelector('textarea');
            if (!field || !field.getClientRects().length) { return; }
            field.style.height = 'auto';
            field.style.height = Math.min(400, Math.max(100, field.scrollHeight + 2)) + 'px';
        }
        window.addEventListener('resize', fitCode);
        output.addEventListener('toggle', fitCode, true);
        fitCode();
        function fieldError(response) {
            var field = form.querySelector('#rcs-url');
            var incoming = response.querySelector('#rcs-url');
            var oldError = form.querySelector('#rcs-error-url');
            if (oldError) { oldError.remove(); }
            if (incoming.getAttribute('aria-invalid') === 'true') {
                field.setAttribute('aria-invalid', 'true');
                field.setAttribute('aria-describedby', incoming.getAttribute('aria-describedby'));
                var error = response.querySelector('#rcs-error-url');
                if (error) { field.insertAdjacentElement('afterend', error); }
            } else {
                field.removeAttribute('aria-invalid');
                field.setAttribute('aria-describedby', 'rcs-help-url');
            }
        }
        feedback.addEventListener('click', function (event) {
            if (!event.target.closest('a[href="#rcs-url"]')) { return; }
            event.preventDefault();
            var field = form.querySelector('#rcs-url');
            field.focus(); field.select();
        });
        form.addEventListener('submit', async function (event) {
            var action = event.submitter && event.submitter.value;
            if (action !== 'test' && action !== 'generate') { return; }
            event.preventDefault();
            if (workspace.getAttribute('aria-busy') === 'true') { return; }
            var key = fingerprint(), version = revision, id = ++requestId;
            var body = new FormData(form);
            body.set('rcs_action', action);
            var controller = new AbortController();
            var deadline = window.setTimeout(function () { controller.abort(); }, 20000);
            workspace.setAttribute('aria-busy', 'true');
            var buttons = form.querySelectorAll('button[value="test"], button[value="generate"]');
            buttons.forEach(function (button) { button.disabled = true; });
            feedback.removeAttribute('data-quiet');
            feedback.textContent = action === 'test' ? messages.testing : messages.generating;
            try {
                var response = await fetch(form.action || location.href, {
                    method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store', signal: controller.signal
                });
                if (!response.ok) { throw new Error('Request failed'); }
                var page = new DOMParser().parseFromString(await response.text(), 'text/html');
                var next = page.querySelector('.rcs-workspace');
                if (!next || !next.querySelector('#rcs-preview-panel') || !next.querySelector('#rcs-shortcode-panel') || !next.querySelector('#rcs-url')) {
                    throw new Error('Editor response missing');
                }
                if (id !== requestId || version !== revision || key !== fingerprint()) {
                    feedback.textContent = messages.changedDuringRequest;
                    stale();
                    return;
                }
                fieldError(next);
                replaceContents(feedback, next.querySelector('#rcs-editor-feedback'));
                if (action === 'test') {
                    var incoming = next.querySelector('#rcs-preview-panel');
                    var content = preview.querySelector('#rcs-preview-content');
                    var incomingContent = incoming.querySelector('#rcs-preview-content');
                    var oldFrame = content.querySelector('iframe');
                    var keepFrame = oldFrame && !incomingContent.querySelector('iframe') && incoming.dataset.state !== 'ready';
                    if (keepFrame) { oldFrame.remove(); }
                    replaceContents(content, incomingContent);
                    if (keepFrame) {
                        content.prepend(oldFrame);
                        status(preview, messages.stalePreview, 'stale');
                        previewKey = null;
                    } else {
                        status(preview, incoming.querySelector('[role="status"]').textContent, incoming.dataset.state);
                        previewKey = content.querySelector('iframe') ? key : null;
                    }
                }
                var nextCode = next.querySelector('#rcs-shortcode-panel');
                if (nextCode.querySelector('#rcs-generated-shortcode')) {
                    replaceContents(output.querySelector('#rcs-shortcode-content'), nextCode.querySelector('#rcs-shortcode-content'));
                    codeKey = key;
                    var verified = previewKey === key && preview.dataset.state === 'ready';
                    status(output, verified ? messages.ready : messages.unverified, 'ready');
                    document.dispatchEvent(new Event('rcs:output'));
                    fitCode();
                } else {
                    codeKey = null;
                    if (output.querySelector('textarea')) { status(output, messages.cannotGenerate, 'stale'); }
                    var problem = nextCode.querySelector('.notice');
                    if (problem) { feedback.append(problem); }
                }
                stale();
                if (!feedback.textContent.trim()) {
                    feedback.textContent = action === 'test' ? messages.testComplete : messages.generated;
                    feedback.setAttribute('data-quiet', 'true');
                }
                if (window.matchMedia('(max-width: 1100px)').matches && !feedback.querySelector('.notice-error')) {
                    var destination = action === 'test' ? preview : output;
                    destination.setAttribute('tabindex', '-1');
                    destination.focus({ preventScroll: true });
                    destination.scrollIntoView({ block: 'start' });
                }
            } catch (error) {
                feedback.textContent = messages.networkError;
                if (preview.querySelector('iframe')) { status(preview, messages.stalePreview, 'stale'); previewKey = null; }
            } finally {
                window.clearTimeout(deadline);
                workspace.setAttribute('aria-busy', 'false');
                buttons.forEach(function (button) { button.disabled = false; });
            }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', setup); }
    else { setup(); }
}());
