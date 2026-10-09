/**
 * Read-only visual inventory for the throwaway instance from scripts/shots.sh.
 * Run while that instance is listening: SHOT_BASE=http://127.0.0.1:8899 node scripts/audit-ui.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';

const base = process.env.SHOT_BASE ?? 'http://127.0.0.1:8899';
const output = process.env.AUDIT_OUT ?? '/tmp/velora-ui-audit-2026-10-09';
const only = process.env.AUDIT_ONLY?.split(',');
const viewportOnly = process.env.AUDIT_VIEWPORTS?.split(',');
const auditLocale = process.env.AUDIT_LOCALE ?? 'ru';
const auditTheme = process.env.AUDIT_THEME ?? 'light';
mkdirSync(output, { recursive: true });
const browser = await chromium.launch();
const routes = [
    ['landing', `/${auditLocale}`], ['login', `/${auditLocale}/login`], ['register', `/${auditLocale}/register`],
    ['forgot-password', `/${auditLocale}/forgot-password`], ['dashboard', '/dashboard'],
    ['agents', '/agents'], ['agent-create', '/agents/create'],
    ['crm-board', '/crm'], ['crm-offline', '/crm?origin=in_person'],
    ['crm-customers', '/crm/customers'], ['crm-tasks', '/crm/tasks'],
    ['bi-data', '/bi/data'],
    ['bi-dashboards', '/bi/dashboards'], ['inbox', '/conversations'],
    ['leads', '/leads'], ['settings', '/settings'],
];
const results = [];

for (const [width, height, label] of [[320, 720, 'small-mobile'], [390, 844, 'mobile'], [768, 1024, 'tablet'], [1365, 900, 'desktop'], [1920, 1080, 'wide-desktop']].filter(([, , label]) => !viewportOnly || viewportOnly.includes(label))) {
    const context = await browser.newContext({
        viewport: { width, height }, deviceScaleFactor: 1, locale: { ru: 'ru-RU', tg: 'tg-TJ', en: 'en-US' }[auditLocale] ?? 'ru-RU', reducedMotion: 'reduce',
    });
    await context.addInitScript(theme => localStorage.setItem('theme', theme), auditTheme);
    const guestContext = await browser.newContext({
        viewport: { width, height }, deviceScaleFactor: 1, locale: { ru: 'ru-RU', tg: 'tg-TJ', en: 'en-US' }[auditLocale] ?? 'ru-RU', reducedMotion: 'reduce',
    });
    await guestContext.addInitScript(theme => localStorage.setItem('theme', theme), auditTheme);
    const guestPage = await guestContext.newPage();
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    guestPage.on('pageerror', e => errors.push(e.message));
    page.on('console', message => {
        if (message.type() === 'warning' && message.text().startsWith('Alpine Expression Error')) errors.push(message.text());
    });
    guestPage.on('console', message => {
        if (message.type() === 'warning' && message.text().startsWith('Alpine Expression Error')) errors.push(message.text());
    });
    await page.goto(`${base}/${auditLocale}/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'demo@velora.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([page.waitForURL(/dashboard|home|agents/), page.click('button[type="submit"]')]);
    await page.goto(`${base}/dashboard?lang=${auditLocale}`, { waitUntil: 'domcontentloaded' });

    await page.goto(`${base}/agents`, { waitUntil: 'domcontentloaded' });
    const agentHref = await page.locator('a[href*="/agents/"]:not([href$="/create"])').first().getAttribute('href').catch(() => null);
    const agent = agentHref?.replace(/.*\/agents\//, '').split(/[/?#]/)[0];
    if (agent) for (const tab of ['knowledge', 'catalog', 'database', 'channels', 'playground', 'conversations', 'settings']) {
        routes.push([`agent-${tab}`, `/agents/${agent}?tab=${tab}`]);
    }
    await page.goto(`${base}/bi/dashboards`, { waitUntil: 'domcontentloaded' });
    const boardHref = await page.locator('a[href*="/bi/dashboards/"]').first().getAttribute('href').catch(() => null);
    if (boardHref) routes.push(['bi-board', new URL(boardHref, base).pathname]);
    await page.goto(`${base}/crm`, { waitUntil: 'domcontentloaded' });
    const customerHref = await page.locator('a[href*="/crm/customers/"]').first().getAttribute('href').catch(() => null);
    if (customerHref) routes.push(['crm-customer-profile', new URL(customerHref, base).pathname]);

    for (const [name, path] of [...new Map(routes).entries()].filter(([name]) => !only || only.includes(name))) {
        errors.length = 0;
        try {
            const target = ['landing', 'login', 'register', 'forgot-password'].includes(name) ? guestPage : page;
            const response = await target.goto(`${base}${path}`, { waitUntil: 'domcontentloaded', timeout: 15000 });
            await target.evaluate(() => document.fonts.ready);
            await target.waitForTimeout(250);
            const metrics = await target.evaluate(() => {
                const vw = document.documentElement.clientWidth;
                const visible = e => {
                    const r = e.getBoundingClientRect(); const s = getComputedStyle(e);
                    return r.width > 0 && r.height > 0 && r.right > 0 && r.left < vw && s.visibility !== 'hidden' && s.display !== 'none';
                };
                const overflow = [...document.querySelectorAll('body *')].filter(visible).filter(e => {
                    const r = e.getBoundingClientRect();
                    if (r.right <= vw + 2) return false;
                    for (let parent = e.parentElement; parent; parent = parent.parentElement) {
                        const s = getComputedStyle(parent);
                        if (['auto', 'scroll', 'hidden', 'clip'].includes(s.overflowX) && parent.scrollWidth > parent.clientWidth + 2) return false;
                    }
                    return true;
                }).map(e => {
                    const r = e.getBoundingClientRect(); return { element: e.tagName.toLowerCase(), class: String(e.className).slice(0, 100), text: e.textContent?.trim().slice(0, 70), left: Math.round(r.left), right: Math.round(r.right) };
                }).slice(0, 25);
                const tiny = [...document.querySelectorAll('button,a,input,select,textarea')].filter(visible).map(e => {
                    const r = e.getBoundingClientRect(); return { tag: e.tagName, label: (e.getAttribute('aria-label') || e.textContent || e.getAttribute('placeholder') || '').trim().slice(0, 70), width: Math.round(r.width), height: Math.round(r.height) };
                }).filter(e => e.width < 36 || e.height < 36).slice(0, 45);
                const nameless = [...document.querySelectorAll('button,a,input,select,textarea')].filter(visible).filter(e => !((e.getAttribute('aria-label') || e.getAttribute('title') || e.textContent || e.getAttribute('placeholder') || '').trim()) && !e.querySelector('img[alt]')).map(e => ({ tag: e.tagName, class: e.className, html: e.outerHTML.slice(0, 170) })).slice(0, 20);
                const expanded = [...document.querySelectorAll('body *')].filter(e => e.scrollWidth > e.clientWidth + 2 && e.clientWidth > 0).map(e => {
                    const r = e.getBoundingClientRect();
                    return {element: e.tagName.toLowerCase(), class: String(e.className).slice(0, 90), clientWidth: e.clientWidth, scrollWidth: e.scrollWidth, right: Math.round(r.right)};
                }).slice(0, 25);
                return { title: document.title, bodyWidth: document.body.scrollWidth, viewport: vw, font: getComputedStyle(document.body).fontFamily, h1: document.querySelector('h1')?.textContent?.trim().slice(0, 100), overflow, expanded, tiny, nameless };
            });
            if (process.env.AUDIT_INTERACTIONS === '1') {
                if (name === 'crm-board') {
                    const open = target.locator('[data-qa="open-deal"]').first();
                    await open.focus();
                    await target.keyboard.press('Enter');
                    const close = target.locator('[x-ref="dealClose"]');
                    await close.waitFor({state: 'visible', timeout: 2000}).catch(() => { throw new Error('Deal dialog did not open from keyboard'); });
                    if (!await close.evaluate(e => e === document.activeElement)) throw new Error('Deal dialog did not receive focus');
                    const dateState = await target.evaluate(() => {
                        const european = document.documentElement.lang !== 'en';
                        const display = crmFormatDate('2026-10-09', european);
                        const dueDisplay = crmFormatDateTime('2026-10-09T14:30', european);
                        return {
                            valid: crmParseDate(display, european),
                            invalid: crmParseDate(european ? '31.02.2026' : '2026-02-31', european),
                            dueValid: crmParseDateTime(dueDisplay, european),
                            dueInvalid: crmParseDateTime(`${display} 25:61`, european),
                        };
                    });
                    if (dateState.valid !== '2026-10-09' || dateState.invalid !== null || dateState.dueValid !== '2026-10-09T14:30' || dateState.dueInvalid !== null) throw new Error('CRM localized date parsing failed');
                    await target.keyboard.press('Escape');
                    await close.waitFor({state: 'hidden', timeout: 2000}).catch(() => { throw new Error('Escape did not close deal dialog'); });
                    if (!await open.evaluate(e => e === document.activeElement)) throw new Error('Deal focus was not restored');
                }
                if (name === 'bi-board') {
                    const chat = target.locator('[data-qa="chat-toggle"]');
                    const chatClose = target.locator('[x-ref="chatClose"]');
                    if (await chatClose.isVisible()) throw new Error('Analyst obscures dashboard on first paint');
                    await chat.click();
                    await chatClose.waitFor({state: 'visible', timeout: 2000}).catch(() => { throw new Error('Analyst did not open'); });
                    if (!await chatClose.evaluate(e => e === document.activeElement)) throw new Error('Analyst did not receive focus');
                    await target.keyboard.press('Escape');
                    await chatClose.waitFor({state: 'hidden', timeout: 2000}).catch(() => { throw new Error('Escape did not close analyst'); });
                    if (label === 'mobile' || label === 'small-mobile') {
                        const filters = target.locator('[data-qa="filter-toggle"]');
                        const date = target.locator('input[x-model="globalFromDisplay"]');
                        if (await date.isVisible()) throw new Error('Filters obscure mobile charts on first paint');
                        await filters.click();
                        await date.waitFor({state: 'visible', timeout: 2000}).catch(() => { throw new Error('Mobile filters did not expand'); });
                        const dateState = await target.locator('[x-data*="biBoard("]').evaluate(element => {
                            const board = window.Alpine.$data(element);
                            const display = board.formatFilterDate('2026-10-09');
                            return {display, valid: board.parseFilterDate(display), invalid: board.parseFilterDate(board.dateFormat === 'DD.MM.YYYY' ? '31.02.2026' : '2026-02-31')};
                        });
                        if (dateState.valid !== '2026-10-09' || dateState.invalid !== null) throw new Error('Localized date parsing failed');
                        await filters.click();
                        await date.waitFor({state: 'hidden', timeout: 2000}).catch(() => { throw new Error('Mobile filters did not collapse'); });
                    }
                }
                if (name === 'crm-tasks') {
                    const dateState = await target.locator('[x-data*="crmTaskBoard("]').evaluate(element => {
                        const board = window.Alpine.$data(element);
                        board.dueDisplay = board.formatDue('2026-10-09T14:30');
                        const valid = board.parseDue();
                        board.dueDisplay = board.dateEuropean ? '31.02.2026 14:30' : '2026-02-31 14:30';
                        const invalid = board.parseDue();
                        board.dueDisplay = '';
                        return {valid, invalid};
                    });
                    if (dateState.valid !== '2026-10-09T14:30' || dateState.invalid !== null) throw new Error('Task localized date parsing failed');
                }
                if (name === 'inbox') {
                    const dateState = await target.locator('[x-data*="conversationsInbox("]').evaluate(element => {
                        const inbox = window.Alpine.$data(element);
                        const display = inbox.formatDateFilter('2026-10-09');
                        return {valid: inbox.parseDateFilter(display), invalid: inbox.parseDateFilter(inbox.europeanDate ? '31.02.2026' : '2026-02-31')};
                    });
                    if (dateState.valid !== '2026-10-09' || dateState.invalid !== null) throw new Error('Inbox localized date parsing failed');
                }
                if (name === 'crm-customer-profile') {
                    const dateState = await target.locator('[x-data="customerDueDate()"]').evaluate(element => {
                        const field = window.Alpine.$data(element);
                        field.display = field.format('2026-10-09');
                        const valid = field.iso();
                        field.display = field.european ? '31.02.2026' : '2026-02-31';
                        const invalid = field.iso();
                        field.display = '';
                        return {valid, invalid};
                    });
                    if (dateState.valid !== '2026-10-09' || dateState.invalid !== null) throw new Error('Customer localized date parsing failed');
                }
            }
            const row = { name, path, viewportLabel: label, status: response?.status(), url: target.url(), errors: [...errors], ...metrics };
            results.push(row);
            await target.screenshot({ path: `${output}/${name}-${label}.png`, fullPage: false });
            console.log(`${label} ${name} ${row.status} width=${metrics.bodyWidth}/${metrics.viewport} overflow=${metrics.overflow.length} tiny=${metrics.tiny.length} errors=${errors.length}`);
        } catch (error) {
            results.push({ name, path, viewport: label, error: error.message });
            console.log(`${label} ${name} ERROR ${error.message.slice(0, 100)}`);
        }
    }
    await guestContext.close();
    await context.close();
}
await browser.close();
writeFileSync(`${output}/metrics.json`, JSON.stringify(results, null, 2));
if (process.env.AUDIT_ASSERT === '1') {
    const failures = results.filter(row => row.error || row.status !== 200 || row.errors?.length || row.bodyWidth > row.viewport + 2);
    if (failures.length) {
        for (const row of failures) console.error(`FAIL ${row.viewportLabel} ${row.name}: ${row.error ?? `HTTP ${row.status}, width ${row.bodyWidth}/${row.viewport}, JS errors ${row.errors?.length ?? 0}`}`);
        process.exitCode = 1;
    }
}
