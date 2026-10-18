<?php

namespace App\Reports\Export;

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Models\User;
use App\Reports\Report;
use App\Services\Stock\Availability;
use Carbon\CarbonImmutable;
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
     */
    public function xlsx(Report $report, array $filters, User $user): BinaryFileResponse
    {
        $columns = $report->columns();
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

        return Excel::download($sheet, $this->filename($report, 'xlsx'));
    }

    /**
     * @param  array<string, string>  $filters  normalized
     */
    public function pdf(Report $report, array $filters, User $user): StreamedResponse
    {
        $content = $this->pdfContent($report, $filters, $user);

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $this->filename($report, 'pdf'), ['Content-Type' => 'application/pdf']);
    }

    /**
     * The PDF file's bytes.
     *
     * @param  array<string, string>  $filters  normalized
     */
    public function pdfContent(Report $report, array $filters, User $user): string
    {
        $rtl = $this->rtl();
        $columns = $report->columns();

        $html = view('exports.report-pdf', [
            'report' => $report,
            'columns' => $columns,
            'rows' => $report->rows($filters, $user),
            'filters' => $report->describeFilters($filters, $user),
            'summary' => $report->summary($filters, $user),
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
        $mpdf->SetTitle($report->title());
        $mpdf->SetDirectionality($rtl ? 'rtl' : 'ltr');
        $mpdf->SetFooter('{PAGENO} / {nbpg}');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
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

    private function filename(Report $report, string $extension): string
    {
        return Str::slug($report->key()).'-'.now()->format('Y-m-d-His').'.'.$extension;
    }

    /**
     * Now in the current warehouse's time zone, like the dates in the rows.
     */
    private function now(): CarbonImmutable
    {
        $warehouse = (string) session(EnsureCurrentWarehouse::SESSION_KEY);

        return $warehouse !== '' ? CarbonImmutable::now(app(Availability::class)->today($warehouse)->getTimezone()) : CarbonImmutable::now();
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
