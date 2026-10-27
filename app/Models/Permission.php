<?php

namespace App\Models;

use App\Models\Concerns\HasNumericKey;
use App\Reports\Reports;
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

        if (Lang::has($key)) {
            return __($key);
        }

        // A report's own permission (report.stock_card.view): "See the Stock card report".
        if (preg_match('/^report\.(.+)\.view$/', $this->code, $match) === 1) {
            $report = Reports::all()[str_replace('_', '-', $match[1])] ?? null;

            if ($report !== null && $report->permission() === $this->code) {
                return __('permissions.report', ['report' => $report->title()]);
            }
        }

        return $this->description;
    }

    /**
     * The sections of Settings › Roles in order: the Stock page, each draft kind
     * next to the document it becomes, the other document types, then the rest.
     * A module not listed comes last.
     */
    public const MODULES = [
        'STOCK', 'DRAFT_IN', 'IN', 'DRAFT_OUT', 'OUT', 'TRANSFER', 'ADJUSTMENT', 'DAMAGE',
        'OPENING_BALANCE', 'PRODUCT', 'MASTERDATA', 'REPORT', 'ADMIN',
    ];

    /** Within a section: seeing first, then doing, then cancelling (last part of the code). */
    private const ACTIONS = ['view', 'create', 'dispatch', 'receive', 'confirm', 'approve', 'import', 'manage', 'cancel'];

    public static function moduleRank(string $module): int
    {
        $rank = array_search($module, self::MODULES, true);

        return $rank === false ? count(self::MODULES) : $rank;
    }

    public function actionRank(): int
    {
        $rank = array_search(str($this->code)->afterLast('.')->toString(), self::ACTIONS, true);

        return $rank === false ? count(self::ACTIONS) : $rank;
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
