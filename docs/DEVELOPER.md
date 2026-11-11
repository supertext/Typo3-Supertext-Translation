# Developer guide — Supertext Translation for TYPO3

How the extension is built, how to work on it, and how it is released and deployed.

## Architecture

```
Editor localizes (Page module / List module / CLI)
        │
        ▼
TYPO3 DataHandler  ── localize | copyToLanguage ──►  new records (copies)
        │
        │ processCmdmap_postProcess   collect source→target uids per command
        │ processCmdmap_afterFinish   once per DataHandler run
        ▼
Hooks\DataHandlerHook ──► Service\TranslationService
                              │  FieldCollector    which fields to translate (TCA)
                              │  LanguageResolver  site language → Supertext code/politeness
                              ▼
                          Supertext SDK (lib/supertext-sdk)  Client::translateTexts(): pack all fields into ONE HTML
                              │          document, POST file → poll → GET translation → DELETE
                              │          (HTTP through Api\Typo3HttpTransport)
                              │
                              ▼
                          DataHandler datamap  write translations back (+ slugs)
```

| Class | Responsibility |
| --- | --- |
| `Hooks\DataHandlerHook` | Collects every record localized in a DataHandler run (from `copyMappingArray`, so inline children like `sys_file_reference` are included), triggers translation in `afterFinish`, shows flash messages. |
| `Service\TranslationService` | Groups segments per language pair, sends each pair's fields as keyed texts to the SDK's `translateTexts()` (which splits only above ~900k characters), writes results through a second DataHandler run (guarded by `TranslationService::$running` to avoid recursion), regenerates page slugs, keeps skipped code fields verbatim. |
| `Service\FieldCollector` | TCA-driven field selection: `input`/`text`, not `l10n_mode=exclude`, not read-only, no numeric/date/email evals, no code editors, no technical reference fields (`sys_file_reference.tablenames`/`fieldname`/`table_local`, link `target`); detects rich text. |
| `Service\LanguageResolver` | Maps a site language to a Supertext target (`supertext_code`, else an exact Supertext code, else the SDK's default; see *Language codes*), source (default language's bare language), politeness and *Translation review* (`supertext_review`, `reviewOf()`). Throws the SDK's `unsupported_language` for languages with neither. `migratedCode()` is the upgrade rule for old values. |
| `Api\SupertextClientFactory` | Creates the SDK's `Supertext\Typo3Translation\Sdk\V1\Client` from the extension configuration (key, endpoint, poll interval and timeout, User-Agent `TYPO3-Supertext-Translation/<version>` with the version from `ext_emconf.php`); `create(true)` is the client for languages with *Translation review* (base URL `reviewUrl`, default `SupertextClientFactory::REVIEW_URL`). |
| `Reporting\Reporting` | Commando Center glue without TYPO3 classes: builds Plugin Health's `InstallationReporter` with the SDK's key check (`Client::checkApiKey()`, `Protocol::apiKeyFingerprint()`, `Supertext::SDK_VERSION`), picks the action (`afterSettingsSaved` when the configuration fingerprint changed, else `onRequest`; `authenticationFailed` after a 401/403), loads and stores the reporter state, answers the ping. Never throws. |
| `Reporting\PluginHealthTransport` | Plugin Health's `HttpTransport` over the SDK's (`Api\Typo3HttpTransport`), so reports use TYPO3's HTTP client too. No TYPO3 classes; excluded from DI like `Reporting`. |
| `Reporting\Typo3Reporting` | Feeds `Reporting` with the site URL, TYPO3 version, ping URL and the registry (`Reporting\RegistryStateStorage`); `later()` runs the reporter at the end of the request (after `fastcgi_finish_request()` where available). |
| `Middleware\PingMiddleware` | Public `GET <root>/supertext/ping` in the frontend stack, before the site resolver (no login, session or page cache). |
| `Configuration\StatusField` | The *Status* block at the top of the extension configuration (`type=user` in `ext_conf_template.txt`): key check, version reports, language mapping per site. TYPO3 renders that form through the install tool in its failsafe mode (`?__typo3_install`), whose container has only core services: not this extension's nor `SiteFinder`. So `StatusField::services()` takes the services from the container when it has them and otherwise builds them from core services the failsafe container offers (`RequestFactory`, `Registry`, `LogManager`). |
| `Site\SiteList` | All sites: TYPO3's `SiteFinder` where it is available, otherwise (failsafe mode) the sites read from `config/sites/*/config.yaml`. Used by `Typo3Reporting` (site URL) and the Status block. |
| `Configuration/SiteConfiguration/Overrides/site_language.php` | The *Supertext* palette in the site language form: `supertext_code` (`selectSingle`, items from `Configuration\SiteLanguageItems->targetItems`, `onChange: reload`) and `supertext_politeness` (display condition `SiteLanguageItems->offersPoliteness`), and `supertext_review` (`check`/`checkboxToggle`, target languages only). |
| `Configuration\SiteLanguageItems` | `itemsProcFunc` of the select: the empty option labelled *Default (&lt;`Languages::suggestTarget()`&gt;)* or *— choose —*, then `Languages::targetOptions()` as "Name (code)" with the name from PHP intl's `Locale::getDisplayName()` in the backend user's language (sorted with `Collator`; English and Swiss German names from the SDK), plus a stored code that isn't in the list. `offersPoliteness()`: the tone field only where the effective target supports it (or none is known yet). |
| `EventListener\ValidateSupertextCodes` | `SiteConfigurationBeforeWriteEvent`: a `supertext_code` must be a list code (respelled via `Languages::matchTarget()`) or the value already stored (custom YAML code); anything else is dropped. A tone other than `more`/`less`/empty is dropped. |
| `Upgrades\SupertextCodeUpgradeWizard` | Upgrade wizard `supertextTranslation_languageCodes` (Install namespace: TYPO3 13, and its alias in 14): rewrites 0.1 free-text `supertext_code` values with `LanguageResolver::migratedCode()` through `SiteWriter`. Only listed when something changes. |
| `Api\Typo3HttpTransport` | The SDK's `HttpTransport` over TYPO3's `RequestFactory`, so `$GLOBALS['TYPO3_CONF_VARS']['HTTP']` (proxy, TLS, timeouts) applies and ext-curl isn't required. |
| `Command\LocalizeCommand` | `supertext:localize <page> <language> [--recursive]`. |
| `Controller\TranslationFilesController` | Backend module *Supertext* (`Configuration/Backend/Modules.php`: `supertext_translation_files`, parent `content` on TYPO3 14 and `web` on 13, page tree; template `Resources/Private/Templates/TranslationFiles/Index.html`, icon in `Configuration/Icons.php`). Export of the selected page (only site languages from `Site::getAvailableLanguages()` for the user) as a download; import of an uploaded file, read in memory, never stored, with the result per job. See *Translation files*. |
| `Command\ExportCommand`, `Command\ImportCommand` | `supertext:export <page> <language…> [--format=xliff\|html] [--output=<file>]` and `supertext:import <file> [--include-changed]`, see *Translation files*. |
| `Files\TranslationFileGlue` | Export/import with the SDK's `TranslationFiles`, without TYPO3 classes (tested by `Tests/TranslationFilesTest.php`): builds the jobs (reference, languages via `LanguageResolver`), checks imported jobs, counts the results and hands the texts to the store. |
| `Files\Typo3TranslationFileStore` | The TYPO3 side (`Files\TranslationFileStore`): collects a page's segments with `FieldCollector`, finds the site language, localizes missing records and writes through `TranslationService::writeBack()`. |
| `Localization\Labels` | Messages in the backend user's language from `Resources/Private/Language/locallang.xlf`; translates the SDK's `SupertextException`s by their `errorCode` (`Labels::ERROR_LABELS`). |
| `Configuration\ExtensionSettings` | Typed extension configuration plus `SUPERTEXT_API_KEY` / `SUPERTEXT_API_ENDPOINT` / `SUPERTEXT_REVIEW_URL` / `SUPERTEXT_VERSION_REPORTS` env overrides, and the configuration fingerprint. |

## Supertext API protocol

The extension talks to Supertext through the official **Supertext SDK for PHP** ([Supertext-API-SDK-Guide](https://github.com/Supertext/Supertext-API-SDK-Guide/tree/main/sdk/php), MIT). The SDK implements the protocol below, the HTML packing (one `data-st-id` element per field), the language code rules, the 429 retries and the API key check (`Client::checkApiKey()`; the version reports are Supertext Plugin Health's, see below); it is tested against the shared test vectors of all Supertext SDKs. The extension only collects fields, maps languages and writes the results back.

**Bundled copy:** the extension ships the SDK (0.5.0) in `lib/supertext-sdk/` (sources, `LICENSE`, `COMMIT` with the SDK commit, `VERSION`, a short README), with the namespace changed from `Supertext\Sdk` to `Supertext\Typo3Translation\Sdk` and autoloaded by the extension's `composer.json`. Everything that talks to the API is in the versioned namespace `…\Sdk\V1` (`src/V1/`); translation files (`…\Sdk\Files`, see *Translation files*), the HTTP transport (`…\Sdk\Http`) and `…\Sdk\Supertext::SDK_VERSION` are version-neutral. Sites install only the extension; another package that requires `supertext/supertext-sdk` itself can't clash with it. This stays so after the SDK is on Packagist.

- Update it with `scripts/vendor-sdk.sh [commit-or-branch]` (default `main`; `SDK_DIR=<clone>` uses a local clone), run the tests, and commit with a CHANGELOG entry.
- Never edit the copy: fix the SDK in its own repository, then re-run the script. PHPStan analyses `Classes/` only.

**Translation review** (`supertext_review`, per target site language): `TranslationService` groups segments per language pair *and* review flag and takes `SupertextClientFactory::create($language['review'])` for each group, a second SDK client with the same key, timeouts, transport and User-Agent whose base URL is `reviewUrl` (default `SupertextClientFactory::REVIEW_URL`, the translation proxy of [Supertext Localization Review](https://github.com/Supertext/Localization-Review-Supertext-Translation); `SUPERTEXT_REVIEW_URL` wins when not empty). `translateTexts()` runs upload, status, download and delete with that one client, so a job is always collected by the client that submitted it. The proxy speaks the same API v1, forwards to the live Supertext API with the extension's key, stores every segment per key and serves reviewers' corrections on the next upload, so nothing else changes in the extension. `reviewUrl = ''` (or the same URL as the API) means every language goes directly. The API key check, version reports and translation files never use the review service. The default language never has review (`LanguageResolver::reviewOf()` only for `languageId > 0`). `Tests/SdkTest.php` (section 6) checks that all four calls go to the review URL for a review language and to the API otherwise, the default, `''`, the env override and the flag of a site language.

## Supertext Plugin Health

[Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) (MIT, no dependencies) is the plugin side of the Supertext Commando Center: version reports, the API key check result they carry and the ping answer. It lives in the Commando Center's repository (`plugin-health/`) and doesn't need the SDK: `Reporting\Reporting` passes it the SDK's key check (`CallableKeyChecker` over `Client::checkApiKey()` and `Protocol::apiKeyFingerprint()`) and `Supertext::SDK_VERSION`. Its `ReporterState` is the JSON the SDK 0.1–0.2 reporter stored, so sites keep their registry state (no new `installed` report).

**Bundled copy:** `lib/supertext-plugin-health/` (sources, `LICENSE`, `COMMIT`, `VERSION`, a short README), namespace changed from `Supertext\PluginHealth` to `Supertext\Typo3Translation\PluginHealth`, autoloaded by the extension's `composer.json` like the SDK; PHPStan analyses `Classes/` only.

- Update it with `scripts/vendor-plugin-health.sh [commit-or-branch]`, e.g. `scripts/vendor-plugin-health.sh plugin-health-v0.1.0` (default `main`; `PLUGIN_HEALTH_DIR=<clone>` uses a local clone of Commando-Center-Supertext-Translation), run the tests, and commit with a CHANGELOG entry.
- Never edit the copy: fix it in Commando-Center-Supertext-Translation → `plugin-health/`, then re-run the script.

The protocol, shared with the WordPress plugin and every other Supertext CMS plugin:

1. `POST {base}translate/ai/file` — multipart: `file` (part `Content-Type` exactly `text/html`, no charset, or the API answers 415), `target_lang` (a Supertext target code, e.g. `de-CH`, `es`), optional `source_lang` (bare language, e.g. `de`; see *Language codes*), optional `politeness` (`more`/`less`) → `{file_id}`
2. `GET …/{file_id}/status` until `done` (`error`, `limit_exceeded`, `deleted` are terminal)
3. `GET …/{file_id}/translation` → translated HTML
4. `DELETE …/{file_id}` (files also expire after 24 h)

Auth header: `Authorization: Supertext-Auth-Key <key>`. The key may be configured with or without the `Supertext-Auth-Key ` prefix; the SDK strips it and always sends exactly one. The header name must be `Authorization` (the live API answers 403 to `Authentication`).

**Rate limit:** the API limits requests per second per key (HTTP 429, `RATE_LIMIT_EXCEEDED`); translating into several languages at once hits it. The SDK retries a 429 up to 4 times, waiting for `Retry-After` if sent, otherwise 1, 2, 4 and 8 seconds (plus jitter), before reporting *Too many requests*. Base URLs: `https://api.supertext.com/v1/` (live), `api.staging…`, `api.testing…`.

### Language codes

Supertext accepts exactly the codes of its `GET features` (the SDK's `Languages::TARGETS` / `SOURCES`, listed in the [installation guide](INSTALLATION.md#supertext-languages)): targets are regional only for German, English, French, Italian and Portuguese (`de-CH`, `en-US`, `pt-BR`), a script for Chinese and Serbian (`zh-Hans`, `sr-Latn`), a dialect for Swiss German (`gsw-u-sd-chzh`), and **bare for all others** (`es`, `nl`, `sl`); sources are bare (`en`). Anything else is rejected (`INVALID_LANGUAGE_PAIR`, HTTP 404 for unknown pairs). **Defaults only where obvious** (SDK 0.4.0, `Languages::DEFAULT_TARGETS`): bare `de`/`en`/`fr`/`it`/`pt` → `de-DE`/`en-US`/`fr-FR`/`it-IT`/`pt-PT`, `zh` → `zh-Hans`, `sr` → `sr-Latn`, `zh-TW`/`HK`/`MO` → `zh-Hant`, `zh-CN`/`SG` → `zh-Hans`, a region of a bare target → the bare target (`es_ES` → `es`). Nothing else is guessed (`en_IE`, `de_LI`, `fr_BE`, `gsw`, `sr_RS`, `no`).

`Service\LanguageResolver` resolves every site language with the SDK's `Languages` (the same rules in every Supertext plugin):

- Target: `Languages::target($locale, [$locale => $supertextCode])`: the site language's `supertext_code` (the select) wins and is sent as it is; else the locale without encoding (`de_CH.UTF-8` → `de_CH`) if it equals a Supertext code apart from case and `_`/`-` (`de_CH` → `de-CH`, `es` → `es`); else the SDK's default (`Languages::defaultTarget()`: `de` → `de-DE`, `nl_NL` → `nl`); else the SDK's `unsupported_language` with the locale as `targetLang`. Nothing is sent then; the editor sees *The language … has no Supertext language yet* (`error.unsupportedLanguage`), one message per language.
- Source: `Languages::source($locale, [$locale => $supertextCode])` (the SDK sends a mapped code in its source form, so one `supertext_code` serves both directions): `en_US` → `en`, a `supertext_code` of `de-CH` → `de`, of `en-US` → `en`.
- Politeness: `supertext_politeness` (`more`/`less`) is sent as configured. `Languages::supportsPoliteness()` is false for e.g. `en-US`, `rm`, `sq`, `sr-*`, `gsw-*`: the Status block warns, and Supertext answers `unsupported_feature` (`error.unsupportedFeature`).
- Translating within one language (source `en`, target `en-GB`) is allowed.

The mapping lives in the site configuration (`config/sites/<site>/config.yaml`, per language), editable in the backend through the *Supertext* palette of the site language form (`Configuration/SiteConfiguration/Overrides/site_language.php`, a select per site language; empty = *Default (de-DE)* from `Languages::suggestTarget()`, or *— choose —* where there is no default). There is no separate env/config override: the YAML is the configuration file and the form edits it. Old free-text values are migrated by `Upgrades\SupertextCodeUpgradeWizard`; the Status block marks only languages without mapping, exact match and default. The docs stand-in (`Tests/Docs/stand-in.mjs`) rejects regional sources like the real API.

## Translation files (XLIFF and HTML)

*Export for translation* and *Import translation* with the SDK's `TranslationFiles` (SDK 0.5.0, `Supertext\Typo3Translation\Sdk\Files`; integration guide → *Translation files: XLIFF and HTML*). Nothing here calls the API.

**Where:** the backend module *Content → Supertext* (`Controller\TranslationFilesController`) for editors, and the CLI commands next to `supertext:localize`; both use `Files\TranslationFileGlue` unchanged. API translation hooks into TYPO3's own *Localize* step, which has no place for a file download or upload, hence the module. Module details:

- **Route and CSRF:** the forms post to the module URL from `UriBuilder::buildUriFromRoute()`, which carries TYPO3's route token (checked by the backend `RouteDispatcher` for every non-public route), so no separate form token is needed.
- **Export:** page from the page tree (`id`), checked with `BackendUtility::readPageAccess()` and default language only; languages limited to the site's languages the user may edit; validation (*Choose at least one language.*, *An HTML file holds exactly one language…*) before the glue runs. The response is the file as an attachment (`Content-Disposition`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`).
- **Import:** the PSR-7 upload; `UPLOAD_ERR_INI_SIZE`/`FORM_SIZE`, a size over 10 MB, and a POST whose body PHP dropped (over `post_max_size`: no fields, no files, `CONTENT_LENGTH` > 0) all show *too large* without reading the file. Results per job: `Labels::importSummary()`; red when the job failed or DataHandler reported errors, green when something was imported, blue otherwise.
- **Permissions:** `Typo3TranslationFileStore::item()` returns *not found* for pages a non-admin user may not see, and `language()` *no language* for languages the user may not edit (`checkLanguageAccess()`); the CLI user is an admin. Writes go through DataHandler as the user.

**Item and segments:** an item is a page in the default language with its content elements (`pid` = the page, `sys_language_uid` = 0, not deleted, hidden ones too) and the file references of both, the records localizing the page in the Page module sends to Supertext. Fields come from `FieldCollector` (the same as API translation); segment keys are `<table>:<uid>:<field>` of the default-language records (`pages:12:title`, `tt_content:7:bodytext`, `sys_file_reference:3:alternative`).

**Job metadata:** `plugin` = `Typo3-Supertext-Translation`; `reference` = `page:<page uid>:<site language id>` (e.g. `page:12:1`; `TranslationFileGlue::reference()` / `parseReference()`, anything else is *not found*); `sourceLanguage` / `targetLanguage` = `LanguageResolver::sourceCode()` / `targetCode()` of the site's default language and the target language (mapping, exact match, default), else the locale with `-` for `_` (`en_IE` → `en-IE`), so unmapped languages don't block an export. File names `typo3-<page>-<target>.xlf` / `.html`, `typo3-<page>.xlf` for several languages.

**Import:** `importFile()` refuses files over 10 MB (`TranslationFileGlue::MAX_UPLOAD_BYTES`) before reading; `TranslationFiles::read()`; per job: `plugin` must be ours (*other system*), the reference must parse and the page exist (*not found*), the site must have the language (*no language*); the segments are collected again and `importTranslations()` compares them. `ok` results are written (`source_changed` only with `--include-changed`); `untranslated`, `invalid_markup` and `missing` are only counted. `Typo3TranslationFileStore::write()` finds each record's translation (`transOrigPointerField` + `languageField`), localizes missing ones with the DataHandler `localize` command (page first, then content elements, then file references; `TranslationService::$running` keeps our hook from translating them with the API; new translations start hidden), then writes one datamap through `TranslationService::writeBack()` (slugs from the translated title, DataHandler as the backend user). Labels: `file.*` in `locallang.xlf`; `Labels::forFileError()` maps the SDK's `TranslationFileError` codes and `Files\TranslationFileGlueError`, `Labels::importSummary()` builds the summary.

## Version reports, API key check and ping (Commando Center)

Protocol: Commando-Center-Supertext-Translation → `docs/DEVELOPER.md` → *Report protocol*; implemented by Supertext Plugin Health's `InstallationReporter` (`reportsEnabled()`, `pingResponse()`, `ReporterState`) with the SDK's `checkApiKey()` as key check (see *Supertext Plugin Health*). The extension only decides when to call it and where to keep the state.

| When | Reporter call | Where |
| --- | --- | --- |
| The extension configuration was saved (its fingerprint differs from the stored one; TYPO3 has no "saved" event) | `afterSettingsSaved` (`installed` the first time, else `settings_saved`) | The *Status* field when the configuration is opened again (synchronously, so the result shows), or the next translation / `supertext:localize` (after the response) |
| Back-end requests that run the extension: opening its configuration, every translation (DataHandler hook), `supertext:localize` | `onRequest` (checks a changed key, reports `upgraded` or `api_key_check` when due) | `Typo3Reporting::later()`: a shutdown function, after `fastcgi_finish_request()` on PHP-FPM; never on front-end page views |
| A translation got 401/403 | `authenticationFailed` | `TranslationService` |

There is no *Test connection* button and no check command, so `testConnection` and `reportCheck` aren't used.

- **Options:** `plugin.name` = `Typo3-Supertext-Translation` (the GitHub repository), `plugin.version` from `ext_emconf.php` (`Configuration\ExtensionVersion`), `system` = `TYPO3` and `Typo3Version`, runtime PHP (Plugin Health's default), `sdk_version` = `Supertext::SDK_VERSION`, `siteUrl` = the base of the first site with an absolute base, else the request's installation URL (`normalizedParams->getSiteUrl()`); without either (CLI with relative site bases) nothing is reported but the key is still checked. `callbackUrl` = `<installation URL>supertext/ping` (the request's installation URL when it is on the site's host, else the site URL's origin).
- **Setting:** `versionReports` in `ext_conf_template.txt`, combined with `SUPERTEXT_VERSION_REPORTS` by `reportsEnabled()`. `SUPERTEXT_REPORT_URL` overrides the center's URL (tests, screenshots).
- **State:** TYPO3's registry (`sys_registry`, namespace `supertext_translation`): `reporter` (Plugin Health's `ReporterState` as JSON, the same as the SDK 0.1–0.2 reporter stored: last reported versions and key status, last failure, latest key check with the key fingerprint) and `settingsFingerprint` (SHA-256 of the extension configuration). Never the key. Storage errors are caught: reporting never breaks a save or a translation.
- **Ping:** `Middleware\PingMiddleware` (`Configuration/RequestMiddlewares.php`, frontend stack after `normalized-params-attribute`, before `eid` and `site`) answers `GET <sitePath>supertext/ping` with Plugin Health's `pingResponse()` status, headers and body (404 while reports are off). Other methods and paths pass through.

## Local development

```bash
composer create-project typo3/cms-base-distribution:^14.3 t3
cd t3
vendor/bin/typo3 setup --driver=sqlite --create-site=http://localhost:8080/ ...
git clone https://github.com/Supertext/Typo3-Supertext-Translation packages/supertext_translation
composer require supertext/typo3-translation:@dev
vendor/bin/typo3 extension:setup -e supertext_translation
```

Add a second language with a locale to `config/sites/*/config.yaml`, create some content, then:

```bash
SUPERTEXT_API_KEY=... vendor/bin/typo3 supertext:localize 1 1 --recursive
```

To test without a real key, point `SUPERTEXT_API_ENDPOINT` at `Tests/Docs/stand-in.mjs` or the SDK repository's `stand-in/stand-in.mjs` (`http://127.0.0.1:8765/v1/`); both prefix untranslated text with `[<lang>] `.

## Tests

```bash
composer install --no-dev        # TYPO3 core (the SDK and Plugin Health are bundled in lib/)
php Tests/SdkTest.php            # every SDK error code has a label; TYPO3 locales → Supertext codes (mapping, exact, default); the site language select, the upgrade migration and the save check; translateTexts round trip with a fake transport; Translation review (review service for all four calls, others direct, default, '' off, env, User-Agent)
php Tests/ReportingTest.php      # Commando Center glue: which reporter action runs when, stored state, report contents, center failures, a state stored by the SDK 0.2 reporter, setting + env, ping answers and middleware
php Tests/SiteListTest.php       # the sites from config.yaml, as the Status block reads them in the install tool's failsafe mode
php Tests/TranslationFilesTest.php  # export/import glue with a fake store: reference round trip, collector keys and mapping in the jobs, other plugin / unknown reference / missing language, ok written, source_changed only with the option, untranslated / invalid / missing not written, files over 10 MB refused
php Tests/LanguageFilesTest.php  # de/fr/it XLIFF files match locallang.xlf (keys, placeholders, URLs); every label the code and configuration use exists
```

The tests use fake transports: they never call the live Supertext API or the Commando Center.

CI (`.github/workflows/ci.yml`) lints all PHP files on 8.2, 8.3, 8.4 and 8.5, installs the dependencies, runs the four tests and syntax-checks the demo entrypoint on every push and pull request, and runs PHPStan (see *Code quality and security checks*).

## End-to-end tests

The tests above use fakes. `.github/workflows/e2e.yml` also tests against the **real Supertext API**, so changes on Supertext's side (language codes, regional variants, formatting, rate limits) show up. It runs on every push to `main` that touches the extension, the demo or the tests, on pull requests that change the E2E test or workflow, every Monday (05:57 UTC), and by hand (*Actions → E2E → Run workflow*). It builds the demo image once (Docker layer cache in GitHub Actions) and starts it twice, each a fresh install (Camino content, SQLite inside the container) with demo accounts made up for the run (`DEMO_ADMIN_*`, `DEMO_EDITOR_*`). Both containers get `Tests/E2E/additional-ci.php` instead of `demo/additional.php`: the demo's reverse-proxy settings (Railway's TLS proxy) would make TYPO3 treat plain `http://127.0.0.1` as HTTPS.

1. **Real API** (repository or organization secret `SUPERTEXT_API_KEY`; without it these steps are skipped with a warning). Version reports are off (`SUPERTEXT_VERSION_REPORTS=0`), so CI runs don't appear in the Commando Center.
   - `Tests/E2E/features.mjs` asks the live `GET features` (cost-free, no extension or SDK code) whether every pair the demo translates (`en` → `de-CH`, `fr-CH`, as the extension sends them) is offered; a missing pair fails the run. Whether the extension's own language list matches the API is the SDK's job (its own tests).
   - `Tests/E2E/live.mjs` (Playwright): the editor account translates every page of the site into every target language through the Page module's language menu and the **Localize** wizard (Translate mode), exactly as the user guide describes; no Supertext warning may appear and the green *Supertext translated …* message must. Then the database is read through `docker exec` (PHP, as `www-data`) and checked: every page, content element and Camino list item has exactly one translation per language; new pages and content elements are hidden for review; every text field is filled and at least 80 % of them changed (single words may stay the same); technical fields (`CType`, `colPos`, list items' `fieldname` and `link`, file references) are unchanged; no `data-st-id`/placeholder or *[Translate to …]* prefix is left; bold, italics, lists, paragraphs and links are kept (counted per field); the text is in the target language (common words of each language counted) and de-CH has no "ß". The extension has no retranslate action (it never overwrites an existing translation), so instead of translating twice it checks that the Page module no longer offers a new translation of a translated page. Each run translates the Camino site once into two languages.
2. **Stand-in API**: the docs screenshots (see *Docs screenshots*), so the images don't change with each machine translation. If `docs/images/` changed, a second job pushes the branch `automation/docs-screenshots` and opens (or updates) the pull request "Docs: regenerate screenshots" (only for runs on `main`). This needs *Settings → Actions → General → Allow GitHub Actions to create and approve pull requests*.

If a run fails, the artifact `e2e-debug` has the result pages (`artifacts/`), the container logs, TYPO3's logs and the stand-in log. Locally:

```bash
cd Tests/E2E && npm install && npx playwright install chromium
# a fresh demo container named demo-live on port 8081 with a real SUPERTEXT_API_KEY,
# DEMO_EDITOR_* set and Tests/E2E/additional-ci.php mounted as config/system/additional.php
SUPERTEXT_API_KEY=… E2E_SOURCE=en E2E_TARGETS=de-CH,fr-CH node features.mjs
TYPO3_URL=http://127.0.0.1:8081 E2E_CONTAINER=demo-live EDITOR_EMAIL=… EDITOR_PASSWORD=… npm run live
```

## Demo (Railway)

The public demo is a container built from `demo/Dockerfile`: TYPO3 14.3 with the official **Camino** theme and its demo content (7 pages, ~50 content elements), English as default language plus German and French (Switzerland), and this extension installed from the repo itself. It runs on Railway in the `supertext-cms-demos` project, service `typo3`, region EU West (Amsterdam): <https://typo3-production.up.railway.app/> (backend: `/typo3/`).

**Deploys:** Railway watches `main` of this repository and rebuilds on every push, so a merged change is live a few minutes later. No GitHub secrets are needed.

**What's in `demo/`:**

| File | Purpose |
| --- | --- |
| `Dockerfile` | `php:8.5-apache` + extensions, `composer install` from the lock file, extension copied to `packages/supertext_translation` |
| `composer.json`, `composer.lock` | The demo project (TYPO3 core packages, Camino, this extension via a path repository) |
| `entrypoint.sh` | Keeps only Apache's prefork MPM (Railway otherwise fails with *More than one MPM loaded*), links persistent folders into `/data`, installs TYPO3 on first boot, creates missing demo accounts and runs `extension:setup` + `cache:flush` on every boot |
| `site-config.yaml` | Site configuration written on first boot (languages, `supertext_politeness`) |
| `additional.php` | Reverse-proxy and trusted-host settings for Railway's TLS proxy |
| `apache.conf`, `php.ini` | Web server and PHP settings |

**Persistent state** lives on a Railway volume (`typo3-data`) mounted at `/data` — the Dockerfile has no `VOLUME` line because Railway's builder rejects it: `var/` (SQLite database, caches, logs), `fileadmin/`, `sites/` and `system/settings.php`. Everything else comes from the image, so code changes never touch content. To reset the demo to fresh Camino content, delete the files on the volume (or recreate the volume) and redeploy.

**Service variables:**

| Variable | |
| --- | --- |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Admin backend user (the e-mail address is also the username). The password is required for the first boot, which installs TYPO3. Older names still work as fallbacks: `TYPO3_ADMIN_PASSWORD`, `TYPO3_ADMIN_USER` (default `admin`), `TYPO3_ADMIN_EMAIL`. |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Optional second account, e.g. for automated tests and screenshots. TYPO3 ships no ready-made editor group, so it is an admin without maintainer rights (no Install Tool or system maintenance). |
| `TYPO3_PROJECT_NAME` | Optional, first boot only |
| `SUPERTEXT_API_KEY` | Supertext key used by the extension |
| `SUPERTEXT_API_ENDPOINT` | Optional, e.g. the staging API (to try the review service, switch on *Translation review* in the site languages instead) |
| `SUPERTEXT_REVIEW_URL` | Optional: another review service than Supertext's (`reviewUrl`; empty keeps the default) |
| `SUPERTEXT_VERSION_REPORTS` | Optional; `0` turns version information to the Supertext Commando Center off (default on) |
| `TYPO3_TRUSTED_HOSTS` | Optional regex of allowed host names (default: any); on the demo it lists the Railway domain **and `healthcheck.railway.app`** (the host Railway's healthcheck sends; without it every check gets a 500) |
| `PORT` | Port Apache listens on; `8080` on Railway, matching the domain's target port |
| `RAILWAY_DOCKERFILE_PATH` | `demo/Dockerfile` (the build context is the repo root) |

**Demo accounts:** on every boot the entrypoint creates the `DEMO_ADMIN` and `DEMO_EDITOR` accounts if they don't exist yet, using TYPO3's `backend:user:create` with the values passed as environment variables (never on the command line). Existing accounts are never changed — to change a password, use the backend (*User settings* or *Backend users*). Passwords must meet TYPO3's password policy: at least 8 characters with an upper-case letter, a lower-case letter, a number and a special character. If one doesn't, that account is skipped and the log says which rule failed; the demo still starts.

**Run it locally:**

```bash
docker build -f demo/Dockerfile -t supertext-typo3-demo .
docker run --rm -p 8080:80 -v typo3demo:/data \
  -e DEMO_ADMIN_EMAIL=you@example.com -e DEMO_ADMIN_PASSWORD='Choose-one1!' -e SUPERTEXT_API_KEY=... supertext-typo3-demo
# frontend http://localhost:8080/  backend http://localhost:8080/typo3/
```

**Updating TYPO3 in the demo:** `cd demo && composer update "typo3/*"` in a checkout where `demo/packages/supertext_translation` exists (or mirror the container layout), then commit the new `composer.lock`. Composer must run with plugins enabled (`COMPOSER_ALLOW_SUPERUSER=1` when root), otherwise `vendor/typo3/autoload-include.php` is missing and TYPO3 fails with *"…/vendor/typo3/sysext/*/ directory does not exist"*.

The older install on demo.supertext.com (`/typo3-translation/`) is no longer deployed to and can be removed.

## Docs screenshots

The images in `docs/images/` are generated by `Tests/Docs/screenshots.mjs` (Playwright) from a local, freshly installed demo, against `Tests/Docs/stand-in.mjs`, which answers like the Supertext API and returns real German for the Camino FAQ page (`sample-de.json`). Regenerate them whenever the backend screens they show change:

```bash
# a local TYPO3 like the demo container: demo/composer.json + this extension in packages/,
# set up with `typo3 setup --distribution=theme_camino`, demo/site-config.yaml as the site config,
# and a system maintainer account (the setup admin)
cd Tests/Docs && npm install && npx playwright install chromium
npm run stand-in &
# serve TYPO3 with SUPERTEXT_API_KEY=test-key SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/
#   SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/api/v1/report (the stand-in also answers version reports)
# (e.g. php -S 127.0.0.1:8090 -t public with a router that sends everything else to public/index.php)
BASE_URL=http://127.0.0.1:8090 DEMO_ADMIN_EMAIL=... DEMO_ADMIN_PASSWORD=... npm run screenshots
```

The module shots (`module-export`, `module-import-result`) export the FAQ page, fill the XLIFF with the German of `sample-de.json` (what a translator would deliver) and import it.

Start from a database where the FAQ page has no German translation yet. Locally, drop `demo/additional.php`: its reverse-proxy settings make TYPO3 redirect to HTTPS.

## Demo movie

`docs/images/demo.gif` (shown at the top of the README) is recorded by `Tests/Docs/movie.mjs` with Supertext's movie kit (`Tests/Docs/movie/`: Playwright screencast → ffmpeg; intro and outro cards, captions, cursor; Inter font under the SIL Open Font License in `movie/assets/fonts/`). TYPO3's modals (the Localize wizard, the Extension Configuration) live in the browser's top layer, above any z-index, so `movie/top-layer.mjs` moves the kit's caption, cursor and click ripples into the top layer too (as popovers) while one is open; `movie-kit.mjs` itself is the shared kit, unchanged. Like the screenshots, the movie needs a **fresh** local demo pointed at the stand-in API (FAQ page not yet translated), `ffmpeg` and, for the voice, Piper:

```bash
pip install piper-tts                       # the voice; without it (or with MOVIE_VOICE=0) the movie is silent
cd Tests/Docs && npm install
npm run stand-in &                          # http://127.0.0.1:8765/v1/
# fresh local TYPO3 as for the screenshots, served with SUPERTEXT_API_KEY=test-key
#   SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/ SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/api/v1/report,
#   and the editor account (DEMO_EDITOR_*)
BASE_URL=http://127.0.0.1:8090 DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run movie
```

About 58 seconds, 1280×720, an English voice and English captions. TYPO3 has no "translate" button: the extension translates while TYPO3 localizes. So the movie shows that flow: the demo editor opens the English page *FAQs* in the page module, chooses *Deutsch (Schweiz)* under *Create new translation* in the language menu, keeps all content elements in TYPO3's *Localize* wizard, picks the mode *Translate* and clicks *Localize* (the waiting time is cut), clicks *Finish* and scrolls through the German page (hidden, with the *Supertext translated …* message). The script writes the GIF (800 px, 12 fps, keep it under 3 MB) to `docs/images/demo.gif` and the MP4 to `Tests/Docs/movie/out/Typo3-Supertext-Translation.mp4` (git-ignored; `MOVIE_OUT` changes the path). The MP4 is not committed; the GIF is silent. The voice model (63 MB, pinned revision, checked by SHA-256) is downloaded once into `Tests/Docs/movie/voice-cache/` (git-ignored, never committed); the time spent generating speech is cut from the movie. `CHROMIUM_PATH` uses another Chromium than Playwright's, `PAGE_ID` another page. Re-record it in the same commit when the localization flow or the extension's messages change.

**Install and configuration movie** (`Tests/Docs/movie-install.mjs`, `npm run movie:install`): the same kit, voice and look, for administrators. Same setup as above, but signed in as the demo **administrator** (a system maintainer: the setup admin is one) and with **no** `SUPERTEXT_API_KEY` in the environment (it would take precedence over the field the movie fills in):

```bash
# fresh local TYPO3 served with SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/
#   SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/api/v1/report and without SUPERTEXT_API_KEY
BASE_URL=http://127.0.0.1:8090 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… npm run movie:install
```

About 97 seconds: a terminal (`kit.terminal()`) with the commands from `docs/INSTALLATION.md` (`composer config repositories…`, `composer require`, `extension:setup`, `cache:flush`; output shortened), then the backend: *System → Settings → Configure extensions… → supertext_translation*, where the key comes from (supertext.com → Integrations → API; create an account at supertext.com), the made-up key `st-demo-0000-0000-0000` typed into *Supertext API key* and saved, *Sites → Setup →* edit the site *→ Languages → Deutsch (Schweiz)* with the *Supertext* palette (*Supertext language* Default (de-CH), *Translation review* left off), *Save*, and where editors translate: *Create new translation* in the page module's language menu. The password confirmation TYPO3 asks for before *Settings* happens before recording starts. The movie scrolls past the *Status* block of the extension configuration (it doesn't show the key check). No GIF; the MP4 goes to `Tests/Docs/movie/out/Typo3-Supertext-Translation-install.mp4` (git-ignored, not committed; `MOVIE_OUT` changes the path). Re-record it when the installation steps, the extension configuration or the site language's *Supertext* palette change.

Voice: Piper voice “alba” (en_GB), trained on a dataset from the University of Edinburgh's Centre for Speech Technology Research, CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/)

## Code quality and security checks

- **Checks** workflow (`.github/workflows/checks.yml`): actionlint and zizmor lint the workflows on every push and pull request; dependency review fails a pull request that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current), the default token is read-only and checkouts don't keep credentials. Locally: `pip install actionlint-py zizmor`, then `actionlint` and `zizmor .github/workflows` in the repo root.
- **Links** workflow (`.github/workflows/links.yml`): lychee checks all Markdown links weekly and whenever docs change on `main`. Broken links open (or update) the issue "Broken links in the docs". Links that can't work from CI (local URLs, placeholders, pages behind a login) are excluded in `.lycheeignore`.
- **PHPStan** (job *PHPStan* in `.github/workflows/ci.yml`, config `phpstan.neon`): level 5 over `Classes/` and `ext_localconf.php`. The job runs `composer install`, which installs TYPO3 core and PHPStan as dev dependencies, so PHPStan knows TYPO3's classes. Locally: `composer install && vendor/bin/phpstan analyse`. Findings that were there when PHPStan was introduced and aren't simple to fix are listed in `phpstan-baseline.neon` (regenerate with `vendor/bin/phpstan analyse --generate-baseline` only after fixing some, never to hide new ones); new code must pass without additions to it.
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as pull request comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.
- `.github/workflows/support-triage.yml`: support requests from the Supertext integrations website arrive as issues labelled `support` (contact details stay in the Supertext CMS). Claude classifies each one: answers questions (marked `<!-- supertext:reply -->`, emailed to the sender by the CMS) and closes them, fixes bugs as pull requests for review (never merged automatically), labels feature requests `feature-request`. Only people with triage rights can add labels, so outsiders can't start it. Needs the organisation secret `ANTHROPIC_API_KEY`; the workflow is the same in every Supertext repo.

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot pull requests and the issue "Broken links in the docs".

## Dependency updates

Dependabot (`.github/dependabot.yml`) opens weekly pull requests: minor and patch updates grouped into one, GitHub Actions in another, each major update on its own.

`.github/workflows/dependabot-merge.yml` merges a minor or patch package update by itself once every other check on its pull request has passed (it waits up to 85 minutes and leaves the pull request alone if a check fails). GitHub Actions updates and majors stay for a person: the workflow's built-in token can't merge changes to workflow files, and a major needs a decision. Merges by the workflow don't start CI on `main`; the weekly health check looks at `main`.

## Releasing

Releases are published by `.github/workflows/release.yml` when the version is officially bumped; nobody tags or creates releases by hand.

1. Move the *Unreleased* entries in `CHANGELOG.md` under a new `## X.Y.Z — YYYY-MM-DD` section, and keep an empty *Unreleased* above it.
2. Set the same version in:
   - `ext_emconf.php`: `version`, shown in TYPO3's extension manager
3. Push to `main`. The workflow checks that the version files match `CHANGELOG.md`, then tags `vX.Y.Z` and creates the GitHub release with the CHANGELOG section as notes (0.x versions as pre-releases). A push that adds no new version does nothing, and a version that is already released is skipped. After fixing a failed run, start it again with *Run workflow* on the *Release* workflow.
## Conventions

- Strict types, final classes, constructor injection (`Configuration/Services.yaml`).
- Exception codes are Unix timestamps (`1759500xxx` range for the API client).
- Interface strings live in `Resources/Private/Language/locallang.xlf` (English source) with `de.`, `fr.` and `it.locallang.xlf` (XLIFF 1.2, `approved="yes"`). `ext_conf_template.txt` labels are `LLL:` references (`Title: description`, split at the first colon); runtime messages go through `Localization\Labels`, which maps each SDK error code to a label. New or changed strings need all four languages in the same commit (formal Sie/vous/Lei, TYPO3's own terms, "Supertext", placeholders and URLs unchanged); `Tests/LanguageFilesTest.php` checks keys, placeholders and URLs.
- Keep the three docs in `docs/` current with every change (see `CLAUDE.md`).

## Known limitations / roadmap

- Translation runs synchronously inside the editor's request (up to `pollTimeout`). Planned: queue + scheduler task for large pages, and webhook completion via TYPO3 `reactions` if Supertext offers callbacks.
- FlexForm fields and container/grid extensions are not translated yet.
- No "retranslate" action for existing translations. A translation memory and reviewers' corrections come from the review service when a language has *Translation review* on; corrections reach TYPO3 only with the next translation (no push).
- Human (professional) translation orders are not supported yet (the WordPress plugin has them).
- Not yet tested on TYPO3 13.4 (the *Supertext* backend module included: it is written against APIs that exist in both, and registers under `web` there).
- The extension configuration has no *Test connection* button, and saving it can't be detected right away (no event): the key check runs when the configuration is opened again or the next translation runs.
- The `extension-configuration.png` screenshot predates the Status block and the *Send version information* setting; on TYPO3 14 a regenerated one would show the limitation below, so it is kept until that is solved.
- On TYPO3 14, *System → Settings → Extension Configuration* renders `ext_conf_template.txt` with the install tool's failsafe container, which has none of the extension's services (nor `SiteFinder`), so the Status block shows *The Supertext status is not available here*. The key check, version reports and language mapping still work; making the block render there needs building `SiteConfiguration` by hand or another place for the status (e.g. a backend module).
