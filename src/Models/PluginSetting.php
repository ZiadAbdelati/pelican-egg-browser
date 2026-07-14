<?php

namespace Community\EggBrowser\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property array|null $value
 */
class PluginSetting extends Model
{
    protected $table = 'egg_browser_settings';

    protected $fillable = [
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
