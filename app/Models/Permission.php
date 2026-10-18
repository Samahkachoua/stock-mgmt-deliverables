<?php

namespace App\Models;

use App\Models\Concerns\HasNumericKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Lang;

/**
 * @property string $id
 * @property string $code
 * @property string $description
 * @property string $module
 */
class Permission extends Model
{
    use HasNumericKey;

    protected $table = 'permission';

    // The table has no timestamps.
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'description',
        'module',
    ];

    /**
     * Translated description (lang/<locale>/permissions.php `labels`, keyed by the
     * code with dots as underscores), falling back to the stored description.
     */
    public function label(): string
    {
        $key = 'permissions.labels.'.str_replace('.', '_', $this->code);

        return Lang::has($key) ? __($key) : $this->description;
    }

    public static function moduleLabel(string $module): string
    {
        $key = 'permissions.modules.'.$module;

        return Lang::has($key) ? __($key) : $module;
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission', 'permission_id', 'role_id');
    }
}
