<?php

return [
    /*
    |--------------------------------------------------------------------------
    | GitHub authentication
    |--------------------------------------------------------------------------
    |
    | Optional personal access token. Unauthenticated requests are limited to
    | 60/hour; a token raises this substantially for large index refreshes.
    |
    */
    'github_token' => env('EGG_BROWSER_GITHUB_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | HTTP behaviour
    |--------------------------------------------------------------------------
    */
    'http' => [
        'timeout' => (int) env('EGG_BROWSER_HTTP_TIMEOUT', 20),
        'connect_timeout' => (int) env('EGG_BROWSER_HTTP_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('EGG_BROWSER_HTTP_RETRIES', 2),
        'retry_sleep_ms' => (int) env('EGG_BROWSER_HTTP_RETRY_SLEEP_MS', 500),
        'user_agent' => env('EGG_BROWSER_USER_AGENT', 'Pelican-Egg-Browser/1.0 (+https://github.com/pelican-eggs)'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'index_ttl' => (int) env('EGG_BROWSER_INDEX_TTL', 3600),
        'manifest_ttl' => (int) env('EGG_BROWSER_MANIFEST_TTL', 1800),
        'prefix' => env('EGG_BROWSER_CACHE_PREFIX', 'egg-browser'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled update checks
    |--------------------------------------------------------------------------
    */
    'schedule' => [
        'enabled' => (bool) env('EGG_BROWSER_SCHEDULE_ENABLED', true),
        // Cron expression evaluated by the panel scheduler (default: daily at 03:00).
        'cron' => env('EGG_BROWSER_SCHEDULE_CRON', '0 3 * * *'),
        'refresh_index_before_check' => (bool) env('EGG_BROWSER_REFRESH_INDEX_BEFORE_CHECK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Install behaviour
    |--------------------------------------------------------------------------
    |
    | tag_strategy:
    |   - repository: apply a tag named after the source repo (e.g. games-steamcmd)
    |   - category: same as repository for official repos; path segment for nested eggs
    |   - custom: always use default_tags
    |   - ask: no automatic tags; admin chooses at install time
    |
    */
    'install' => [
        'tag_strategy' => env('EGG_BROWSER_TAG_STRATEGY', 'repository'),
        'default_tags' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('EGG_BROWSER_DEFAULT_TAGS', ''))
        ))),
        'prefer_pelican_json' => (bool) env('EGG_BROWSER_PREFER_PELICAN_JSON', true),
        'set_update_url' => (bool) env('EGG_BROWSER_SET_UPDATE_URL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Official default repositories
    |--------------------------------------------------------------------------
    |
    | Each entry is a GitHub repository under the pelican-eggs organisation
    | (or any other owner/name). Admins can extend this list in settings; the
    | values below are the built-in defaults used when settings are empty.
    |
    */
    'default_repositories' => [
        [
            'owner' => 'pelican-eggs',
            'name' => 'minecraft',
            'label' => 'Minecraft',
            'category' => 'minecraft',
            'branch' => 'main',
            'enabled' => true,
        ],
        [
            'owner' => 'pelican-eggs',
            'name' => 'games-steamcmd',
            'label' => 'SteamCMD Games',
            'category' => 'games-steamcmd',
            'branch' => 'main',
            'enabled' => true,
        ],
        [
            'owner' => 'pelican-eggs',
            'name' => 'games-standalone',
            'label' => 'Standalone Games',
            'category' => 'games-standalone',
            'branch' => 'main',
            'enabled' => true,
        ],
        [
            'owner' => 'pelican-eggs',
            'name' => 'generic',
            'label' => 'Generic / Language',
            'category' => 'generic',
            'branch' => 'main',
            'enabled' => true,
        ],
        [
            'owner' => 'pelican-eggs',
            'name' => 'voice',
            'label' => 'Voice Servers',
            'category' => 'voice',
            'branch' => 'main',
            'enabled' => true,
        ],
        [
            'owner' => 'pelican-eggs',
            'name' => 'chatbots',
            'label' => 'Chatbots',
            'category' => 'chatbots',
            'branch' => 'main',
            'enabled' => true,
        ],
        [
            'owner' => 'pelican-eggs',
            'name' => 'database',
            'label' => 'Databases',
            'category' => 'database',
            'branch' => 'main',
            'enabled' => true,
        ],
        [
            'owner' => 'pelican-eggs',
            'name' => 'storage',
            'label' => 'Storage',
            'category' => 'storage',
            'branch' => 'main',
            'enabled' => true,
        ],
        [
            'owner' => 'pelican-eggs',
            'name' => 'monitoring',
            'label' => 'Monitoring',
            'category' => 'monitoring',
            'branch' => 'main',
            'enabled' => true,
        ],
        [
            'owner' => 'pelican-eggs',
            'name' => 'software',
            'label' => 'Other Software',
            'category' => 'software',
            'branch' => 'main',
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra repositories (env CSV: owner/name or owner/name@branch)
    |--------------------------------------------------------------------------
    */
    'extra_repositories' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('EGG_BROWSER_EXTRA_REPOSITORIES', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Egg file discovery
    |--------------------------------------------------------------------------
    */
    'discovery' => [
        // Prefer Pelican-native filenames over legacy pterodactyl-* copies.
        // Official pelican-eggs repos now ship PLCN eggs as .yaml (and still keep some .json).
        'include_patterns' => [
            '#(^|/)egg-[^/]+\.(json|ya?ml)$#i',
            '#(^|/)pelican-egg-[^/]+\.(json|ya?ml)$#i',
            '#(^|/)egg\.(json|ya?ml)$#i',
        ],
        'exclude_patterns' => [
            '#(^|/)egg-pterodactyl-[^/]+\.(json|ya?ml)$#i',
            '#(^|/)pterodactyl-egg-[^/]+\.(json|ya?ml)$#i',
            '#(^|/)\.github/#',
        ],
    ],
];
