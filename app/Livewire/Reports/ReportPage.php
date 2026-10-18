<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\DefersRows;
use App\Livewire\Concerns\RemembersListState;
use App\Models\User;
use App\Reports\Export\Exporter;
use App\Reports\Report;
use App\Reports\Reports;
use App\Support\Notify;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page shared by every report (the add-report skill): filters at the top,
 * the rows below, Excel and PDF export of exactly those rows. Filters and page
 * are kept in the session per report (CLAUDE.md "List state"), never in the
 * URL; export() downloads from the same filters.
 */
#[Layout('components.layouts.app')]
class ReportPage extends Component
{
    use DefersRows, RemembersListState, WithoutUrlPagination, WithPagination;

    /** Report key, from the route. */
    #[Locked]
    public string $report = '';

    /** @var array<string, string> filter name => value (normalized) */
    public array $filters = [];

    public function mount(string $report): void
    {
        $definition = Reports::get($report);
        Gate::authorize($definition->permission());

        $this->report = $definition->key();
        $this->filters = $definition->normalize([], $this->user());
    }

    public function updatedFilters(): void
    {
        // Values are normalized again: a new customer clears an item that isn't theirs.
        $this->filters = $this->definition()->normalize($this->filters, $this->user());
        $this->resetPage();

        if ($this->definition()->dates($this->filters)->invalid()) {
            Notify::error(__('feedback.date_order'), on: $this);
        }
    }

    public function resetFilters(): void
    {
        $this->clearListState();
        $this->filters = $this->definition()->normalize([], $this->user());
    }

    /**
     * Excel or PDF of the rows the page shows (its current filters).
     */
    public function export(string $format): ?Response
    {
        $report = $this->definition();
        Gate::authorize($report->permission());

        if (! in_array($format, Exporter::FORMATS, true)) {
            return null;
        }

        $user = $this->user();
        $filters = $report->normalize($this->filters, $user);

        if ($report->dates($filters)->invalid()) {
            Notify::error(__('feedback.date_order'), on: $this);

            return null;
        }

        if ($report->missing($filters) !== null) {
            return null;
        }

        $exporter = app(Exporter::class);

        return $format === 'xlsx'
            ? $exporter->xlsx($report, $filters, $user)
            : $exporter->pdf($report, $filters, $user);
    }

    public function render(): View
    {
        $report = $this->definition();
        $user = $this->user();
        // Dates the wrong way round: nothing is fetched, the two fields are outlined.
        $missing = $report->blocked($this->filters);

        return view($this->view(), [
            'definition' => $report,
            'choices' => collect($report->filters())->mapWithKeys(fn ($filter) => [$filter->name => $filter->choices($user, $this->filters)])->all(),
            'rows' => $this->rowsOnceLoaded(fn () => $this->pageInRange(fn (int $page) => $report->paginate($this->filters, $user, $page))),
            'summary' => $missing === null ? $report->summary($this->filters, $user) : [],
            'missing' => $missing,
            'dateError' => $report->dates($this->filters)->invalid(),
            // What a cleared report has: the filters bar counts the filters that differ.
            'defaults' => $report->normalize([], $user),
            'exportable' => $missing === null,
        ])->title($report->title());
    }

    protected function listStateKey(): string
    {
        return 'reports.'.$this->report;
    }

    protected function listStateProperties(): array
    {
        return ['filters'];
    }

    protected function restoredListState(): void
    {
        // A remembered customer, item or warehouse may no longer be allowed.
        $this->filters = $this->definition()->normalize($this->filters, $this->user());
    }

    /**
     * The Blade view of the page (the Stock page has its own design).
     */
    protected function view(): string
    {
        return 'livewire.reports.page';
    }

    protected function definition(): Report
    {
        return Reports::get($this->report);
    }

    protected function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
