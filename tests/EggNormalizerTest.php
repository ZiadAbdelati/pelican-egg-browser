<?php

/**
 * Lightweight assertions for EggNormalizer.
 *
 * Run inside a panel environment (or any PHP with this file's sibling autoload):
 *   php plugins/egg-browser/tests/EggNormalizerTest.php
 *
 * This file intentionally avoids PHPUnit so it can run with plain PHP.
 */

namespace Community\EggBrowser\Tests;

use Community\EggBrowser\Support\EggNormalizer;

require_once dirname(__DIR__) . '/src/Support/EggNormalizer.php';

function assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "OK: {$message}\n";
}

$n = new EggNormalizer();

$base = [
    'name' => 'Demo',
    'author' => 'author@example.com',
    'description' => "Hello\r\nWorld",
    'uuid' => '11111111-1111-1111-1111-111111111111',
    'meta' => ['version' => 'PLCN_v1', 'update_url' => null],
    'docker_images' => ['Java' => 'ghcr.io/pelican-eggs/yolks:java_17'],
    'startup' => './server',
    'config' => [
        'files' => '{}',
        'startup' => '{"done": "Ready"}',
        'logs' => '{}',
        'stop' => '^C',
    ],
    'scripts' => [
        'installation' => [
            'script' => "#!/bin/bash\necho hi\n",
            'container' => 'ghcr.io/pelican-eggs/installers:debian',
            'entrypoint' => 'bash',
        ],
    ],
    'variables' => [
        [
            'name' => 'Version',
            'env_variable' => 'VERSION',
            'default_value' => 'latest',
            'user_viewable' => true,
            'user_editable' => true,
            'rules' => 'required|string',
        ],
    ],
];

$fp1 = $n->fingerprint($base);

// UUID change must not affect content fingerprint
$otherUuid = $base;
$otherUuid['uuid'] = '22222222-2222-2222-2222-222222222222';
assert_true($fp1 === $n->fingerprint($otherUuid), 'fingerprint ignores uuid');

// Description newline normalization
$otherDesc = $base;
$otherDesc['description'] = "Hello\nWorld";
assert_true($fp1 === $n->fingerprint($otherDesc), 'fingerprint normalizes newlines');

// Content change must affect fingerprint
$changed = $base;
$changed['startup'] = './other';
assert_true($fp1 !== $n->fingerprint($changed), 'fingerprint changes with startup');

// Legacy startup becomes startup_commands
$normalized = $n->normalize($base);
assert_true(isset($normalized['startup_commands']['Default']), 'legacy startup mapped');
assert_true($normalized['startup_commands']['Default'] === './server', 'legacy startup value');

// Section diff detects startup change
$diff = $n->diffSections($base, $changed);
assert_true($diff['startup']['changed'] === true, 'diff marks startup changed');
assert_true($diff['docker_images']['changed'] === false, 'diff keeps docker images unchanged');

// Rules array normalization from pipe string
assert_true(
    $normalized['variables'][0]['rules'] === ['required', 'string'],
    'variable rules split from pipe string'
);

echo "\nAll EggNormalizer checks passed.\n";
