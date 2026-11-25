<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Reports\Export\Exporter;
use App\Reports\Export\ExportTable;
use App\Support\Format;
use App\Support\Notify;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;
use Livewire\Drawer\Utils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Excel / PDF export of a list (the toolbar's buttons, x-list-toolbar) and the
 * columns its viewer hid (the Columns menu, remembered in the browser and sent
 * with the next request). The export is the list's own table with every row the
 * filters match, read back from its HTML (ExportTable::fromHtml): what the
 * screen says, column for column, without the hidden columns.
 *
 * The component uses RemembersListState, WithPerPage and DefersRows, paginates
 * with perPage() and passes its paginator to the view as `rows`.
 */
trait ExportsList
{
    /** Most rows one export holds; more asks the user to narrow the filters. */
    public const EXPORT_MAX = 5000;

    /** @var list<string> column keys the viewer hid (Columns menu) */
    public array $hiddenColumns = [];

    private bool $exportingAll = false;

    /** The list's title, at the top of the file. */
    abstract protected function exportTitle(): string;

    /** The same check the list makes on opening: an export never shows more than the list. */
    abstract protected function authorizeExport(): void;

    /** Rows per page the viewer chose (WithPerPage). */
    abstract protected function rowsPerPage(): int;

    /**
     * Rows per page; every matching row (up to EXPORT_MAX + 1) while exporting.
     */
    protected function perPage(): int
    {
        return $this->exportingAll ? self::EXPORT_MAX + 1 : $this->rowsPerPage();
    }

    public function export(string $format): ?Response
    {
        $this->authorizeExport();

        if (! in_array($format, Exporter::FORMATS, true)) {
            return null;
        }

        $page = (int) $this->getPage();
        $loaded = $this->rowsLoaded;
        $this->exportingAll = true;
        $this->rowsLoaded = true;
        $this->setPage(1);

        try {
            $view = $this->render();
            // The list's paginator, whatever the view calls it (rows, items, jobs).
            $rows = collect($view->getData())->first(fn ($value) => $value instanceof LengthAwarePaginator);

            if ($rows instanceof LengthAwarePaginator && $rows->total() > self::EXPORT_MAX) {
                Notify::error(__('app.export.too_many', ['max' => Format::qty(self::EXPORT_MAX)]), on: $this);

                return null;
            }

            $html = $this->renderForExport($view);
        } finally {
            $this->exportingAll = false;
            $this->rowsLoaded = $loaded;
            $this->setPage($page);
        }

        $table = ExportTable::fromHtml($html, $this->exportTitle(), $this->listStateKey(), $this->hiddenColumns);
        $exporter = app(Exporter::class);
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $format === 'xlsx' ? $exporter->tableXlsx($table) : $exporter->tablePdf($table, $user);
    }

    /**
     * The list's view rendered the way Livewire renders it (public properties
     * and the component shared with the view).
     */
    private function renderForExport(View $view): string
    {
        $view = Utils::generateBladeView($view, Utils::getPublicPropertiesDefinedOnSubclass($this));
        $revert = Utils::shareWithViews('__livewire', $this);
        // As Livewire's validation feature does, for the views' @error.
        $revertErrors = Utils::shareWithViews('errors', (new ViewErrorBag)->put('default', $this->getErrorBag()));

        try {
            return $view->render();
        } finally {
            $revertErrors();
            $revert();
        }
    }
}
