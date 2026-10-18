<?php

namespace App\Models;

use App\Models\Concerns\HasNumericKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One uploaded import file (App\Imports\ImportEngine).
 *
 * @property string $id
 * @property string $job_type PRODUCT | OPENING_BALANCE
 * @property string $file_name
 * @property string $status UPLOADED | VALIDATED | CONFIRMED | FAILED
 * @property string $created_by
 * @property string|null $disk
 * @property string|null $path
 * @property int $total_rows
 * @property int $processed_rows
 * @property string|null $error_message
 * @property list<string>|null $headers
 * @property list<string>|null $warehouse_ids
 * @property array<string, mixed>|null $result
 * @property string|null $confirmed_by
 * @property Carbon $created_at
 * @property Carbon|null $confirmed_at
 * @property-read User $creator
 * @property-read User|null $confirmer
 */
class ImportJob extends Model
{
    use HasNumericKey;

    protected $table = 'import_job';

    // created_at only, set by the database; confirmed_at by the engine.
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'warehouse_ids' => 'array',
            'result' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'created_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'job_id');
    }
}
