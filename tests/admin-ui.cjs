/* Run against a disposable WordPress site with Free activated and Playwright on NODE_PATH.
 * RCS_UI_URL, RCS_UI_USER and RCS_UI_PASSWORD identify the test administrator.
 * RCS_UI_BROWSERS defaults to chromium; RCS_UI_REPORT_DIR must be on Scratch.
 * RCS_UI_TEST_MATCH optionally selects test names; RCS_UI_EXECUTABLE_PATH selects an installed browser.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const playwright = require('playwright');

const base = process.env.RCS_UI_URL || 'http://127.0.0.1:8896';
const output = path.resolve(process.env.RCS_UI_REPORT_DIR || '');
const roots = ['/Volumes/Scratch/remote-content-shortcode/'];
if (process.env.GITHUB_ACTIONS === 'true' && process.env.RUNNER_TEMP) {
    roots.push(path.resolve(process.env.RUNNER_TEMP) + path.sep);
}
assert(roots.some(root => output.startsWith(root)), 'Browser evidence must stay on project Scratch or RUNNER_TEMP.');
assert(process.env.RCS_UI_USER && process.env.RCS_UI_PASSWORD, 'Set disposable test administrator credentials.');
fs.mkdirSync(output, { recursive: true });

(async () => {
    let failures = 0;
    for (const engine of (process.env.RCS_UI_BROWSERS || 'chromium').split(',')) {
        assert(['chromium', 'firefox', 'webkit'].includes(engine), 'Unsupported browser engine.');
        const browser = await playwright[engine].launch(process.env.RCS_UI_EXECUTABLE_PATH ? { executablePath: process.env.RCS_UI_EXECUTABLE_PATH } : {});
        try {
            const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
            const page = await context.newPage();
            page.setDefaultTimeout(8000);
            const results = [], errors = [];
            page.on('pageerror', error => errors.push({ message: error.message, url: page.url(), stack: error.stack }));
            async function check(name, run) {
                if (process.env.RCS_UI_TEST_MATCH && !new RegExp(process.env.RCS_UI_TEST_MATCH).test(name)) { return; }
                try {
                    await run();
                    results.push({ name, status: 'PASS' });
                    console.log('PASS', engine, name);
                } catch (error) {
                    ++failures;
                    results.push({ name, status: 'FAIL', error: error.message });
                    console.error('FAIL', engine, name, error.message);
                    await page.screenshot({ path: path.join(output, engine + '-failure-' + results.length + '.png'), fullPage: true }).catch(() => {});
                }
            }
            async function tab(name = 'builder') {
                await page.goto(base + '/wp-admin/tools.php?page=remote-content-shortcode&tab=' + name);
            }
            function isBuilderResponse(response) {
                const url = new URL(response.url()), current = new URL(page.url());
                return response.request().method() === 'POST' && url.origin === current.origin &&
                    url.pathname === current.pathname && url.searchParams.get('page') === 'remote-content-shortcode';
            }
            async function waitForEditor() {
                await page.waitForFunction(() => document.querySelector('.rcs-workspace')?.getAttribute('aria-busy') === 'false');
            }
            async function submit(name) {
                const button = page.getByRole('button', { name, exact: true });
                if (await page.locator('.rcs-workspace').count() && await button.evaluate(element => !!element.closest('form.rcs-builder'))) {
                    await Promise.all([page.waitForResponse(isBuilderResponse), button.click()]);
                    await waitForEditor();
                } else {
                    await Promise.all([page.waitForNavigation(), button.click()]);
                }
            }
            const field = name => page.getByLabel(name, { exact: true });
            async function reveal(name) {
                const section = field(name).locator('xpath=ancestor::details');
                if (await section.count() && await section.getAttribute('open') === null) {
                    await section.locator('summary').click();
                }
            }
            await page.goto(base + '/wp-login.php');
            await field('Username or Email Address').fill(process.env.RCS_UI_USER);
            await field('Password').fill(process.env.RCS_UI_PASSWORD);
            await Promise.all([page.waitForURL('**/wp-admin/**'), page.getByRole('button', { name: 'Log In', exact: true }).click()]);

            await check('preview contains fixed CSS and preserves usable admin controls', async () => {
                await tab();
                await field('Source URL').fill('http://127.0.0.1/private');
                await reveal('Fallback HTML');
                await field('Fallback HTML').fill('<main><div id="rcs-ui-overlay" style="position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:2147483647;background:white">Contained preview</div></main>');
                await submit('Test content');
                const frame = page.getByTitle('Remote content preview', { exact: true });
                assert.equal(await frame.getAttribute('sandbox'), '');
                await page.frameLocator('iframe[title="Remote content preview"]').getByText('Contained preview', { exact: true }).waitFor();
                assert.equal(await page.locator('#rcs-ui-overlay, .wrap main').count(), 0);
                await page.getByRole('button', { name: 'Generate shortcode', exact: true }).click({ trial: true });
                await field('Source URL').click();
                await page.screenshot({ path: path.join(output, engine + '-contained-preview.png'), fullPage: true });
            });
            await check('sanitized HTML is keyboard accessible by name', async () => {
                await page.locator('summary').filter({ hasText: 'Sanitized HTML' }).click();
                await field('Sanitized HTML').focus();
                assert(await field('Sanitized HTML').evaluate(element => document.activeElement === element));
            });
            await check('builder retains settings and supports 30-second shortcode generation', async () => {
                await tab();
                await field('Source URL').fill('https://example.com/article?language=en');
                await field('CSS selector').fill('main');
                await reveal('Remove selector');
                await field('Remove selector').fill('aside');
                await reveal('HTTP method');
                await field('HTTP method').selectOption('POST');
                await field('POST body').fill('q=hello&limit=5');
                await reveal('Cache lifetime (seconds)');
                await field('Cache lifetime (seconds)').fill('300');
                await field('Timeout (seconds)').fill('30');
                await field('Rewrite relative URLs').selectOption('true');
                await submit('Generate shortcode');
                assert.equal(await field('Source URL').inputValue(), 'https://example.com/article?language=en');
                assert.equal(await field('CSS selector').inputValue(), 'main');
                assert.equal(await field('Remove selector').inputValue(), 'aside');
                assert.equal(await field('HTTP method').inputValue(), 'POST');
                assert.equal(await field('POST body').inputValue(), 'q=hello&limit=5');
                assert.equal(await field('Cache lifetime (seconds)').inputValue(), '300');
                assert.equal(await field('Timeout (seconds)').inputValue(), '30');
                assert.equal(await field('Rewrite relative URLs').inputValue(), 'true');
                assert((await field('Generated shortcode').inputValue()).includes('timeout="&quot;30&quot;"'));
                assert((await page.locator('.wrap').innerText()).includes('five seconds'));
            });
            await check('builder retains public values after a validation error', async () => {
                await field('POST body').fill('[remote_content url="https://example.com"]');
                await submit('Generate shortcode');
                assert((await page.locator('.notice-error').innerText()).includes('invalid_builder_body'));
                assert.equal(await field('CSS selector').inputValue(), 'main');
                assert.equal(await field('POST body').inputValue(), '[remote_content url="https://example.com"]');
            });
            await check('builder invalid URL has one recovery notice for Test and Generate', async () => {
                await tab();
                await field('Source URL').fill('ftp://example.com/');
                await field('CSS selector').fill('h1');
                for (const action of ['Test content', 'Generate shortcode']) {
                    await submit(action);
                    assert.equal(await page.locator('.rcs-workspace .notice').count(), 1);
                    assert.equal(await page.locator('.rcs-url-notice').count(), 1);
                    assert(!(await page.locator('.rcs-workspace').innerText()).includes('Request failed.'));
                    assert.equal(await field('Source URL').getAttribute('aria-invalid'), 'true');
                    assert.equal(await field('CSS selector').inputValue(), 'h1');
                    await page.getByRole('link', { name: 'Correct the Source URL', exact: true }).click();
                    assert(await field('Source URL').evaluate(element => document.activeElement === element));
                }
                await field('Source URL').fill('https://example.com/articles');
                await submit('Generate shortcode');
                assert.equal(await page.locator('.rcs-workspace .notice').count(), 0);
                assert.equal(await field('Source URL').getAttribute('aria-invalid'), null);
                assert((await field('Generated shortcode').inputValue()).includes('https://example.com/articles'));
            });
            await check('persistent editor keeps preview and shortcode aligned without navigation', async () => {
                await tab();
                let navigations = 0;
                const recordNavigation = frame => { if (frame === page.mainFrame()) ++navigations; };
                page.on('framenavigated', recordNavigation);
                try {
                    await page.evaluate(() => {
                        window.__rcsEditorSession = 'persistent-editor';
                        Object.defineProperty(navigator, 'clipboard', {
                            configurable: true,
                            value: { writeText: async value => { window.__rcsCopiedShortcode = value; } },
                        });
                    });
                    const previewStatus = page.locator('#rcs-preview-status');
                    const codeStatus = page.locator('#rcs-shortcode-status');
                    const code = field('Generated shortcode');
                    const copy = page.locator('[data-rcs-copy]');
                    const frame = page.getByTitle('Remote content preview', { exact: true });
                    assert.equal(await previewStatus.getAttribute('role'), 'status');
                    assert.equal(await codeStatus.getAttribute('role'), 'status');
                    await field('Source URL').fill('http://127.0.0.1/private');
                    await field('CSS selector').fill('main');
                    await reveal('Fallback HTML');
                    await field('Fallback HTML').fill('<main><article>Persistent editor preview</article></main>');

                    await submit('Generate shortcode');
                    const firstCode = await code.inputValue();
                    assert(firstCode.includes('selector="&quot;main&quot;"'));
                    assert.equal(await page.locator('#rcs-preview-content iframe').count(), 0);
                    assert.equal(await copy.isDisabled(), false);
                    await copy.click();
                    assert.equal(await page.evaluate(() => window.__rcsCopiedShortcode), firstCode);

                    await submit('Test content');
                    assert.equal(await code.inputValue(), firstCode, 'Test must generate the exact shortcode for the submitted settings.');
                    assert.equal(await frame.getAttribute('sandbox'), '');
                    await page.frameLocator('iframe[title="Remote content preview"]').getByText('Persistent editor preview', { exact: true }).waitFor();
                    await frame.evaluate(element => { element.dataset.rcsTestIdentity = 'first-preview'; });
                    const firstPreview = await frame.getAttribute('srcdoc');

                    await field('CSS selector').fill('article');
                    assert.equal((await previewStatus.innerText()).trim(), 'Needs refresh');
                    assert.equal((await codeStatus.innerText()).trim(), 'Settings changed');
                    assert.equal(await copy.isDisabled(), true);
                    await submit('Generate shortcode');
                    const updatedCode = await code.inputValue();
                    assert.notEqual(updatedCode, firstCode);
                    assert(updatedCode.includes('selector="&quot;article&quot;"'));
                    assert.equal(await frame.getAttribute('data-rcs-test-identity'), 'first-preview', 'Generate must retain the existing iframe element.');
                    assert.equal(await frame.getAttribute('srcdoc'), firstPreview);
                    assert.equal((await previewStatus.innerText()).trim(), 'Needs refresh');
                    assert.equal(await copy.isDisabled(), false);
                    await submit('Test content');
                    assert.equal(await code.inputValue(), updatedCode);
                    await copy.click();
                    assert.equal(await page.evaluate(() => window.__rcsCopiedShortcode), updatedCode);

                    await frame.evaluate(element => { element.dataset.rcsTestIdentity = 'before-delayed-response'; });
                    let releaseResponse, markResponseCaptured, captureTimer;
                    const responseGate = new Promise(resolve => { releaseResponse = resolve; });
                    const responseCaptured = new Promise(resolve => { markResponseCaptured = resolve; });
                    const builderUrl = page.url();
                    const holdResponse = async route => {
                        if (route.request().method() !== 'POST') return route.continue();
                        // Forward the original request once, then hold only its response.
                        const response = await route.fetch();
                        markResponseCaptured();
                        await responseGate;
                        await route.fulfill({ response });
                    };
                    await page.route(builderUrl, holdResponse);
                    try {
                        await page.getByRole('button', { name: 'Test content', exact: true }).click();
                        await Promise.race([
                            responseCaptured,
                            new Promise((resolve, reject) => { captureTimer = setTimeout(() => reject(new Error('Builder POST response was not captured.')), 8000); }),
                        ]);
                        clearTimeout(captureTimer);
                        assert.equal(await page.locator('.rcs-workspace').getAttribute('aria-busy'), 'true');
                        await field('CSS selector').fill('section');
                        assert.equal((await previewStatus.innerText()).trim(), 'Needs refresh');
                        assert.equal((await codeStatus.innerText()).trim(), 'Settings changed');
                        assert.equal(await copy.isDisabled(), true);
                        const responseSettled = page.waitForResponse(isBuilderResponse);
                        releaseResponse();
                        await responseSettled;
                        await waitForEditor();
                        assert.equal(await field('CSS selector').inputValue(), 'section');
                        assert.equal(await code.inputValue(), updatedCode, 'An outdated response must not replace the displayed shortcode.');
                        assert.equal(await frame.getAttribute('data-rcs-test-identity'), 'before-delayed-response', 'An outdated response must not replace the preview.');
                        assert.equal((await previewStatus.innerText()).trim(), 'Needs refresh');
                        assert.equal((await codeStatus.innerText()).trim(), 'Settings changed');
                        assert.equal(await copy.isDisabled(), true);
                    } finally {
                        clearTimeout(captureTimer);
                        releaseResponse();
                        await page.unroute(builderUrl, holdResponse);
                    }

                    await submit('Test content');
                    assert((await code.inputValue()).includes('selector="&quot;section&quot;"'));
                    assert.equal(await copy.isDisabled(), false);
                    await copy.click();
                    assert.equal(await page.evaluate(() => window.__rcsCopiedShortcode), await code.inputValue());
                    await frame.evaluate(element => { element.dataset.rcsTestIdentity = 'before-failed-retest'; });
                    const retainedPreview = await frame.getAttribute('srcdoc');
                    await field('Fallback HTML').fill('');
                    await submit('Test content');
                    assert.equal(await frame.getAttribute('data-rcs-test-identity'), 'before-failed-retest');
                    assert.equal(await frame.getAttribute('srcdoc'), retainedPreview);
                    assert.equal((await previewStatus.innerText()).trim(), 'Needs refresh');
                    const feedback = (await page.locator('#rcs-editor-feedback').innerText()).trim();
                    assert(/preview|content|source|request/i.test(feedback), 'A failed retest must explain the problem.');
                    assert(!/WP_Error|Stack trace|Fatal error|TypeError/.test(feedback), 'Failure feedback must use plain language.');
                    assert.equal(navigations, 0, 'Editor actions must not navigate the main page.');
                    assert.equal(await page.evaluate(() => window.__rcsEditorSession), 'persistent-editor');
                } finally {
                    page.off('framenavigated', recordNavigation);
                }
            });
            await check('editor remains usable without JavaScript', async () => {
                const plain = await browser.newContext({ javaScriptEnabled: false, reducedMotion: 'reduce', storageState: await context.storageState(), viewport: { width: 390, height: 844 } });
                try {
                    const nojs = await plain.newPage();
                    await nojs.goto(base + '/wp-admin/tools.php?page=remote-content-shortcode&tab=builder');
                    await nojs.getByLabel('Source URL', { exact: true }).fill('http://127.0.0.1/private');
                    await nojs.getByLabel('CSS selector', { exact: true }).fill('h1');
                    await nojs.locator('details').filter({ has: nojs.getByLabel('Fallback HTML', { exact: true }) }).locator('summary').click();
                    await nojs.getByLabel('Fallback HTML', { exact: true }).fill('<h1>Without scripts</h1>');
                    await Promise.all([nojs.waitForNavigation(), nojs.getByRole('button', { name: 'Test content', exact: true }).click()]);
                    assert.equal(await nojs.getByTitle('Remote content preview', { exact: true }).getAttribute('sandbox'), '');
                    await nojs.getByText('Inspect shortcode', { exact: true }).click();
                    assert(await nojs.getByLabel('Generated shortcode', { exact: true }).isVisible());
                    assert((await nojs.getByLabel('Generated shortcode', { exact: true }).inputValue()).includes('selector="&quot;h1&quot;"'));
                    assert.equal(await nojs.locator('[data-rcs-copy]').isVisible(), false);
                } finally { await plain.close(); }
            });
            const alias = 'ui_' + engine;
            await check('invalid connection creation retains public scope and clears its secret', async () => {
                await tab('connections');
                const form = page.locator('form').filter({ has: page.locator('input[name="alias"][value=""]') });
                await form.getByLabel('Alias', { exact: true }).fill(alias);
                await form.getByLabel('HTTPS origin', { exact: true }).fill('http://example.com');
                await form.getByLabel('Exact endpoint path', { exact: true }).fill('/endpoint');
                await form.getByLabel('Secret', { exact: true }).fill('ui-disposable-token');
                await Promise.all([page.waitForNavigation(), form.getByRole('button', { name: 'Save connection', exact: true }).click()]);
                const retained = page.locator('form').filter({ has: page.locator('input[name="alias"][value="' + alias + '"]') });
                assert.equal(await retained.getByLabel('HTTPS origin', { exact: true }).inputValue(), 'http://example.com');
                assert.equal(await retained.getByLabel('Exact endpoint path', { exact: true }).inputValue(), '/endpoint');
                assert.equal(await retained.getByLabel('Secret', { exact: true }).inputValue(), '');
                assert(!(await page.content()).includes('ui-disposable-token'));
                await retained.getByLabel('HTTPS origin', { exact: true }).fill('https://example.com');
                await retained.getByLabel('Secret', { exact: true }).fill('ui-disposable-token');
                await Promise.all([page.waitForNavigation(), retained.getByRole('button', { name: 'Save connection', exact: true }).click()]);
                await page.locator('.notice-success').waitFor();
            });
            await check('invalid connection edit retains unchecked methods without populating another form', async () => {
                const form = page.locator('form').filter({ has: page.locator('input[name="alias"][value="' + alias + '"]') });
                await form.getByLabel('HTTPS origin', { exact: true }).fill('http://edited.example.com');
                await form.getByLabel('GET', { exact: true }).uncheck();
                await Promise.all([page.waitForNavigation(), form.getByRole('button', { name: 'Save connection', exact: true }).click()]);
                const retained = page.locator('form').filter({ has: page.locator('input[name="alias"][value="' + alias + '"]') });
                assert.equal(await retained.getByLabel('HTTPS origin', { exact: true }).inputValue(), 'http://edited.example.com');
                assert.equal(await retained.getByLabel('GET', { exact: true }).isChecked(), false);
                assert.equal(await page.locator('form').filter({ has: page.locator('input[name="alias"][value=""]') }).getByLabel('HTTPS origin', { exact: true }).inputValue(), '');
                await Promise.all([page.waitForNavigation(), retained.getByRole('button', { name: 'Delete connection', exact: true }).click()]);
            });
            await check('connection fields have WordPress text-input styling', async () => {
                await tab('connections');
                const input = page.locator('.wrap input.regular-text').first();
                assert.equal(await input.getAttribute('type'), 'text');
                assert((await input.boundingBox()).height >= 30);
                assert.equal(await input.evaluate(element => getComputedStyle(element).borderTopStyle), 'solid');
            });
            await check('all admin tabs fit desktop tablet and mobile widths', async () => {
                for (const width of [1440, 768, 390, 320]) {
                    await page.setViewportSize({ width, height: 900 });
                    for (const name of ['builder', 'connections', 'cache', 'usage', 'help']) {
                        await tab(name);
                        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), name + ' overflows at ' + width);
                        if (name === 'builder') {
                            assert.equal(await page.locator('.rcs-tabs').isVisible(), width > 1100);
                            assert.equal(await page.locator('.rcs-section-switcher').isVisible(), width <= 1100);
                        }
                    }
                }
                await page.screenshot({ path: path.join(output, engine + '-320-help.png'), fullPage: true });
            });
            if (errors.length) ++failures;
            fs.writeFileSync(path.join(output, engine + '-regressions.json'), JSON.stringify({ engine, version: browser.version(), results, errors }, null, 2));
        } finally {
            await browser.close();
        }
    }
    process.exitCode = failures ? 1 : 0;
})().catch(error => { console.error(error); process.exitCode = 1; });
