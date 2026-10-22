/* Run against a disposable WordPress site with Free activated and Playwright on NODE_PATH.
 * RCS_UI_URL, RCS_UI_USER and RCS_UI_PASSWORD identify the test administrator.
 * RCS_UI_BROWSERS defaults to chromium; RCS_UI_REPORT_DIR must be on Scratch.
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
        const browser = await playwright[engine].launch();
        try {
            const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
            const page = await context.newPage();
            page.setDefaultTimeout(8000);
            const results = [], errors = [];
            page.on('pageerror', error => errors.push({ message: error.message, url: page.url(), stack: error.stack }));
            async function check(name, run) {
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
            async function submit(name) {
                await Promise.all([page.waitForNavigation(), page.getByRole('button', { name, exact: true }).click()]);
            }
            const field = name => page.getByLabel(name, { exact: true });
            await page.goto(base + '/wp-login.php');
            await field('Username or Email Address').fill(process.env.RCS_UI_USER);
            await field('Password').fill(process.env.RCS_UI_PASSWORD);
            await submit('Log In');

            await check('preview contains fixed CSS and preserves usable admin controls', async () => {
                await tab();
                await field('Source URL').fill('http://127.0.0.1/private');
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
                await field('Remove selector').fill('aside');
                await field('HTTP method').selectOption('POST');
                await field('POST body').fill('q=hello&limit=5');
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
