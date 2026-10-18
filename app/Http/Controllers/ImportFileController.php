<?php

namespace App\Http\Controllers;

use App\Imports\ImportEngine;
use App\Imports\Imports;
use App\Imports\TemplateExport;
use App\Models\User;
use App\Reports\Export\ReportSheet;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportFileController extends Controller
{
    /**
     * "Download template": the import's columns in the user's language, with
     * the codes to use on a second sheet.
     */
    public function template(Request $request, string $type): BinaryFileResponse
    {
        $import = Imports::get($type);
        abort_unless($import->allows($this->user($request)), 403);

        return Excel::download(new TemplateExport($import, $this->user($request)), Str::slug($import->title()).'-template.xlsx');
    }

    /**
     * "Download error report": the rows with errors or warnings as uploaded,
     * plus a Message column, to fix and upload again.
     */
    public function errors(Request $request, ImportEngine $engine, string $job): BinaryFileResponse
    {
        $import = $engine->visibleTo($this->user($request))->findOrFail($job);
        [$headers, $rows] = $engine->problemRows($import);

        return Excel::download(
            new ReportSheet(__('imports.check.error_report'), $headers, $rows, app()->getLocale() === 'ar'),
            pathinfo($import->file_name, PATHINFO_FILENAME).'-'.Str::slug(__('imports.check.error_report')).'.xlsx',
        );
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
