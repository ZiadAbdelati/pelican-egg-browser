# AGENTS.md — Egg Browser

Guidance for humans and coding agents working on this Pelican Panel plugin.

## What this is

A production Pelican **admin plugin** (`id: egg-browser`) that:

1. Indexes eggs from GitHub repositories (default: official `pelican-eggs/*`).
2. Installs them through Pelican’s `EggImporterService`.
3. Tracks install fingerprints and reports update / local-change status.
4. Optionally runs scheduled update checks via the panel scheduler.

Reference plugins for structure and Filament conventions:

- https://hub.pelican.dev/plugins
- https://github.com/pelican-dev/plugins (especially `player-counter`, `announcements`, `minecraft-modrinth`)
- https://pelican.dev/docs/panel/advanced/plugins/

Panel import implementation to wrap, not reimplement:

- `App\Services\Eggs\Sharing\EggImporterService`
- `App\Services\Eggs\Sharing\EggExporterService`
- `App\Models\Egg` (`EXPORT_VERSION = PLCN_v3`)

## Hard constraints

- **Folder name === `plugin.json` `id` === `egg-browser`.**
- Namespace: `Community\EggBrowser`. Main class: `EggBrowserPlugin`.
- `app/` does not exist — use `src/` (Pelican convention).
- Do **not** vendor a full Laravel app. This tree is dropped into `panel/plugins/egg-browser`.
- Prefer panel services (`EggImporterService`) over hand-rolled DB egg writes.
- Nests are gone in modern Pelican — use **tags** (`install.tag_strategy`).
- Never commit secrets. GitHub tokens go in env / plugin settings only.

## Layout map

| Path | Role |
|---|---|
| `plugin.json` | Manifest (id, class, panels, panel_version) |
| `config/egg-browser.php` | Defaults + env mapping |
| `src/EggBrowserPlugin.php` | Filament `Plugin` + `HasPluginSettings` |
| `src/Providers/*` | Permissions, bindings, schedule, commands |
| `src/Services/*` | GitHub, index, manifest, status, install |
| `src/Support/EggNormalizer.php` | Fingerprints + section diffs |
| `src/Filament/Admin/Pages/*` | Admin UI (Livewire pages) |
| `resources/views/filament/*` | Blade views for those pages |
| `database/migrations/*` | `egg_browser_*` tables |
| `lang/en/strings.php` | UI copy (`egg-browser::strings.*`) |

## Status algorithm (do not “simplify” carelessly)

Three hashes on normalized egg JSON (UUID excluded from content hash):

1. `installed_fingerprint` — snapshot at last install/update through this plugin  
2. `current_fingerprint` — live `EggExporterService` export  
3. `upstream_fingerprint` — latest upstream JSON  

| Condition | Status |
|---|---|
| no local egg | `not_installed` |
| local egg, no track row | `unknown_unlinked` |
| current ≠ installed | local changes |
| installed ≠ upstream | update available |
| both | `local_changes_and_update` |
| fetch error | `check_failed` |
| no upstream reachable / no cached upstream | `source_unavailable` |

**Update apply policy:** overwrite in place; if local changes → require `force=true`.

Normalization lives only in `EggNormalizer`. Keep section keys stable — the UI diff table depends on `DIFF_SECTIONS`.

## GitHub access

- Use `GitHubClient` for all GitHub HTTP (timeouts, retries, PAT, rate-limit errors).
- Trees API recursive listing discovers eggs; raw.githubusercontent.com fetches JSON.
- Prefer Pelican-native filenames over `*pterodactyl*` twins (`EggPathMatcher`).
- On rate limit, surface `GitHubException::rateLimited` and recommend `EGG_BROWSER_GITHUB_TOKEN`.

## UI conventions

- Admin panel only (`panels: ["admin"]`).
- Discover pages via `EggBrowserPlugin::register()` → `src/Filament/Admin/Pages`.
- Views are namespaced: `egg-browser::filament.*`.
- Match Filament v4 / Pelican patterns from Hub plugins (`navigationIcon` as `tabler-*`, `Width` enums, Actions API).
- Keep list views cheap: **do not** fetch every upstream manifest on the index page. Full upstream compare happens on detail / explicit check.

## Jobs & schedule

| Job / command | Purpose |
|---|---|
| `RefreshEggIndexJob` / `egg-browser:refresh-index` | Rebuild tree indexes |
| `CheckTrackedEggJob` | One tracked egg |
| `CheckAllTrackedEggsJob` / `egg-browser:check-updates` | All tracked eggs |
| Provider `Schedule` | Cron from `egg-browser.schedule.cron` when enabled |

Queue is preferred; commands support `--sync` for debugging.

## Database

Tables (prefix `egg_browser_`):

- `repository_cache` — last tree index per owner/name/branch  
- `tracked_eggs` — link panel egg ↔ upstream path + fingerprints  
- `settings` — structured repository list override  

Migrations auto-register with the panel plugin loader.

## Safe change checklist

1. Touch only plugin files under this repo (never panel core).
2. If changing fingerprint fields, document migration impact — old fingerprints may flip status until reinstall/relink.
3. If adding a status, update `EggInstallStatus`, translations, and both Blade status color maps.
4. Keep install path going through `EggImporterService::fromContent`.
5. Run `php -l` on changed PHP files when a full panel isn’t available.
6. Do not add Composer deps unless necessary; declare them in `plugin.json` `composer_packages` if you do.

## Out of scope (unless explicitly requested)

- Client/server panel UI for end users  
- Auto-updating running servers’ files (egg definition only)  
- Non-GitHub sources (S3, Gitea, etc.)  
- Selling / licensing gates  

## Release packaging

1. Bump `version` in `plugin.json`.  
2. Ensure no `meta` block remains in `plugin.json` for public zips.  
3. Zip the `egg-browser` directory.  
4. Optional: publish to Hub (`https://hub.pelican.dev/plugins`) with an `update_url`.

## Quick smoke (no panel)

```bash
find src config database -name '*.php' -print0 | xargs -0 -n1 php -l
php -r 'require "src/Support/EggNormalizer.php";'  # needs autoload; prefer php -l only
```

With a panel checkout:

```bash
php artisan p:plugin:install
php artisan migrate
php artisan egg-browser:refresh-index
php artisan egg-browser:check-updates --sync
```
