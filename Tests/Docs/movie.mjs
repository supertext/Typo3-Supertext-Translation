#!/usr/bin/env node
/**
 * Records the demo movie (docs/images/demo.gif and an MP4) from a FRESH local demo whose
 * extension talks to stand-in.mjs, like screenshots.mjs. TYPO3 has no "translate" button: the
 * editor localizes the English page "FAQs" into Deutsch (Schweiz) with TYPO3's own Localize
 * wizard (mode Translate), the extension translates it with Supertext on the way, and the
 * editor checks the German page. See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://127.0.0.1:8090 DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run movie
 *
 * Voice: Piper (pip install piper-tts), voice "alba" downloaded once into movie/voice-cache/
 * (git-ignored); MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * Tests/Docs/movie/out/Typo3-Supertext-Translation.mp4, git-ignored; the MP4 is not committed).
 * CHROMIUM_PATH: another Chromium than Playwright's. PAGE_ID: the page (default 5, Camino's FAQs).
 * Needs ffmpeg.
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
const out = process.env.MOVIE_OUT ?? here('movie/out/Typo3-Supertext-Translation.mp4');
const gif = here('../../docs/images/demo.gif');
const need = (name) => process.env[name] ?? (() => { throw new Error(`Set ${name}`); })();

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' });

// Sign in as the demo editor (not recorded).
await page.goto(`${B}/typo3/`);
await page.fill('input[name=username]', need('DEMO_EDITOR_EMAIL'));
await page.fill('input[type=password]', need('DEMO_EDITOR_PASSWORD'));
await page.click('button[type=submit]');
await page.waitForURL(/\/typo3\/(main|module)/, { timeout: 30000 });

// The page module with the English FAQs page (loading not recorded).
await page.goto(`${B}/typo3/module/web/layout?id=${PAGE_ID}`);
const frame = () => page.frame('list_frame');
const languageMenu = () => frame().getByRole('button', { name: /English/ }).first();
await languageMenu().waitFor();
await page.waitForTimeout(800);

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'typo3-movie-'));
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') });
const wizardButton = (label) => page.locator('.wizard-actions button').filter({ hasText: label }).last();

// 1. The English page in the page module.
await kit.say("Here's a page in English, “FAQs”, in TYPO3's page module.", { caption: 'A page in English: “FAQs”' });

// 2. TYPO3's own translation: the language menu → Create new translation.
await kit.say('To translate it, open the language menu, and under Create new translation, choose Deutsch, Schweiz.', {
	caption: 'Language menu → <b>Deutsch (Schweiz)</b>',
	during: async () => {
		await kit.click(languageMenu());
		await kit.hold(600);
		await kit.click(frame().getByText('Deutsch (Schweiz)').first());
		await page.getByText(/Step \d of \d/).first().waitFor({ timeout: 30000 });
		await overlayToFront(page);
		await kit.sync();
	},
});

// 3. The Localize wizard: content, mode Translate, confirm.
await kit.say("TYPO3's Localize wizard opens. Keep all content elements selected, and click Next.", {
	caption: 'Keep all content elements, click <b>Next</b>',
	during: async () => {
		await kit.hold(900);
		await kit.click(wizardButton('Next'));
		await page.getByText('Select localization mode').waitFor();
	},
});
await kit.say('Choose Translate, then Next.', {
	caption: 'Mode: <b>Translate</b>, then <b>Next</b>',
	during: async () => {
		await kit.moveTo(page.getByText('Translate', { exact: true }).first());
		await kit.hold(500);
		await kit.click(wizardButton('Next'));
		await page.getByText('Please review the following settings').waitFor();
	},
});
const localize = wizardButton('Localize');
await kit.say('Check the summary, and click Localize. This is where Supertext comes in.', {
	caption: 'Click <b>Localize</b>: Supertext translates on the way',
	during: () => kit.moveTo(localize),
	after: 0,
});

// The stand-in answers at once: send the request only after the progress state has been seen.
let release;
const released = new Promise((resolve) => (release = resolve));
await page.route(/\/ajax\/wizard\/localization\/localize/, async (route) => {
	if (route.request().method() === 'POST') await released;
	await route.continue();
});
await page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y]);
const done = page.getByText(/Localization completed/).waitFor({ timeout: 300_000 });
await kit.say('Supertext translates the content.', {
	caption: 'Supertext translates the content…',
	during: async () => {
		await localize.click();
		await kit.hold(1300);
		release();
	},
});
kit.pause();
await done;
kit.resume();
await kit.sync();

// 4. The result: the German page.
await kit.say('A few seconds later, the localization is complete. Click Finish to open the German version.', {
	caption: 'Localization completed – click <b>Finish</b>',
	during: async () => {
		await kit.hold(700);
		await kit.click(wizardButton('Finish'));
		await frame().getByText(/Supertext translated/).waitFor({ timeout: 30000 });
		await kit.sync();
	},
});
await kit.say('The page and its content elements are now in German.', {
	caption: 'Page and content elements in German',
	during: async () => {
		await kit.hold(1200);
		await frame().evaluate(() => window.scrollBy({ top: 430, behavior: 'smooth' }));
	},
});
await kit.say('The new page is hidden. Review it, and publish it when it is ready.', { caption: 'The German version is ready – review it and publish', after: -700 }); // the screen runs on briefly after the last frame
await kit.caption('');

fs.mkdirSync(path.dirname(out), { recursive: true });
await kit.finish(out, { system: 'TYPO3', repo: 'Typo3-Supertext-Translation', gif });
await browser.close();
fs.rmSync(workDir, { recursive: true, force: true });
console.log(`Saved ${out} and ${gif} (${(fs.statSync(gif).size / 1e6).toFixed(2)} MB)`);
