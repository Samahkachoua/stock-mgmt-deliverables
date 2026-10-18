<?php

namespace App\Imports;

use App\Jobs\ReadImportFile;
use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Models\User;
use App\Services\Stock\Exceptions\StockException;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * The staged Excel import (SRS FR-10.2/10.3), the same for every kind:
 *
 *   1 Upload   upload(): the file is stored on the imports disk, an import_job
 *              is created and ReadImportFile is queued.
 *   2 Check    read(): rows go into import_row (progress on the job), then
 *              check() stores a status and a plain-language message per row.
 *              Nothing else is written.
 *   3 Import   confirm(): checks again (a stale check can't slip through) and
 *              writes every row that passed, in one transaction.
 */
final class ImportEngine
{
    /**
     * @throws ImportRefused when the file is not an .xlsx file
     */
    public function upload(ImportType $type, UploadedFile $file, User $user): ImportJob
    {
        if (! $type->allows($user)) {
            throw new AuthorizationException;
        }

        if (mb_strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            throw new ImportRefused(__('imports.errors.not_xlsx'));
        }

        $disk = (string) config('imports.disk');
        $path = $file->store(config('imports.folder').'/'.now()->format('Y/m'), $disk);

        $job = ImportJob::create([
            'job_type' => $type->code(),
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'status' => 'UPLOADED',
            'created_by' => $user->id,
            'disk' => $disk,
            'path' => $path,
        ]);

        ReadImportFile::dispatch($job->id);

        return $job->refresh();
    }

    /**
     * Reads the file into import_row and checks the rows. A file that can't
     * be used (unreadable, not the template, too many rows) leaves the job
     * FAILED with the reason, in the uploader's language. Row messages are
     * stored as keys and shown in each viewer's language (RowMessages).
     */
    public function read(ImportJob $job): void
    {
        $type = Imports::forCode($job->job_type);
        $locale = app()->getLocale();
        app()->setLocale($job->creator->preferred_lang ?: (string) config('app.locale'));

        try {
            [$headers, $rows] = $this->sheet($job, $type);

            $job->update(['headers' => $headers, 'total_rows' => count($rows), 'processed_rows' => 0]);
            ImportRow::where('job_id', $job->id)->delete();

            foreach (array_chunk($rows, (int) config('imports.chunk'), true) as $chunk) {
                ImportRow::insert(array_map(fn (array $row, int $rowNo) => [
                    'job_id' => $job->id,
                    'row_no' => $rowNo,
                    'payload' => json_encode($row, JSON_UNESCAPED_UNICODE),
                    'status' => 'PENDING',
                ], $chunk, array_keys($chunk)));

                $job->increment('processed_rows', count($chunk));
            }

            $this->check($job->refresh(), $job->creator);
        } catch (ImportRefused $e) {
            $job->update(['status' => 'FAILED', 'error_message' => $e->getMessage()]);
        } finally {
            app()->setLocale($locale);
        }
    }

    /**
     * Checks every row again and stores status + message; the job becomes
     * VALIDATED. Used after reading, by "Check again", and before importing.
     */
    public function check(ImportJob $job, User $user): void
    {
        $type = Imports::forCode($job->job_type);
        $rows = $job->rows()->whereIn('status', ['PENDING', 'VALID', 'WARNING', 'ERROR'])->orderBy('row_no')->get();
        $checks = $type->check($rows, $user);

        DB::transaction(function () use ($job, $type, $rows, $checks) {
            foreach ($rows->chunk(500) as $chunk) {
                $values = [];
                $bindings = [];

                foreach ($chunk as $row) {
                    $values[] = '(?::bigint, ?, ?)';
                    array_push($bindings, $row->id, $checks[$row->id]->status(), $checks[$row->id]->message());
                    $row->status = $checks[$row->id]->status();
                    $row->message = $checks[$row->id]->message();
                }

                DB::update('UPDATE import_row SET status = v.status, message = v.message FROM (VALUES '.implode(', ', $values).') AS v(id, status, message) WHERE import_row.id = v.id', $bindings);
            }

            $job->update(['status' => 'VALIDATED', 'error_message' => null, 'warehouse_ids' => $type->warehouseIds($rows)]);
        });
    }

    /**
     * Rows per status: ready (VALID), warnings, errors, imported.
     *
     * @return array{ready: int, warnings: int, errors: int, imported: int}
     */
    public function counts(ImportJob $job): array
    {
        $counts = DB::table('import_row')->where('job_id', $job->id)->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status');

        return [
            'ready' => (int) ($counts['VALID'] ?? 0),
            'warnings' => (int) ($counts['WARNING'] ?? 0),
            'errors' => (int) ($counts['ERROR'] ?? 0),
            'imported' => (int) ($counts['IMPORTED'] ?? 0),
        ];
    }

    /**
     * Imports every row that passes a fresh check, in one transaction; rows
     * with errors are left out. Nothing is written when anything fails.
     *
     * @return array<string, mixed> what was created
     *
     * @throws ImportRefused
     */
    public function confirm(ImportJob $job, User $user): array
    {
        $type = Imports::forCode($job->job_type);

        if (! $type->allows($user)) {
            throw new AuthorizationException;
        }

        try {
            return $this->write($job, $type, $user);
        } catch (ImportRefused $e) {
            // The refused attempt was rolled back, its fresh check with it:
            // store the check again so the rows say why.
            $fresh = $job->fresh();

            if ($fresh !== null && $fresh->status === 'VALIDATED') {
                $this->check($fresh, $user);
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function write(ImportJob $job, ImportType $type, User $user): array
    {
        try {
            return DB::transaction(function () use ($job, $type, $user) {
                // A second "Import" waits here, then finds the job imported.
                $locked = ImportJob::whereKey($job->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === 'CONFIRMED') {
                    throw new ImportRefused(__('imports.errors.already_imported'));
                }

                if ($locked->status !== 'VALIDATED') {
                    throw new ImportRefused(__('imports.errors.not_ready'));
                }

                $this->check($locked, $user);
                $rows = $locked->rows()->orderBy('row_no')->get();
                $importable = $rows->whereIn('status', ['VALID', 'WARNING']);

                if ($importable->isEmpty()) {
                    throw new ImportRefused(__('imports.errors.nothing_to_import'));
                }

                $result = $type->write($rows, $locked, $user) + ['rows' => $importable->count(), 'skipped' => $rows->where('status', 'ERROR')->count()];

                ImportRow::where('job_id', $locked->id)->whereIn('status', ['VALID', 'WARNING'])->update(['status' => 'IMPORTED']);
                $locked->update(['status' => 'CONFIRMED', 'confirmed_at' => now(), 'confirmed_by' => $user->id, 'result' => $result]);

                Audit::record('import_job', $locked->id, 'IMPORT', null, ['file' => $locked->file_name, 'type' => $locked->job_type] + $result, $user->id);

                return $result;
            });
        } catch (StockException $e) {
            // A line refused while posting (e.g. stock that changed meanwhile).
            $row = $e->row !== null && str_starts_with($e->row, 'row') ? (int) substr($e->row, 3) : null;

            throw new ImportRefused($row === null ? $e->getMessage() : __('imports.errors.row', ['row' => $row, 'message' => $e->getMessage()]));
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'duplicate key')) {
                throw new ImportRefused(__('imports.errors.changed_meanwhile'));
            }

            throw $e;
        }
    }

    /**
     * Import jobs the user may see: kinds they may import; opening balances
     * only when all the file's warehouses are theirs (or they uploaded it).
     *
     * @return Builder<ImportJob>
     */
    public function visibleTo(User $user): Builder
    {
        $codes = array_values(array_map(fn (ImportType $type) => $type->code(), Imports::allowedFor($user)));

        return ImportJob::query()
            ->whereIn('job_type', $codes)
            ->where(fn (Builder $query) => $query
                ->where('job_type', '<>', 'OPENING_BALANCE')
                ->orWhere('created_by', $user->id)
                ->orWhereRaw('warehouse_ids <@ ?::jsonb', [json_encode($user->warehouseIds())]));
    }

    /**
     * The error report: the problem rows as uploaded, plus a Message column.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    public function problemRows(ImportJob $job): array
    {
        $headers = [...($job->headers ?? []), __('imports.check.message')];
        $width = count($job->headers ?? []);

        $rows = $job->rows()->whereIn('status', ImportRow::PROBLEMS)->orderBy('row_no')->get()
            ->map(fn (ImportRow $row) => [...array_pad($row->payload['cells'], $width, ''), $row->text()])
            ->all();

        return [$headers, $rows];
    }

    /**
     * The first sheet's header and data rows: cells as text (in column order,
     * for the error report) and values by column key. Blank rows are skipped;
     * rows are keyed by their spreadsheet row number.
     *
     * @return array{0: list<string>, 1: array<int, array{cells: list<string>, values: array<string, string>}>}
     */
    private function sheet(ImportJob $job, ImportType $type): array
    {
        [$file, $temporary] = $this->localFile($job);

        try {
            $sheet = IOFactory::createReader('Xlsx')->setReadDataOnly(true)->load($file)->getSheet(0);
        } catch (Throwable) {
            throw new ImportRefused(__('imports.errors.unreadable'));
        } finally {
            if ($temporary) {
                @unlink($file);
            }
        }

        $lastColumn = $sheet->getHighestDataColumn();
        $lastRow = $sheet->getHighestDataRow();
        $headers = array_map(fn ($cell) => $this->text($cell), $sheet->rangeToArray("A1:{$lastColumn}1", null, true, false)[0]);

        // Which spreadsheet column holds which template column (headers in any language).
        $map = [];
        foreach ($type->columns() as $column) {
            foreach ($headers as $index => $header) {
                if (in_array(Column::normalize($header), $column->aliases(), true)) {
                    $map[$column->key] = $index;
                    break;
                }
            }
        }

        $missing = array_filter($type->columns(), fn (Column $column) => $column->required && ! isset($map[$column->key]));
        if ($missing !== []) {
            throw new ImportRefused(__('imports.errors.wrong_template', [
                'type' => $type->title(),
                'columns' => implode(', ', array_map(fn (Column $column) => $column->label(), $missing)),
            ]));
        }

        $dates = array_map(fn (Column $column) => $column->key, array_filter($type->columns(), fn (Column $column) => $column->kind === 'date'));
        $max = (int) config('imports.max_rows');
        $rows = [];

        if ($lastRow >= 2) {
            foreach ($sheet->rangeToArray("A2:{$lastColumn}{$lastRow}", null, true, false, true) as $rowNo => $cells) {
                $cells = array_values($cells);

                if (implode('', array_map(fn ($cell) => $this->text($cell), $cells)) === '') {
                    continue;
                }

                if (count($rows) >= $max) {
                    throw new ImportRefused(__('imports.errors.too_many_rows', ['max' => number_format($max)]));
                }

                $values = [];
                foreach ($map as $key => $index) {
                    $cell = $cells[$index] ?? null;
                    // Dates typed into a date cell arrive as Excel serial numbers.
                    $values[$key] = in_array($key, $dates, true) && is_numeric($cell) && $cell > 0
                        ? ExcelDate::excelToDateTimeObject((float) $cell)->format('Y-m-d')
                        : $this->text($cell);
                }

                $rows[(int) $rowNo] = ['cells' => array_map(fn ($cell) => $this->text($cell), $cells), 'values' => $values];
            }
        }

        if ($rows === []) {
            throw new ImportRefused(__('imports.errors.empty'));
        }

        return [$headers, $rows];
    }

    /**
     * A cell as text: numbers without a trailing ".0", TRUE/FALSE as Y/N.
     */
    private function text(mixed $cell): string
    {
        return match (true) {
            $cell === null => '',
            is_bool($cell) => $cell ? 'Y' : 'N',
            is_float($cell) && floor($cell) == $cell && abs($cell) < 1e15 => (string) (int) $cell,
            is_float($cell) => rtrim(rtrim(sprintf('%.6F', $cell), '0'), '.'),
            default => trim((string) $cell),
        };
    }

    /**
     * A path PhpSpreadsheet can open: the file itself on a local disk, or a
     * temporary copy from S3 / Supabase Storage.
     *
     * @return array{0: string, 1: bool} path, and whether it is a temporary copy
     */
    private function localFile(ImportJob $job): array
    {
        $disk = Storage::disk((string) $job->disk);

        if (config("filesystems.disks.{$job->disk}.driver") === 'local') {
            return [$disk->path((string) $job->path), false];
        }

        $copy = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        file_put_contents($copy, $disk->get((string) $job->path));

        return [$copy, true];
    }
}
