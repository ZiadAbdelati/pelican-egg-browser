<?php

namespace Community\EggBrowser\Services;

use App\Models\Egg;
use Community\EggBrowser\Models\TrackedEgg;
use Illuminate\Support\Facades\Log;

/**
 * Removes unsafe/incorrect tracked-source associations.
 *
 * A source association is retained only if the local egg UUID equals the
 * upstream manifest UUID, or its update_url points to that exact source file.
 * This intentionally never deletes panel eggs — only plugin tracking rows.
 */
class TrackedEggRepairService
{
    public function __construct(protected EggManifestService $manifests) {}

    /**
     * @return array{checked: int, retained: int, removed: int, unresolved: int, errors: list<string>}
     */
    public function repair(bool $dryRun = false): array
    {
        $checked = 0;
        $retained = 0;
        $removed = 0;
        $unresolved = 0;
        $errors = [];

        TrackedEgg::query()->orderBy('id')->chunkById(50, function ($rows) use (&$checked, &$retained, &$removed, &$unresolved, &$errors, $dryRun) {
            foreach ($rows as $tracked) {
                $checked++;
                $egg = $tracked->egg_id ? Egg::query()->find($tracked->egg_id) : null;
                if (!$egg && $tracked->egg_uuid) {
                    $egg = Egg::query()->where('uuid', $tracked->egg_uuid)->first();
                }

                if (!$egg) {
                    // A deleted local egg is not a bad source association; leave history visible.
                    $retained++;
                    continue;
                }

                try {
                    $manifest = $this->manifests->fetch(
                        $tracked->source_owner,
                        $tracked->source_repo,
                        $tracked->source_path,
                        $tracked->source_branch ?: 'main',
                        forceRefresh: true,
                    );
                } catch (\Throwable $e) {
                    $unresolved++;
                    $errors[] = ($tracked->egg_name ?: "#{$tracked->egg_id}") . ': cannot verify source — ' . $e->getMessage();
                    continue;
                }

                $upstreamUuid = strtolower(trim((string) ($manifest['parsed']['uuid'] ?? '')));
                $localUuid = strtolower(trim((string) $egg->uuid));
                $uuidMatch = $upstreamUuid !== '' && $upstreamUuid === $localUuid;
                $urlMatch = $this->updateUrlMatches($egg->update_url, $tracked);

                if ($uuidMatch || $urlMatch) {
                    $retained++;
                    continue;
                }

                if (!$dryRun) {
                    $tracked->delete();
                }
                $removed++;

                Log::warning('[egg-browser] removed unverified tracked source', [
                    'tracked_id' => $tracked->id,
                    'egg_id' => $egg->id,
                    'egg_name' => $egg->name,
                    'source' => $tracked->sourceKey(),
                ]);
            }
        });

        return compact('checked', 'retained', 'removed', 'unresolved', 'errors');
    }

    protected function updateUrlMatches(?string $url, TrackedEgg $tracked): bool
    {
        $url = strtolower(trim((string) $url));
        if ($url === '') {
            return false;
        }

        $owner = strtolower($tracked->source_owner);
        $repo = strtolower($tracked->source_repo);
        $path = strtolower(ltrim($tracked->source_path, '/'));

        return str_contains($url, $owner . '/' . $repo)
            && (str_contains($url, '/' . $path) || str_contains($url, '/' . basename($path)));
    }
}
