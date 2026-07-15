<?php

namespace Community\EggBrowser\Models;

use App\Models\Egg;
use Community\EggBrowser\Enums\EggInstallStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tracks eggs installed/linked through Egg Browser for update comparison.
 *
 * @property int $id
 * @property int|null $egg_id
 * @property string $source_owner
 * @property string $source_repo
 * @property string $source_path
 * @property string $source_branch
 * @property string|null $source_sha
 * @property string|null $source_blob_sha
 * @property string|null $upstream_fingerprint
 * @property string|null $installed_fingerprint
 * @property array|null $installed_snapshot
 * @property string|null $egg_uuid
 * @property string|null $egg_name
 * @property string $status
 * @property string|null $last_error
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $checking_disabled_at
 * @property Carbon|null $last_installed_at
 * @property Carbon|null $last_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Egg|null $egg
 */
class TrackedEgg extends Model
{
    protected $table = 'egg_browser_tracked_eggs';

    protected $fillable = [
        'egg_id',
        'source_owner',
        'source_repo',
        'source_path',
        'source_branch',
        'source_sha',
        'source_blob_sha',
        'upstream_fingerprint',
        'installed_fingerprint',
        'installed_snapshot',
        'egg_uuid',
        'egg_name',
        'status',
        'last_error',
        'last_checked_at',
        'checking_disabled_at',
        'last_installed_at',
        'last_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'installed_snapshot' => 'array',
            'last_checked_at' => 'datetime',
            'checking_disabled_at' => 'datetime',
            'last_installed_at' => 'datetime',
            'last_updated_at' => 'datetime',
        ];
    }

    public function egg(): BelongsTo
    {
        return $this->belongsTo(Egg::class);
    }

    public function statusEnum(): EggInstallStatus
    {
        return EggInstallStatus::tryFrom($this->status) ?? EggInstallStatus::UnknownUnlinked;
    }

    public function sourceKey(): string
    {
        return strtolower("{$this->source_owner}/{$this->source_repo}:{$this->source_path}");
    }

    public function rawUrl(): string
    {
        $branch = $this->source_branch ?: 'main';

        return "https://raw.githubusercontent.com/{$this->source_owner}/{$this->source_repo}/{$branch}/{$this->source_path}";
    }

    public function htmlUrl(): string
    {
        $branch = $this->source_branch ?: 'main';

        return "https://github.com/{$this->source_owner}/{$this->source_repo}/blob/{$branch}/{$this->source_path}";
    }
}
