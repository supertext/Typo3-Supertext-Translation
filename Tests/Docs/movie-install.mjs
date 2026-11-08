#!/usr/bin/env node
/**
 * Records the install and configuration movie (MP4 only, no GIF) from a FRESH local demo whose
 * extension talks to stand-in.mjs, like movie.mjs. An administrator installs the extension
 * (terminal: Composer, extension:setup, cache:flush), enters an API key in the extension
 * configuration, checks the Supertext language of a site language and shows where editors
 * translate (TYPO3's own "Create new translation"). See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://127.0.0.1:8090 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… npm run movie:install
 *
 * The admin must be a system maintainer (the setup admin is one). Run the demo WITHOUT
 * SUPERTEXT_API_KEY (it would take precedence over the field); the movie types the made-up key
 * st-demo-0000-0000-0000 into the extension configuration. MOVIE_VOICE=0 makes a silent movie.
 * MOVIE_OUT: where the MP4 goes (default Tests/Docs/movie/out/Typo3-Supertext-Translation-install.mp4,
 * git-ignored). CHROMIUM_PATH: another Chromium than Playwright's. Needs ffmpeg.
 */
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { MovieKit } from './movie/movie-kit.mjs';
import { overlayToFront } from './movie/top-layer.mjs';

const B = (process.env.BASE_URL ?? 'http://127.0.0.1:8090').replace(/\/$/, '');
const PAGE_ID = Number(process.env.PAGE_ID || 5);
const here = (p) => fileURLToPath(new URL(p, import.meta.url));
const out = process.env.MOVIE_OUT ?? here('movie/out/Typo3-Supertext-Translation-install.mp4');
const need = (name) => process.env[name] ?? (() => { throw new Error(`Set ${name}`); })();
const FAKE_KEY = 'st-demo-0000-0000-0000';
const PASSWORD = need('DEMO_ADMIN_PASSWORD');

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' });
const frame = () => page.frame('list_frame');

// Sign in as the demo administrator (not recorded): configuration is an administrator's task.
await page.goto(`${B}/typo3/`);
await page.fill('input[name=username]', need('DEMO_ADMIN_EMAIL'));
await page.fill('input[type=password]', PASSWORD);
await page.click('button[type=submit]');
await page.waitForURL(/\/typo3\/(main|module)/, { timeout: 30000 });

// Open the page module on FAQs once, so the module later opens there (with the page tree expanded).
await page.goto(`${B}/typo3/module/web/layout?id=${PAGE_ID}`);
await frame().getByRole('button', { name: /English/ }).first().waitFor({ timeout: 30000 });

// System → Settings asks to confirm the password first: do that now, so it isn't recorded.
await page.goto(`${B}/typo3/module/system/settings`);
await page.waitForTimeout(2500);
if (page.frames().some((f) => f.url().includes('/sudo-mode/'))) {
	await page.keyboard.type(PASSWORD);
	await page.keyboard.press('Enter');
}
await frame().getByText(/Configure extensions/i).first().waitFor({ timeout: 30000 });

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'typo3-movie-install-'));
await page.goto('about:blank');
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') });

/** More commands in the terminal kit.terminal() opened (same look; kit.terminal() would start a new one). */
async function typeMore(lines) {
	await page.evaluate(() => document.querySelector('#x > .p.c:last-child')?.remove());
	for (const l of lines) {
		if (l.cmd !== undefined) {
			await page.evaluate(() => { const x = document.getElementById('x');
				const p = document.createElement('span'); p.className = 'p'; p.textContent = '$ '; x.appendChild(p);
				const c = document.createElement('span'); c.className = 'c'; x.appendChild(c); });
			for (const ch of l.cmd) { await page.evaluate((ch) => { document.querySelector('#x .c').textContent += ch; }, ch); await page.waitForTimeout(38); }
			await page.waitForTimeout(450);
			await page.evaluate(() => { document.querySelector('#x .c').classList.remove('c'); document.getElementById('x').append('\n'); });
		} else {
			await page.evaluate((o) => { const s = document.createElement('span'); s.className = 'o'; s.textContent = o + '\n'; document.getElementById('x').appendChild(s); }, l.out ?? '');
			await page.waitForTimeout(l.wait ?? 180);
		}
	}
	await page.evaluate(() => { const c = document.createElement('span'); c.className = 'p c'; c.textContent = '$ '; document.getElementById('x').appendChild(c); });
}

/** Ripple at the cursor, then `action` (for clicks the kit can't do itself, e.g. navigations). */
const press = async (locator, action) => {
	await kit.moveTo(locator);
	await page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y]);
	await action();
};

// 1. Terminal: the commands from docs/INSTALLATION.md (output shortened).
await kit.say('First, add the Supertext repository to your TYPO3 project with Composer.', {
	caption: 'Add the repository to <b>Composer</b>',
	during: () => kit.terminal({ title: 'my-site', lines: [
		{ cmd: 'composer config repositories.supertext-typo3 vcs https://github.com/Supertext/Typo3-Supertext-Translation' },
	] }),
});
await kit.say('Then require the extension. It brings everything it needs with it.', {
	caption: 'Require the extension: <b>composer require</b>',
	during: () => typeMore([
		{ cmd: 'composer require supertext/typo3-translation:dev-main' },
		{ out: './composer.json has been updated' },
		{ out: 'Package operations: 1 install, 0 updates, 0 removals' },
		{ out: '  - Installing supertext/typo3-translation (dev-main)' },
		{ out: 'Generating autoload files' },
	]),
});
await kit.say("Set it up with TYPO3's console.", {
	caption: 'Set it up: <b>extension:setup</b>',
	during: () => typeMore([
		{ cmd: 'vendor/bin/typo3 extension:setup -e supertext_translation' },
		{ out: ' [OK] Extension(s) "supertext_translation" successfully set up.' },
	]),
});
await kit.say("And flush TYPO3's caches.", {
	caption: 'Flush the caches: <b>cache:flush</b>',
	during: () => typeMore([{ cmd: 'vendor/bin/typo3 cache:flush' }]),
});
await kit.hold(300);

// 2. The backend as administrator: System → Settings → Extension Configuration (loading time cut).
kit.pause();
await page.goto(`${B}/typo3/module/dashboard`);
await page.waitForLoadState('load');
await page.waitForTimeout(1500);
await kit.sync();
kit.resume();
const settingsNav = page.locator('a[href*="/module/system/settings"]').first();
await kit.say('Now sign in to the TYPO3 backend as a system maintainer. In the module menu, open System, and then Settings.', {
	caption: 'In the backend: <b>System → Settings</b>',
	during: async () => {
		await kit.hold(600);
		await kit.click(settingsNav);
		await frame().getByText(/Configure extensions/i).first().waitFor({ timeout: 30000 });
		await kit.sync();
	},
});
await kit.say('Under Extension Configuration, click Configure extensions, and open the section supertext translation.', {
	caption: '<b>Configure extensions…</b> → <b>supertext_translation</b>',
	during: async () => {
		await kit.click(frame().getByText(/Configure extensions/i).first());
		const ext = page.getByText('supertext_translation', { exact: true }).last();
		await ext.waitFor();
		await overlayToFront(page);
		await kit.hold(500);
		await kit.click(ext);
		await page.locator('input[name="apiKey"]').waitFor({ state: 'attached' });
		// Straight to the key: its label at the top of the dialog (the Status block above it isn't part of this movie).
		await page.getByText(/^\s*Supertext API key/).first().evaluate((el) => el.scrollIntoView({ block: 'start', behavior: 'smooth' }));
	},
});

// 3. The API key.
const keyField = page.locator('input[name="apiKey"]');
await kit.say('Generate your API key at supertext.com, under Integrations, A P I. No account yet? Create one at supertext.com.', {
	caption: 'API key: <b>supertext.com → Integrations → API</b><br>No account yet? Create one at <b>supertext.com</b>',
	during: async () => {
		await kit.hold(500);
		await kit.moveTo(keyField);
	},
});
await kit.say('Paste it into Supertext API key.', {
	caption: 'Paste it into <b>Supertext API key</b>',
	during: () => kit.type(keyField, FAKE_KEY),
});
const save = page.getByRole('button', { name: 'Save "supertext_translation" configuration' });
await kit.say('Then save the configuration.', {
	caption: 'Click <b>Save</b>',
	during: async () => {
		await save.scrollIntoViewIfNeeded();
		await kit.hold(400);
		await press(save, () => Promise.all([page.waitForResponse((r) => r.request().method() === 'POST'), save.click()]));
		await kit.hold(600);
	},
});

// 4. The site languages: Sites → Setup → edit the site → Languages → Deutsch (Schweiz) → Supertext.
await kit.say('Next, the Supertext language of each site language. Open Sites, then Setup, and edit the site.', {
	caption: '<b>Sites → Setup</b> → edit the site',
	during: async () => {
		await page.keyboard.press('Escape');
		await kit.hold(500);
		await kit.click(page.locator('a[href*="/module/site/configuration"]').first());
		const edit = frame().locator('a[title="Edit site configuration"]').first();
		await edit.waitFor();
		await kit.sync();
		await kit.click(edit);
		await frame().getByRole('tab', { name: /Languages/i }).waitFor();
	},
});
await kit.say('On the Languages tab, open the language Deutsch, Schweiz. Each site language has its own settings.', {
	caption: '<b>Languages</b> → <b>Deutsch (Schweiz)</b>',
	during: async () => {
		await kit.click(frame().getByRole('tab', { name: /Languages/i }));
		await kit.hold(400);
		await kit.click(frame().getByText('Deutsch (Schweiz)').first());
		await frame().locator('select[name*="supertext_code"]').first().waitFor();
	},
});
const codeSelect = frame().locator('select[name*="supertext_code"]').first();
const heading = frame().getByText('Supertext', { exact: true }).last();
await kit.say('In the section Supertext, the Supertext language is preselected: German, Switzerland.', {
	caption: '<b>Supertext language</b>: Default (de-CH)',
	during: async () => {
		await heading.evaluate((el) => el.scrollIntoView({ block: 'start', behavior: 'smooth' }));
		await kit.hold(1000);
		await kit.moveTo(codeSelect);
	},
});
const review = frame().locator('[name*="supertext_review"]').last().locator('xpath=ancestor::div[contains(@class,"form-group")][1]');
await kit.say('Translation review can be switched on per language. We leave it off.', {
	caption: '<b>Translation review</b>: optional, per language (left off)',
	during: async () => { await kit.hold(300); await kit.moveTo(review.locator('input[type=checkbox]').first()); },
});
const saveSite = frame().getByRole('button', { name: 'Save' }).first();
await kit.say('Save, and check the other languages the same way.', {
	caption: 'Click <b>Save</b>, then the same for the other languages',
	during: async () => {
		await press(saveSite, () => Promise.all([frame().waitForNavigation(), saveSite.click()]));
		await frame().waitForLoadState('load');
		await kit.sync();
	},
});

// 5. Where editors translate: TYPO3's own language menu in the page module.
await kit.say("That's it. Editors translate with TYPO3's own Create new translation, and Supertext translates the content on the way.", {
	caption: 'Done: editors use <b>Create new translation</b>',
	during: async () => {
		await kit.click(page.locator('a[data-moduleroute-identifier="web_layout"]'));
		const menu = frame().getByRole('button', { name: /English/ }).first();
		await menu.waitFor();
		await kit.sync();
		await kit.click(menu);
		await kit.moveTo(frame().getByText('Create new translation').first());
	},
	after: 600,
});
await kit.caption('');

fs.mkdirSync(path.dirname(out), { recursive: true });
await kit.finish(out, { system: 'TYPO3', repo: 'Typo3-Supertext-Translation', kind: 'install' });
await browser.close();
fs.rmSync(workDir, { recursive: true, force: true });
console.log(`Saved ${out}`);
