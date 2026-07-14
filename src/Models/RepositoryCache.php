<?php

namespace Community\EggBrowser\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Persists the last successful tree index for a repository.
 *
 * @property int $id
 * @property string $owner
 * @property string $name
 * @property string $branch
 * @property string|null $tree_sha
 * @property array|null $eggs
 * @property string|null $last_error
 * @property Carbon|null $fetched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RepositoryCache extends Model
{
    protected $table = 'egg_browser_repository_cache';

    protected $fillable = [
        'owner',
        'name',
        'branch',
        'tree_sha',
        'eggs',
        'last_error',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'eggs' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    public function fullName(): string
    {
        return "{$this->owner}/{$this->name}";
    }
}
