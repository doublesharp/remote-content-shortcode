/* Clipboard contract tests; real browser behavior is checked in the local WP lab. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const script = fs.readFileSync(path.join(__dirname, '../inc/admin.js'), 'utf8');
const shortcode = '[remote_content decode_atts="true" url="&quot;https://example.com/?a=1&amp;b=2&quot;"]q=hello&limit=5[/remote_content]';

function view(clipboard) {
    const attributes = { 'aria-controls': 'generated', 'data-status': 'status', 'data-success': 'Copied', 'data-fallback': 'Selected; use Copy' };
    const status = { textContent: '' }, actions = { hidden: true };
    const field = { value: shortcode, focused: false, selected: false,
        focus() { this.focused = true; }, select() { this.selected = true; } };
    let click;
    const button = {
        getAttribute: name => attributes[name],
        setAttribute: (name, value) => { attributes[name] = value; },
        removeAttribute: name => { delete attributes[name]; },
        closest: () => actions,
        addEventListener: (event, run) => { assert.equal(event, 'click'); click = run; }
    };
    vm.runInNewContext(script, { document: {
        readyState: 'complete', querySelectorAll: () => [button],
        addEventListener: () => {},
        getElementById: id => ({ generated: field, status })[id]
    }, navigator: { clipboard } });
    assert.equal(actions.hidden, false);
    return { field, status, attributes, click: () => click() };
}

(async () => {
    const writes = [];
    const copied = view({ writeText: async value => { writes.push(value); } });
    await copied.click();
    assert.deepEqual(writes, [shortcode]);
    assert.equal(copied.field.value, shortcode);
    assert.equal(copied.status.textContent, 'Copied');
    assert.equal(copied.field.focused, false);
    console.log('PASS copy preserves the complete encoded shortcode and keeps focus');

    for (const clipboard of [undefined, { writeText: async () => { throw new Error('Permission denied'); } }]) {
        const fallback = view(clipboard);
        await fallback.click();
        assert.equal(fallback.status.textContent, 'Selected; use Copy');
        assert.equal(fallback.field.focused, true);
        assert.equal(fallback.field.selected, true);
        assert.equal(fallback.attributes['aria-busy'], undefined);
    }
    console.log('PASS unavailable and denied clipboard access select the full field without false success');

    let finish, calls = 0;
    const waiting = view({ writeText: () => { ++calls; return new Promise(resolve => { finish = resolve; }); } });
    const first = waiting.click();
    assert.equal(waiting.attributes['aria-busy'], 'true');
    assert.equal(waiting.status.textContent, '');
    await waiting.click();
    assert.equal(calls, 1);
    finish(); await first;
    assert.equal(waiting.status.textContent, 'Copied');
    assert.equal(waiting.attributes['aria-busy'], undefined);
    console.log('PASS pending copy does not duplicate writes or announce early success');
})().catch(error => { console.error(error); process.exitCode = 1; });
