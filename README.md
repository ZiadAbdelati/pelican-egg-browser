# Egg Browser

Pelican Panel plugin for discovering, installing, and maintaining game-server eggs from the [official Pelican egg repositories](https://github.com/pelican-eggs).

| | |
|---|---|
| **Plugin ID** | `egg-browser` |
| **Version** | 1.0.0 |
| **Author** | community |
| **License** | MIT |
| **Panel** | Admin |
| **Requires** | Pelican Panel (incl. beta; `panel_version` unset) |

Inspired by patterns used in official plugins on the [Pelican Hub marketplace](https://hub.pelican.dev/plugins) and the [pelican-dev/plugins](https://github.com/pelican-dev/plugins) repository.

---

## Features

1. **Official repository browser**
   - Defaults to the split `pelican-eggs/*` category repositories (minecraft, games-steamcmd, games-standalone, generic, voice, chatbots, database, storage, monitoring, software).
   - Admins can add any additional GitHub egg repos in settings.
   - Indexes via the GitHub Trees API with caching, timeouts, retries, and rate-limit aware errors.
   - Search + filters: free text, repository, category, install status.
   - Paginated card grid with name, category, short description, blob revision, and install status.

2. **Egg detail view**
   - Readable manifest summary (images, startup, variable count, install container, stop command).
   - Raw JSON viewer + download.
   - One-click install into Pelican via the panel’s `EggImporterService` (supports PTDL_v1/v2 and PLCN formats).

3. **Install tracking & update checks**
   - Tracks source (`owner/repo/path`), install-time fingerprint, and current local fingerprint.
   - Status matrix:
     - `Up to date`
     - `Update available`
     - `Local changes detected`
     - `Local changes + update`
     - `Source unavailable`
     - `Unknown/unlinked`
     - `Check failed`
     - `Not installed`
   - On-demand check (single egg or all) and optional scheduled checks (default every 6 hours).
   - Structured section diff: metadata, startup, docker images, variables, scripts, config files, install script, features, file denylist, tags.
   - Updates overwrite in place; **force required** when local changes are detected.

> **Note on nests:** Modern Pelican replaced nests with **tags**. This plugin’s “nest mapping” is implemented as a configurable **tag strategy** (repository / category / custom / ask).

---

## Install

### From this repository

1. Copy the plugin folder into your panel:

```bash
# from the panel root (default /var/www/pelican)
cp -r /path/to/egg-browser plugins/egg-browser
```

The folder name **must** be `egg-browser` (matches `plugin.json` `id`).

2. Install / enable the plugin:

```bash
php artisan p:plugin:install
# or use Admin → Plugins → Import
```

3. Run migrations (auto-discovered by the plugin system; if needed):

```bash
php artisan migrate
```

4. Ensure the scheduler is running (for periodic update checks):

```cron
* * * * * php /var/www/pelican/artisan schedule:run >> /dev/null 2>&1
```

5. Optional: queue worker for async jobs:

```bash
php artisan queue:work
```

### Permissions

The plugin registers admin permissions under `egg_browser`:

| Permission | Purpose |
|---|---|
| `egg_browser.view` | Browse catalog / tracked list |
| `egg_browser.install` | Install eggs |
| `egg_browser.update` | Apply updates |
| `egg_browser.manage` | Settings & bulk checks |

Root admins always have access. Grant the custom permissions on roles as needed.

### Linking existing panel eggs safely

`Link installed eggs` uses each panel egg’s **`update_url`** (the same field the panel’s built-in egg updater uses). Supported forms:

```text
https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/paper/egg-paper.yaml
https://raw.githubusercontent.com/pelican-eggs/minecraft/main/java/paper/egg-paper.yaml
https://github.com/pelican-eggs/minecraft/blob/main/java/paper/egg-paper.yaml
```

Both `.yaml` and `.json` are supported. Name/slug guessing is **not** used for auto-link (it previously caused wrong source associations).

If an earlier Egg Browser version created guessed links, remove only invalid **tracking rows** (never local panel eggs):

```bash
php artisan egg-browser:repair-tracking --dry-run
php artisan egg-browser:repair-tracking
```

Then run:

```bash
php artisan egg-browser:refresh-index
php artisan egg-browser:link-local
```

Eggs without a usable `update_url` stay untracked until you set one or install them from the browser.

---

## Configuration

Open **Admin → Plugins → Egg Browser → Settings**, or set environment variables:

| Env | Default | Description |
|---|---|---|
| `EGG_BROWSER_GITHUB_TOKEN` | _(empty)_ | Optional GitHub PAT (raises rate limits) |
| `EGG_BROWSER_EXTRA_REPOSITORIES` | _(empty)_ | CSV of `owner/name` or `owner/name@branch` |
| `EGG_BROWSER_TAG_STRATEGY` | `repository` | `repository` \| `category` \| `custom` \| `ask` |
| `EGG_BROWSER_DEFAULT_TAGS` | _(empty)_ | Comma-separated tags always applied |
| `EGG_BROWSER_PREFER_PELICAN_JSON` | `true` | Prefer non-`pterodactyl-*` egg files |
| `EGG_BROWSER_SET_UPDATE_URL` | `true` | Write raw GitHub URL into egg `meta.update_url` |
| `EGG_BROWSER_SCHEDULE_ENABLED` | `true` | Enable cron-based update checks |
| `EGG_BROWSER_SCHEDULE_CRON` | `0 */6 * * *` | Scheduler expression |
| `EGG_BROWSER_INDEX_TTL` | `3600` | Repo tree cache seconds |
| `EGG_BROWSER_MANIFEST_TTL` | `1800` | Egg JSON cache seconds |
| `EGG_BROWSER_HTTP_TIMEOUT` | `20` | HTTP timeout seconds |
| `EGG_BROWSER_HTTP_RETRIES` | `2` | Retry count on 429/5xx |

### Repository list format (settings textarea)

```
pelican-eggs/minecraft
pelican-eggs/games-steamcmd
pelican-eggs/games-standalone@main
my-org/custom-eggs@develop
# disabled official repo:
pelican-eggs/voice #disabled
```

Full `https://github.com/owner/repo` URLs are accepted.

---

## Usage

1. **Admin → Eggs → Egg Browser** — browse/search the catalog.
2. Open an egg card for the detail view (summary + raw JSON).
3. **Install** — imports via Pelican’s importer; creates a tracking row.
4. **Installed Eggs** — list of tracked eggs with statuses.
5. **Check updates** (header or per-row) — refreshes status against upstream.
6. On **Update available**, open the egg, review the section diff, then **Update** (force if local edits exist).

### Artisan

```bash
# Refresh GitHub repository indexes
php artisan egg-browser:refresh-index

# Check all tracked eggs (queue)
php artisan egg-browser:check-updates

# Check synchronously, refresh index first
php artisan egg-browser:check-updates --sync --refresh-index

# Check one tracked row
php artisan egg-browser:check-updates --egg=12 --sync
```

---

## Architecture

```
egg-browser/
├── plugin.json
├── config/egg-browser.php
├── database/migrations/
├── lang/en/strings.php
├── resources/views/filament/
└── src/
    ├── EggBrowserPlugin.php          # Filament plugin + HasPluginSettings
    ├── Console/Commands/
    ├── Enums/EggInstallStatus.php
    ├── Exceptions/
    ├── Filament/Admin/Pages/         # Browser, Detail, Tracked list
    ├── Jobs/                         # Index refresh + update checks
    ├── Models/                       # TrackedEgg, RepositoryCache, PluginSetting
    ├── Providers/EggBrowserPluginProvider.php
    ├── Services/
    │   ├── GitHubClient.php
    │   ├── RepositoryConfigService.php
    │   ├── EggIndexService.php
    │   ├── EggManifestService.php
    │   ├── EggStatusService.php      # 3-way fingerprint status
    │   └── EggInstallService.php     # wraps EggImporterService
    └── Support/
        ├── EggNormalizer.php         # fingerprints + section diffs
        └── EggPathMatcher.php        # tree → egg file discovery
```

### Status model (3-way)

| | Meaning |
|---|---|
| **Installed fingerprint** | Normalized hash of upstream JSON at last install/update |
| **Current fingerprint** | Normalized hash of live panel egg export |
| **Upstream fingerprint** | Normalized hash of latest upstream JSON |

- Current ≠ installed → **Local changes**
- Installed ≠ upstream → **Update available**
- Both → **Local changes + update** (force required to apply)

Volatile fields (`exported_at`, `_comment`, icons, UUID for content hash) are stripped/normalized before hashing.

### Import path

Install/update calls `App\Services\Eggs\Sharing\EggImporterService::fromContent()` so the plugin stays compatible with Pelican’s egg format upgrades (`PTDL_*` → `PLCN_v3`).

---

## Development

See [AGENTS.md](./AGENTS.md) for contributor / agent guidance.

```bash
# syntax check (no panel required)
find src -name '*.php' -print0 | xargs -0 -n1 php -l
```

Packaging for distribution: remove any `meta` key from `plugin.json` if present, zip the `egg-browser` folder, share or submit to the Hub.

---

## Defaults: official repositories

| Repository | Category |
|---|---|
| [pelican-eggs/minecraft](https://github.com/pelican-eggs/minecraft) | Minecraft |
| [pelican-eggs/games-steamcmd](https://github.com/pelican-eggs/games-steamcmd) | SteamCMD games |
| [pelican-eggs/games-standalone](https://github.com/pelican-eggs/games-standalone) | Standalone games |
| [pelican-eggs/generic](https://github.com/pelican-eggs/generic) | Generic / language |
| [pelican-eggs/voice](https://github.com/pelican-eggs/voice) | Voice |
| [pelican-eggs/chatbots](https://github.com/pelican-eggs/chatbots) | Chatbots |
| [pelican-eggs/database](https://github.com/pelican-eggs/database) | Databases |
| [pelican-eggs/storage](https://github.com/pelican-eggs/storage) | Storage |
| [pelican-eggs/monitoring](https://github.com/pelican-eggs/monitoring) | Monitoring |
| [pelican-eggs/software](https://github.com/pelican-eggs/software) | Other software |

Egg directory: https://pelican-eggs.github.io/

---

## License

MIT — see [LICENSE](./LICENSE).
