#!/usr/bin/env node
/**
 * End-to-end test against the REAL Supertext API: a fresh demo container (Camino content,
 * nothing translated yet) with a real SUPERTEXT_API_KEY. The editor account translates every
 * page into every target language of the site through the Page module's Localize wizard,
 * then the database is checked: every page, content element and Camino list item has one
 * translated copy, the copies are hidden for review, really in the target language (de-CH
 * without "ß"), formatting survived, technical fields are untouched, and the wizard no longer
 * offers a second translation (the extension never overwrites an existing translation).
 * See "End-to-end tests" in docs/DEVELOPER.md.
 *
 *   TYPO3_URL=http://127.0.0.1:8081 E2E_CONTAINER=demo-live EDITOR_EMAIL=… EDITOR_PASSWORD=… \
 *   node Tests/E2E/live.mjs
 *
 * The database (SQLite inside the container) is read with PHP through `docker exec`.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const B = (process.env.TYPO3_URL ?? 'http://127.0.0.1:8081').replace(/\/$/, '');
const CONTAINER = process.env.E2E_CONTAINER ?? 'demo-live';
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`); })();
const artifacts = fileURLToPath(new URL('./artifacts/', import.meta.url));
mkdirSync(artifacts, { recursive: true });

const failures = [];
function check(name, ok, detail = '') {
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
	if (!ok) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
	return ok;
}
const note = (text) => console.log(`note ${text}`);

// Common words, to tell the target languages apart. Lower case, whole words.
const STOPWORDS = {
	en: ['the', 'and', 'is', 'are', 'with', 'for', 'you', 'your', 'our', 'of', 'to', 'we', 'who', 'what'],
	de: ['der', 'die', 'das', 'und', 'ist', 'sind', 'mit', 'für', 'sie', 'ihre', 'ihr', 'unser', 'unsere', 'wir', 'eine', 'ein', 'den', 'zu', 'von', 'was'],
	fr: ['le', 'les', 'et', 'est', 'sont', 'avec', 'pour', 'vous', 'votre', 'vos', 'notre', 'nos', 'nous', 'des', 'une', 'un', 'du', 'de', 'qui', 'que'],
	it: ['il', 'lo', 'gli', 'e', 'è', 'sono', 'con', 'per', 'lei', 'sua', 'suo', 'nostro', 'nostra', 'noi', 'una', 'un', 'del', 'della', 'di', 'che', 'chi'],
};
const words = (text) => text.toLowerCase().match(/[\p{L}]+/gu) ?? [];
const score = (text, lang) => words(text).filter((w) => STOPWORDS[lang].includes(w)).length;
const plain = (v) => String(v ?? '').replace(/<[^>]+>/g, ' ');
const count = (haystack, needle) => String(haystack ?? '').split(needle).length - 1;
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

// Text fields the extension translates (FieldCollector: TCA input/text fields), per table.
const FIELDS = {
	pages: ['title', 'nav_title', 'subtitle', 'description', 'abstract', 'seo_title', 'og_title', 'og_description', 'twitter_title', 'twitter_description'],
	tt_content: ['header', 'subheader', 'bodytext', 'tx_themecamino_link_label'],
	tx_themecamino_list_item: ['header', 'text', 'link_label'],
	sys_file_reference: ['title', 'alternative', 'description'],
};
// Technical fields that must come back exactly as in the source.
const TECHNICAL = {
	pages: ['doktype'],
	tt_content: ['CType', 'colPos', 'frame_class'],
	tx_themecamino_list_item: ['fieldname', 'link'],
	sys_file_reference: ['fieldname', 'tablenames', 'uid_local'],
};
const LEFTOVERS = ['data-st-id', 'data-st-ph', 'st-ph-', '[Translate to'];
const MARKUP = [['links', '<a '], ['page links', 't3://'], ['bold', '<strong'], ['bold', '<b>'], ['italics', '<em'], ['italics', '<i>'], ['lists', '<ul'], ['list items', '<li'], ['paragraphs', '<p']];

// --- Database (SQLite in the container, read as www-data so no file changes owner) -------
const PHP_QUERY = `<?php
$c = require '/var/www/typo3/config/system/settings.php';
$path = $c['DB']['Connections']['Default']['path'] ?? (glob('/var/www/typo3/var/sqlite/*.sqlite')[0] ?? '');
if ($path === '' || $path[0] !== '/') { $path = '/var/www/typo3/' . $path; }
$pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = json_decode(getenv('E2E_Q'), true);
$st = $pdo->prepare($q['sql']);
$st->execute($q['params']);
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));`;
const PHP_SITES = `<?php
require '/var/www/typo3/vendor/autoload.php';
$out = [];
foreach (glob('/var/www/typo3/config/sites/*/config.yaml') as $f) { $out[] = Symfony\\Component\\Yaml\\Yaml::parseFile($f); }
echo json_encode($out);`;
function php(code, env = {}) {
	const args = ['exec', '-i', '-u', 'www-data'];
	for (const [k, v] of Object.entries(env)) args.push('-e', `${k}=${v}`);
	return JSON.parse(execFileSync('docker', [...args, CONTAINER, 'php'], { input: code, encoding: 'utf8', maxBuffer: 64 << 20 }));
}
const q = (sql, params = []) => php(PHP_QUERY, { E2E_Q: JSON.stringify({ sql, params }) });
const inList = (ids) => (ids.length ? ids.map(() => '?').join(',') : 'NULL');

/** Source records (default language) of the site. */
async function sourceRecords() {
	const pages = q('SELECT * FROM pages WHERE sys_language_uid = 0 AND deleted = 0 ORDER BY uid');
	const pids = pages.map((p) => p.uid);
	const content = q(`SELECT * FROM tt_content WHERE sys_language_uid = 0 AND deleted = 0 AND pid IN (${inList(pids)}) ORDER BY uid`, pids);
	const items = q(`SELECT * FROM tx_themecamino_list_item WHERE sys_language_uid = 0 AND deleted = 0 AND pid IN (${inList(pids)}) ORDER BY uid`, pids);
	const refs = q(`SELECT * FROM sys_file_reference WHERE sys_language_uid = 0 AND deleted = 0 AND pid IN (${inList(pids)}) ORDER BY uid`, pids);
	return { pages, tt_content: content, tx_themecamino_list_item: items, sys_file_reference: refs };
}
/** Translations of a table in one language, grouped by their source uid. */
function translations(table, language) {
	const parent = table === 'pages' ? 'l10n_parent' : table === 'tt_content' ? 'l18n_parent' : 'l10n_parent';
	const rows = q(`SELECT * FROM ${table} WHERE sys_language_uid = ? AND deleted = 0`, [language]);
	const bySource = new Map();
	for (const r of rows) {
		const src = Number(r[parent]) || Number(r.l10n_source) || 0;
		if (!bySource.has(src)) bySource.set(src, []);
		bySource.get(src).push(r);
	}
	return bySource;
}

// --- Setup -------------------------------------------------------------------------------
const site = php(PHP_SITES)[0];
if (!site) throw new Error('No site configuration in the container: is this the demo?');
const languages = site.languages ?? [];
const sourceLanguage = languages.find((l) => Number(l.languageId) === 0);
const targets = languages.filter((l) => Number(l.languageId) > 0 && l.enabled !== false);
const titles = languages.map((l) => l.title);
const source = await sourceRecords();
console.log(`Source (${sourceLanguage?.locale}): ${source.pages.length} pages, ${source.tt_content.length} content elements, ${source.tx_themecamino_list_item.length} list items, ${source.sys_file_reference.length} file references; targets: ${targets.map((t) => `${t.title} (${t.locale})`).join(', ')}`);
check('demo has target languages', targets.length > 0);
check('fresh demo (nothing translated yet)', q('SELECT COUNT(*) AS n FROM pages WHERE sys_language_uid > 0 AND deleted = 0')[0].n === 0);

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1400, height: 1000 }, locale: 'en-US' });
page.setDefaultTimeout(30_000);
const frame = () => page.frame('list_frame');
const safe = (s) => String(s).replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').toLowerCase();

/** The "Create new translation" entry for a language in the Page module's language menu, or null. */
async function languageMenuItem(title) {
	const f = frame();
	await f.getByRole('button', { name: new RegExp(titles.map(esc).join('|')) }).first().click();
	await page.waitForTimeout(600);
	// Only entries below the menu's "Create new translation" heading count; above it are the
	// languages the page already has.
	const handle = await f.evaluateHandle((wanted) => {
		const own = (el, text) => el.textContent.trim() === text && ![...el.children].some((c) => c.textContent.trim() === text);
		const heading = [...document.querySelectorAll('*')].find((el) => own(el, 'Create new translation') && el.getClientRects().length);
		if (!heading) return null;
		const menu = heading.closest('.dropdown-menu, [role="menu"], ul') ?? heading.parentElement.parentElement;
		let below = false;
		for (const el of menu.querySelectorAll('*')) {
			if (el === heading) below = true;
			else if (below && own(el, wanted) && el.getClientRects().length) return el.closest('a, button') ?? el;
		}
		return null;
	}, title);
	return handle.asElement();
}

async function openLayout(pageUid) {
	await page.goto(`${B}/typo3/module/web/layout?id=${pageUid}`);
	await frame().getByRole('button', { name: new RegExp(titles.map(esc).join('|')) }).first().waitFor();
}

/** Messages in the module after a run: [success texts, warning/error texts]. */
async function moduleMessages() {
	const f = frame();
	await page.waitForTimeout(1500);
	const ok = await f.getByText(/Supertext translated/).allTextContents();
	const bad = await f.locator('.callout-warning, .callout-danger, .callout-error, .alert-warning, .alert-danger').allTextContents();
	const toasts = await page.locator('typo3-notification-message').allTextContents().catch(() => []);
	return [ok, [...bad, ...toasts.filter((t) => /error|fail|supertext/i.test(t))].map((t) => t.replace(/\s+/g, ' ').trim())];
}

/** Translates one page into one language with the Localize wizard. */
async function translatePage(p, lang) {
	const tag = `page ${p.uid} "${p.title}" → ${lang.locale}`;
	await openLayout(p.uid);
	const item = await languageMenuItem(lang.title);
	if (!item) {
		await page.screenshot({ path: `${artifacts}not-offered-${p.uid}-${safe(lang.locale)}.png` });
		return check(`${tag}: Page module offers "Create new translation"`, false);
	}
	const started = Date.now();
	try {
		await item.click();
		await page.getByText(/Step \d of \d/).first().waitFor();
		const button = (label) => page.locator('.wizard-actions button').filter({ hasText: label }).last();
		const next = (label = 'Next') => button(label).click();
		// The steps depend on the page: content selection (all selected by default), source
		// language (when the page has several), mode (Translate is the default), handler,
		// confirmation. Accept the defaults until the confirmation's "Localize".
		for (let step = 0; ; step++) {
			await page.waitForTimeout(1000);
			if (await button('Localize').isVisible()) break;
			if (step > 10) throw new Error('no "Localize" button after 10 steps');
			await next();
		}
		await next('Localize'); // Supertext translates in this step
		await page.getByText(/Localization completed/).waitFor({ timeout: 900_000 });
		await next('Finish');
	} catch (e) {
		await page.screenshot({ path: `${artifacts}wizard-${p.uid}-${safe(lang.locale)}.png` });
		return check(`${tag}: Localize wizard`, false, e.message.split('\n')[0]);
	}
	const [ok, bad] = await moduleMessages();
	await page.screenshot({ path: `${artifacts}result-${p.uid}-${safe(lang.locale)}.png` });
	console.log(`${tag}: ${Math.round((Date.now() - started) / 1000)} s${ok.length ? ` — ${ok[0].replace(/\s+/g, ' ').trim()}` : ''}`);
	check(`${tag}: no error or warning shown`, bad.length === 0, bad.join(' | '));
	return check(`${tag}: Supertext confirms the translation`, ok.length > 0);
}

// --- Log in as the editor ----------------------------------------------------------------
await page.goto(`${B}/typo3/`);
await page.fill('input[name=username]', need('EDITOR_EMAIL'));
await page.fill('input[type=password]', need('EDITOR_PASSWORD'));
await page.click('button[type=submit]');
const loggedIn = await page.waitForURL(/\/typo3\/(main|module)/, { timeout: 30_000 }).then(() => true, () => false);
if (!check('editor can log in (DEMO_EDITOR_*)', loggedIn)) {
	await page.screenshot({ path: `${artifacts}login.png` });
	throw new Error(failures.join('\n'));
}

// --- Translate every page into every language ---------------------------------------------
for (const lang of targets) {
	for (const p of source.pages) await translatePage(p, lang);
}

// --- Check the database --------------------------------------------------------------------
for (const lang of targets) {
	const id = Number(lang.languageId);
	const code = String(lang.locale).split(/[-_.]/)[0].toLowerCase();
	const tag = `${lang.title} (${lang.locale})`;
	let fields = 0;
	let changed = 0;
	let text = '';
	for (const table of Object.keys(FIELDS)) {
		const found = translations(table, id);
		const copies = source[table].map((s) => found.get(s.uid) ?? []);
		const missing = source[table].filter((s, i) => copies[i].length === 0).map((s) => s.uid);
		const dupes = source[table].filter((s, i) => copies[i].length > 1).map((s) => s.uid);
		if (source[table].length) {
			check(`${tag}: every ${table} record translated`, missing.length === 0, `${source[table].length - missing.length}/${source[table].length}${missing.length ? `, missing uid ${missing.join(',')}` : ''}`);
			check(`${tag}: one ${table} translation per record (no duplicates)`, dupes.length === 0, dupes.join(','));
		}
		if (table === 'pages' || table === 'tt_content') {
			const visible = copies.flat().filter((r) => Number(r.hidden) === 0).map((r) => r.uid);
			check(`${tag}: new ${table} translations are hidden for review`, visible.length === 0, visible.length ? `visible uid ${visible.join(',')}` : '');
		}
		source[table].forEach((s, i) => {
			const t = copies[i][0];
			if (!t) return;
			const what = `${table}#${s.uid}${s.CType ? ` (${s.CType})` : ''}`;
			for (const f of TECHNICAL[table]) {
				if (f in s && String(s[f] ?? '') !== String(t[f] ?? '')) check(`${tag}: ${what}.${f} unchanged`, false, `"${s[f]}" → "${t[f]}"`);
			}
			for (const f of FIELDS[table]) {
				if (!(f in s) || !/\p{L}/u.test(plain(s[f]))) continue;
				if (table === 'tt_content' && f === 'bodytext' && s.CType === 'html') continue;
				fields++;
				const v = String(t[f] ?? '');
				if (!check(`${tag}: ${what}.${f} filled`, plain(v).trim() !== '')) continue;
				if (v !== String(s[f])) changed++;
				else note(`${tag}: ${what}.${f} unchanged ("${plain(v).trim().slice(0, 60)}")`);
				text += ` ${plain(v)}`;
				const left = LEFTOVERS.filter((l) => v.includes(l));
				check(`${tag}: ${what}.${f} has no protocol leftovers`, left.length === 0, left.join(', '));
				for (const [name, needle] of MARKUP) {
					if (count(s[f], needle)) check(`${tag}: ${what}.${f} keeps its ${name} (${needle})`, count(v, needle) === count(s[f], needle), `${count(v, needle)}/${count(s[f], needle)}`);
				}
			}
		});
		if (table === 'pages') {
			for (const t of copies.flat()) {
				const s = source.pages.find((x) => x.uid === Number(t.l10n_parent));
				if (s?.slug && t.slug === s.slug && t.title !== s.title) note(`${tag}: page ${s.uid} slug not rebuilt from the title (${t.slug})`);
			}
		}
	}
	check(`${tag}: texts were changed by the translation`, fields > 0 && changed / fields >= 0.8, `${changed}/${fields} fields`);
	if (STOPWORDS[code]) {
		const scores = Object.fromEntries(Object.keys(STOPWORDS).map((l) => [l, score(text, l)]));
		const best = Object.entries(scores).sort((a, b) => b[1] - a[1])[0][0];
		check(`${tag}: text is in the target language`, best === code, JSON.stringify(scores));
	}
	if (/^de[-_]ch/i.test(lang.locale)) check(`${tag}: Swiss German spelling (no "ß")`, !text.includes('ß'));
}

// --- No second translation ------------------------------------------------------------------
// The extension never overwrites an existing translation (no retranslate action, see the
// user guide): the Page module must not offer a new translation of a translated page.
note('the extension has no retranslate action: checking that a translated page is not offered again instead');
const sample = source.pages.find((p) => Number(p.doktype) === 1) ?? source.pages[0];
for (const lang of targets) {
	await openLayout(sample.uid);
	const item = await languageMenuItem(lang.title);
	check(`page ${sample.uid} → ${lang.locale}: no second "Create new translation"`, item === null);
	await page.keyboard.press('Escape');
}

await browser.close();

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:\n- ${failures.join('\n- ')}`);
	process.exit(1);
}
console.log('\nAll end-to-end checks passed.');
