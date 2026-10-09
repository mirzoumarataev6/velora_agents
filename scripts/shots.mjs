/**
 * Screenshot every main surface, light and dark, at 1920x1080.
 *
 * Replaces the headless-Chrome shell script that came before it: that one could
 * not carry a session into the browser (it curl'd each page to disk and
 * rendered the file), so nothing driven by Alpine — a tab, a dropdown, the
 * theme toggle — was ever exercised. Playwright drives the real app with a real
 * login, which is the only way a dark-mode shot is honest.
 *
 * The server and its throwaway database are set up by scripts/shots.sh; this
 * script only drives the browser.
 *
 * Usage: bash scripts/shots.sh [outdir]
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const BASE = process.env.SHOT_BASE ?? 'http://127.0.0.1:8899';
const OUT = resolve(process.env.SHOT_OUT ?? 'docs/screenshots/redesign');
const EMAIL = process.env.SHOT_EMAIL ?? 'demo@velora.test';
const PASSWORD = process.env.SHOT_PASSWORD ?? 'password';
const ONLY = process.env.SHOT_ONLY ? process.env.SHOT_ONLY.split(',') : null;

mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();

/* One context per theme. The theme is read out of localStorage before first
   paint (see layouts/dashboard), so it has to be seeded as an init script
   rather than toggled after load — otherwise every shot captures the flash. */
async function contextFor(theme) {
    const ctx = await browser.newContext({
        viewport: { width: 1920, height: 1080 },
        deviceScaleFactor: 1,
        colorScheme: theme,
        locale: 'ru-RU',
        reducedMotion: 'reduce',
    });
    await ctx.addInitScript(`try { localStorage.setItem('theme', ${JSON.stringify(theme)}); } catch (e) {}`);
    return ctx;
}

async function login(ctx, email = EMAIL) {
    const page = await ctx.newPage();
    await page.goto(`${BASE}/ru/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForURL(/dashboard|home|agents/, { timeout: 20000 }), page.click('button[type="submit"]')]);
    await page.close();
}

/* Discover the seeded agent's id so the detail page can be shot without it
   being hard-coded to whatever the seeder happened to make last time. */
async function findAgentId(ctx) {
    const page = await ctx.newPage();
    await page.goto(`${BASE}/agents`, { waitUntil: 'networkidle' });
    const href = await page
        .locator('a[href*="/agents/"]:not([href$="/create"])')
        .first()
        .getAttribute('href')
        .catch(() => null);
    await page.close();
    return href ? href.replace(/.*\/agents\//, '').split(/[/?#]/)[0] : null;
}

/* The seeded board's own URL. Discovered rather than hard-coded, for the same
   reason the agent's id is: the seeder's autoincrement is not stable across
   runs, and a hard-coded /bi/dashboards/1 silently shoots a 403 page. */
async function findDashboardPath(ctx) {
    const page = await ctx.newPage();
    await page.goto(`${BASE}/bi/dashboards`, { waitUntil: 'networkidle' });
    const href = await page
        .locator('a[href*="/bi/dashboards/"]')
        .first()
        .getAttribute('href')
        .catch(() => null);
    await page.close();
    return href ? new URL(href, BASE).pathname : null;
}

async function findCustomerPath(ctx) {
    const page = await ctx.newPage();
    await page.goto(BASE + '/crm', { waitUntil: 'networkidle' });
    const href = await page.locator('a[href*="/crm/customers/"]').first().getAttribute('href').catch(() => null);
    await page.close();
    return href ? new URL(href, BASE).pathname : null;
}

async function shoot(ctx, theme, name, path, opts = {}) {
    if (ONLY && !ONLY.includes(name)) return;
    const page = await ctx.newPage();
    await page.addInitScript(() => window.addEventListener('error', event => {
        // Alpine annotates parse failures with the expression and element, but
        // Playwright's pageerror stack drops those properties. Keep them in
        // screenshot diagnostics so a broken hidden tab can be located.
        if (event.error?.expression) {
            console.warn('Alpine expression:', event.error.expression, event.error.el?.outerHTML?.slice(0, 300));
        }
    }));
    page.on('pageerror', error => console.error(`  ! browser ${name}: ${error.stack ?? error.message}`));
    page.on('console', message => {
        if (message.type() !== 'error' && !message.text().startsWith('Alpine expression:')) return;
        const location = message.location();
        console.error(`  ! console ${name}: ${message.text()} (${location.url}:${location.lineNumber}:${location.columnNumber})`);
    });
    try {
        await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 30000 });
        if (process.env.SHOT_DEBUG_CRM === '1' && name === 'crm-board') {
            console.log(await page.evaluate(() => [...document.querySelectorAll('[x-show="selected"], [x-show="adding"]')].map(el => ({
                expression: el.getAttribute('x-show'),
                parent: el.parentElement?.tagName,
                containingScopes: (() => { const scopes = []; for (let node = el; node; node = node.parentElement) if (node.hasAttribute?.('x-data')) scopes.push(node.getAttribute('x-data').slice(0, 40)); return scopes; })(),
                selected: 'selected' in Alpine.$data(el),
                adding: 'adding' in Alpine.$data(el),
            }))));
        }
        /* Webfonts must be in before a shot, or headings render in the fallback
           and every type comparison is worthless. */
        await page.evaluate(() => document.fonts.ready);
        if (opts.click) {
            for (const sel of [].concat(opts.click)) {
                await page.click(sel, { timeout: 5000 }).catch(() => {});
            }
        }
        if (opts.fill) {
            for (const [selector, value] of opts.fill) {
                await page.fill(selector, value, { timeout: 5000 });
            }
        }
        await page.waitForTimeout(opts.settle ?? 700);
        if (process.env.SHOT_DEBUG_BI === '1' && name === 'bi-dashboard') {
            console.log(await page.evaluate(() => [...document.querySelectorAll('[data-bi-chart]')].map(el => ({
                type: JSON.parse(el.dataset.biChart).type, rows: JSON.parse(el.dataset.biChart).rows.length,
                first: JSON.parse(el.dataset.biChart).rows[0], columns: JSON.parse(el.dataset.biChart).columns,
                svg: !!el.querySelector('svg'), width: el.clientWidth, height: el.clientHeight,
                series: el.querySelectorAll('.apexcharts-series').length,
                svgText: el.querySelector('svg')?.outerHTML.slice(-350),
                canvas: el.querySelector('.apexcharts-canvas')?.getBoundingClientRect().toJSON(),
                path: el.querySelector('.apexcharts-series path')?.getAttribute('d')?.slice(0, 60),
            }))));
        }
        await page.screenshot({ path: `${OUT}/${name}-${theme}.png`, fullPage: opts.fullPage !== false });
        console.log(`  · ${name}-${theme}`);
    } catch (e) {
        console.error(`  ! ${name}-${theme}: ${e.message.split('\n')[0]}`);
    } finally {
        await page.close();
    }
}

for (const theme of (process.env.SHOT_THEMES ?? 'light,dark').split(',')) {
    console.log(`→ ${theme}`);
    const guest = await contextFor(theme);
    await shoot(guest, theme, 'landing', '/ru');
    await shoot(guest, theme, 'login', '/ru/login');
    await shoot(guest, theme, 'register', '/ru/register');
    await shoot(guest, theme, 'forgot-password', '/ru/forgot-password');
    await guest.close();

    const app = await contextFor(theme);
    await login(app);
    const agentId = (!ONLY || ONLY.some(name => name.startsWith('agent'))) ? await findAgentId(app) : null;

    await shoot(app, theme, 'dashboard', '/dashboard');
    await shoot(app, theme, 'crm-board', '/crm');
    await shoot(app, theme, 'crm-tasks-create', '/crm/tasks', {
        click: '.page-actions button', settle: 350, fullPage: false,
    });
    await shoot(app, theme, 'crm-offline', '/crm?origin=in_person');
    await shoot(app, theme, 'crm-customers', '/crm/customers');
    await shoot(app, theme, 'crm-customers-search', '/crm/customers', {
        fill: [['#customer-search', 'Бобур']], settle: 700,
    });
    await shoot(app, theme, 'crm-workspace-modal', '/crm', {
        click: 'button:has-text("Новая папка")', settle: 350, fullPage: false,
    });
    await shoot(app, theme, 'crm-new-deal', '/crm', {
        click: 'button:has-text("Новая сделка")', settle: 500, fullPage: false,
    });
    await shoot(app, theme, 'crm-deal-detail', '/crm', {
        click: 'tr.group', settle: 500, fullPage: false,
    });
    const customerPath = (!ONLY || ONLY.includes('crm-customer-profile')) ? await findCustomerPath(app) : null;
    if (customerPath) await shoot(app, theme, 'crm-customer-profile', customerPath);
    await shoot(app, theme, 'dashboard-30', '/dashboard?range=30');
    await shoot(app, theme, 'agents', '/agents');
    await shoot(app, theme, 'agent-create', '/agents/create');
    for (const step of [1, 2, 3, 4, 5]) {
        await shoot(app, theme, `agent-wizard-step-${step}`, '/agents/create', {
            click: `.stepper-step:nth-child(${step}) button`,
            fullPage: false,
        });
    }
    if (agentId) {
        /* Every tab the detail page actually has — the list lives in each
           locale's agents.php under show.tabs, and it is worth keeping in
           step: an earlier version of this script shot two tabs that do not
           exist ("overview", "persona"), which silently produced two more
           copies of whatever the default tab is. */
        for (const [name, tab] of [
            ['agent-knowledge', '?tab=knowledge'],
            ['agent-catalog', '?tab=catalog'],
            ['agent-database', '?tab=database'],
            ['agent-channels', '?tab=channels'],
            ['agent-playground', '?tab=playground'],
            ['agent-conversations', '?tab=conversations'],
            ['agent-settings', '?tab=settings'],
        ]) {
            await shoot(app, theme, name, `/agents/${agentId}${tab}`);
        }
    }
    /* The BI module. The board is shot with `settle` raised: gridstack is a
       lazily-imported chunk, so the cards are re-placed a beat after the page
       is otherwise idle, and a shot taken at `networkidle` catches the
       pre-gridstack CSS stack rather than the real board. */
    await shoot(app, theme, 'bi-data', '/bi/data');
    // The BI module's OWN connection form — host, port, credentials. It is
    // no longer a picker over the agents' connections, so the shot has to
    // open the modal to show anything.
    await shoot(app, theme, 'bi-connect', '/bi/data', {
        click: 'button:has-text("Подключить свою базу")',
        settle: 900,
        fullPage: false,
    });
    await shoot(app, theme, 'bi-dashboards', '/bi/dashboards');

    const boardPath = await findDashboardPath(app);

    if (boardPath) {
        await shoot(app, theme, 'bi-dashboard', boardPath, { settle: 1600 });
        await shoot(app, theme, 'bi-widget-editor', boardPath, {
            click: 'button:has-text("Добавить виджет")',
            settle: 1400,
            fullPage: false,
        });
        await shoot(app, theme, 'analysis-chat', boardPath, {settle: 1600, fullPage: false});
    }

    if (!ONLY || ONLY.some(name => ['analysis-empty', 'analysis-upload-progress'].includes(name))) {
        const empty = await contextFor(theme);
        await login(empty, 'empty@velora.test');
        await shoot(empty, theme, 'analysis-empty', '/bi/dashboards', {fullPage: false});
        if (!ONLY || ONLY.includes('analysis-upload-progress')) {
            const page = await empty.newPage();
            await page.goto(`${BASE}/bi/dashboards`, {waitUntil: 'networkidle'});
            await page.locator('input[type="file"]').setInputFiles({
                name: 'Продажи.csv', mimeType: 'text/csv',
                buffer: Buffer.from('Дата,Регион,Выручка\n01.09.2026,Душанбе,1800\n02.09.2026,Худжанд,1250\n'),
            });
            await page.locator('[data-analysis-source]').waitFor({timeout: 10000});
            await page.screenshot({path: `${OUT}/analysis-upload-progress-${theme}.png`});
            console.log(`  · analysis-upload-progress-${theme}`);
            await page.close();
        }
        // The pending-ingest page polls by design. Closing its context while
        // that fetch is outstanding can stall Playwright; browser.close below
        // tears it down once all requested shots are saved.
    }

    await shoot(app, theme, 'inbox', '/conversations');
    await shoot(app, theme, 'leads', '/leads');
    await shoot(app, theme, 'settings', '/settings');
    await app.close();
}

await browser.close();
console.log(`Done → ${OUT}`);
