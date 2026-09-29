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

async function login(ctx) {
    const page = await ctx.newPage();
    await page.goto(`${BASE}/ru/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="email"]', EMAIL);
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

async function shoot(ctx, theme, name, path, opts = {}) {
    if (ONLY && !ONLY.includes(name)) return;
    const page = await ctx.newPage();
    try {
        await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 30000 });
        /* Webfonts must be in before a shot, or headings render in the fallback
           and every type comparison is worthless. */
        await page.evaluate(() => document.fonts.ready);
        if (opts.click) {
            for (const sel of [].concat(opts.click)) {
                await page.click(sel, { timeout: 5000 }).catch(() => {});
            }
        }
        await page.waitForTimeout(opts.settle ?? 700);
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
    const agentId = await findAgentId(app);

    await shoot(app, theme, 'dashboard', '/dashboard');
    await shoot(app, theme, 'crm-board', '/crm');
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
    await shoot(app, theme, 'inbox', '/conversations');
    await shoot(app, theme, 'leads', '/leads');
    await shoot(app, theme, 'settings', '/settings');
    await app.close();
}

await browser.close();
console.log(`Done → ${OUT}`);
