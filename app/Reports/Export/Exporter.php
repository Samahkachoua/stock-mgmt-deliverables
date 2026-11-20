<?php

namespace App\Reports\Export;

use App\Models\User;
use App\Reports\Report;
use App\Services\Stock\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one export utility for every report (the add-report skill): Excel through
 * maatwebsite/excel, PDF through mPDF. Both take the report's rows() with the
 * same filters as the screen, so an export holds exactly the rows the screen
 * counts.
 *
 * PDFs are always light (black on white) and use DejaVu Sans, which covers
 * Latin, French and Arabic; mPDF joins Arabic letters (presentation forms)
 * and lays Arabic pages out right to left.
 */
final class Exporter
{
    public const FORMATS = ['xlsx', 'pdf'];

    /** Font for PDFs: shipped with mPDF, Arabic and Latin. */
    public const PDF_FONT = 'dejavusans';

    /**
     * @param  array<string, string>  $filters  normalized
     * @param  list<string>  $hidden  columns the viewer hid (Columns menu): left out
     */
    public function xlsx(Report $report, array $filters, User $user, array $hidden = []): BinaryFileResponse
    {
        $columns = $this->visible($report->columns(), $hidden);
        $rows = $report->rows($filters, $user)
            ->map(fn (array $cells) => array_map(fn (string $key) => $this->excelValue($cells[$key] ?? ''), array_keys($columns)))
            ->values()
            ->all();

        $sheet = new ReportSheet(
            $report->title(),
            array_map(fn (array $column) => __($column['label']), array_values($columns)),
            $rows,
            $this->rtl(),
        );

        return Excel::download($sheet, $this->filename($report->key(), 'xlsx'));
    }

    /**
     * @param  array<string, string>  $filters  normalized
     * @param  list<string>  $hidden  columns the viewer hid (Columns menu): left out
     */
    public function pdf(Report $report, array $filters, User $user, array $hidden = []): StreamedResponse
    {
        return $this->streamPdf($this->pdfContent($report, $filters, $user, $hidden), $report->key());
    }

    /**
     * The PDF file's bytes.
     *
     * @param  array<string, string>  $filters  normalized
     * @param  list<string>  $hidden
     */
    public function pdfContent(Report $report, array $filters, User $user, array $hidden = []): string
    {
        return $this->renderPdf(
            $report->title(),
            $this->visible($report->columns(), $hidden),
            $report->rows($filters, $user),
            $report->describeFilters($filters, $user),
            $report->summary($filters, $user),
            $user,
        );
    }

    /**
     * A list (ExportTable, read from the list's own table) as an Excel sheet.
     */
    public function tableXlsx(ExportTable $table): BinaryFileResponse
    {
        $keys = array_keys($table->columns);

        $sheet = new ReportSheet(
            $table->title,
            array_column(array_values($table->columns), 'label'),
            array_map(fn (array $cells) => array_map(fn (string $key) => $cells[$key] ?? '', $keys), $table->rows),
            $this->rtl(),
        );

        return Excel::download($sheet, $this->filename($table->slug, 'xlsx'));
    }

    /**
     * A list (ExportTable) as a PDF, laid out like a report's.
     */
    public function tablePdf(ExportTable $table, User $user): StreamedResponse
    {
        return $this->streamPdf(
            $this->renderPdf($table->title, $table->columns, collect($table->rows), $table->filters, [], $user),
            $table->slug,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $columns  labels are translation keys or text
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<string>  $filters
     * @param  list<array{label: string, value: string}>  $summary
     */
    private function renderPdf(string $title, array $columns, iterable $rows, array $filters, array $summary, User $user): string
    {
        $rtl = $this->rtl();

        $html = view('exports.report-pdf', [
            'title' => $title,
            'columns' => $columns,
            'rows' => collect($rows),
            'filters' => $filters,
            'summary' => $summary,
            'rtl' => $rtl,
            'generatedAt' => $this->now(),
            'by' => $user->full_name,
        ])->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => count($columns) > 7 ? 'A4-L' : 'A4',
            'default_font' => self::PDF_FONT,
            'tempDir' => $this->tempDir(),
            'margin_top' => 14,
            'margin_bottom' => 14,
            'margin_left' => 10,
            'margin_right' => 10,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetDirectionality($rtl ? 'rtl' : 'ltr');
        $mpdf->SetFooter('{PAGENO} / {nbpg}');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    private function streamPdf(string $content, string $slug): StreamedResponse
    {
        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $this->filename($slug, 'pdf'), ['Content-Type' => 'application/pdf']);
    }

    /**
     * @template T
     *
     * @param  array<string, T>  $columns
     * @param  list<string>  $hidden
     * @return array<string, T>
     */
    private function visible(array $columns, array $hidden): array
    {
        $visible = array_diff_key($columns, array_flip($hidden));

        // Never an empty file: with every column hidden, all of them.
        return $visible === [] ? $columns : $visible;
    }

    /**
     * @param  string|array{text: string, value?: float|int|string|null}  $cell
     */
    private function excelValue(string|array $cell): string|int|float|null
    {
        if (is_string($cell)) {
            return $cell;
        }

        return array_key_exists('value', $cell) ? $cell['value'] : $cell['text'];
    }

    private function filename(string $slug, string $extension): string
    {
        return Str::slug($slug).'-'.now()->format('Y-m-d-His').'.'.$extension;
    }

    /**
     * Now in the time zone of the user's default warehouse, like the dates in the rows.
     */
    private function now(): CarbonImmutable
    {
        $user = Auth::user();
        $warehouse = $user instanceof User ? $user->defaultWarehouseId() : null;

        return $warehouse !== null ? CarbonImmutable::now(app(Availability::class)->today($warehouse)->getTimezone()) : CarbonImmutable::now();
    }

    private function rtl(): bool
    {
        return app()->getLocale() === 'ar';
    }

    private function tempDir(): string
    {
        $dir = storage_path('framework/cache/mpdf');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
