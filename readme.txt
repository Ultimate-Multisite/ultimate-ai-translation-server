=== Gratis AI Translations Server ===
Contributors: ultimate-multisite
Tags: translation, ai, glotpress, localization
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Server-side translation queue for AI-generated WordPress core, plugin, and theme language packs.

== Description ==

Gratis AI Translations Server runs on a GlotPress-powered WordPress installation and serves AI-generated core, plugin, and theme translations to client sites.

The plugin manages translation job requests, imports existing human translations where available, delegates AI translation work, builds language packages, and exposes REST endpoints for compatible client plugins.

== WordPress core REST contract ==

Core is a typed target, never a plugin alias. Clients request an exact WordPress version and locale with the canonical `wordpress` textdomain:

`POST /wp-json/gratis-ai-translations/v1/request-translation`

`{"target_type":"core","textdomain":"wordpress","version":"7.1","locales":["de_DE"]}`

The direct status endpoint uses the same `target_type`, `textdomain`, `version`, and one `locale`. The server also accepts `core` as a legacy textdomain alias and stores every core job as `(core, wordpress, version, locale)`.

For a mixed batch, send one optional core object alongside existing plugin and theme arrays:

`POST /wp-json/gratis-ai-translations/v1/batch-check-translations`

`{"core":{"version":"7.1"},"plugins":[...],"themes":[...],"locales":["de_DE"]}`

Core results are namespaced as `core:wordpress`; existing plugin and theme result keys and payloads are unchanged. A client that receives no core result from an older server can safely continue with its existing plugin/theme flow.

For core, the server resolves the exact WordPress.org language package for the requested version and locale, retains official non-empty translations, and only asks AI to fill eligible missing entries. Packages are version- and locale-specific archives for WordPress core language-pack installation, not Traduttore plugin packages. Core jobs require the normal queue approval flow unless approved by an administrator.

== Installation ==

1. Install and activate GlotPress.
2. Install and activate Gratis AI Translations Server.
3. Configure the translation provider options required by your deployment.
4. Connect compatible client sites to the server API.

== Changelog ==

= Unreleased =
- New: Add typed WordPress core translation targets for REST, queue, CLI, and dashboard operations.
- New: Import exact-version WordPress.org core language packages before AI fills eligible gaps.

= 1.4.0 =
Version 1.4.0 - Released on 2026-08-19
- Improved: WordPress compatibility metadata now reflects testing through WordPress 7.1.

= Version 1.3.0 - Released on 2026-07-09 =
- New: Route plugin and theme translation jobs through the Superdav AI Service while keeping existing AI translation compatibility.
- New: Import available human translations from translate.wordpress.org before filling remaining gaps with AI translations.
- New: Add a batch translation-check REST endpoint so client sites can request multiple translation status checks efficiently.
- New: Require manual approval for translation jobs before the queue processes them.
- New: Add queue management controls and context, including Delete All Pending, plugin source badges, and remaining/total string counts.
- Fix: Bound queue processing and harden request validation so large or invalid batches cannot overload the server.
- Fix: Keep queue processing active with recurring WP-Cron scheduling and clearer completed-today health reporting.
- Fix: Improve package generation and download reliability for Traduttore packages, human-only translations, fallback POT/PO imports, and server-domain download URLs.
- Fix: Improve GlotPress project metadata and import handling for missing projects, translated-source stripping, contexts, and API return formats.
- Fix: Use the correct Superdav v1 status endpoint when checking AI translation jobs.
- Improved: Simplify package building by relying on Traduttore's ZipProvider and removing redundant package endpoints.
- Improved: Add Composer, PHPStan WordPress tooling, WP-CLI configuration, and local development documentation for safer maintenance.
