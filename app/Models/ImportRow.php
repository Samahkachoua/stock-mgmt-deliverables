<?php

namespace App\Models;

use App\Imports\RowMessages;
use App\Models\Concerns\HasNumericKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One data row of an import file. `payload` holds `cells` (the row as
 * uploaded, in column order, for the error report) and `values` (cleaned
 * values by column key, for checking and importing).
 *
 * @property string $id
 * @property string $job_id
 * @property int $row_no row number in the spreadsheet (the header is row 1)
 * @property array{cells: list<string>, values: array<string, string>} $payload
 * @property string $status PENDING | VALID | WARNING | ERROR | IMPORTED
 * @property string|null $message JSON from RowCheck::message(); read it with text()
 */
class ImportRow extends Model
{
    use HasNumericKey;

    public const PROBLEMS = ['WARNING', 'ERROR'];

    protected $table = 'import_row';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * The row's messages as sentences, in the current language (or $locale).
     */
    public function text(?string $locale = null): string
    {
        return RowMessages::render($this->message, $locale);
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'row_no' => 'integer'];
    }
}
