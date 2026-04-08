# AGENTS.md — Gratis AI Translations Server

## Project Overview

WordPress plugin (server-side) that generates and serves AI-translated plugin translations via REST API. Runs on translate.ultimatemultisite.com alongside GlotPress. Manages a translation job queue, generates `.mo`/`.po` packages, and serves them to client installations running the Gratis AI Plugin Translations plugin.

## Build Commands

```bash
# No build step — pure PHP plugin with no compiled assets
# No Composer runtime dependencies
```

## Project Structure

```
ultimate-ai-translation-server/
├── gratis-ai-translations-server.php  # Plugin entry point (namespaced)
├── src/
│   ├── class-rest-api.php             # REST API endpoints
│   ├── class-translation-queue.php    # Job queue management
│   ├── class-translation-generator.php # AI translation generation
│   ├── class-package-builder.php      # .mo/.po package creation
│   ├── class-admin-dashboard.php      # Admin monitoring UI
│   └── class-cli.php                  # WP-CLI commands
├── composer.json                      # PSR-4 autoload config only
└── LICENSE
```

## Code Style & Conventions

- **PHP version**: >= 8.2
- **Namespace**: `GratisAITranslationsServer\`
- **Autoloading**: Custom `spl_autoload_register` (maps to `src/class-{name}.php`)
- **File naming**: `class-{name}.php` in `src/`
- **Text domain**: `gratis-ai-translations-server`
- **Network plugin**: `Network: true`
- **Constants prefix**: `GRATIS_AI_TS_`
- **Uses `declare(strict_types=1)`**
- **Requires GlotPress** (`Requires Plugins: glotpress`)

## Key Patterns

- Singleton pattern on service classes: `REST_API::instance()`
- Hooks into `plugins_loaded` at priority 20
- Custom database table: `{$wpdb->base_prefix}gratis_ai_translation_jobs`
- Storage directory: `wp-content/gratis-ai-translations/` (packages, temp, logs)
- Job queue processed via WP-Cron (`gratis_ai_ts_process_queue`)
- WP-CLI commands under `wp gratis-ai-server`
- Rate limiting and concurrent job limits configurable via site options

## Important Notes

- This is the **server** plugin — see `ultimate-ai-plugin-translations` for the client component
- Requires GlotPress to be installed and active
- Creates filesystem storage under `wp-content/gratis-ai-translations/` with `.htaccess` protection
- Database table created on activation via `dbDelta()`

=======

## Local Development Environment

The shared WordPress dev install for testing this plugin is at `../wordpress` (relative to this repo root).

- **URL**: http://wordpress.local:8080
- **Admin**: http://wordpress.local:8080/wp-admin — `admin` / `admin`
- **WordPress version**: 7.0-RC2
- **This plugin**: symlinked into `../wordpress/wp-content/plugins/$(basename $PWD)`
- **Reset to clean state**: `cd ../wordpress && ./reset.sh`

WP-CLI is configured via `wp-cli.yml` in this repo root — run `wp` commands directly from here without specifying `--path`.

```bash
wp plugin activate $(basename $PWD)   # activate this plugin
wp plugin deactivate $(basename $PWD) # deactivate
wp db reset --yes && cd ../wordpress && ./reset.sh  # full reset
```
