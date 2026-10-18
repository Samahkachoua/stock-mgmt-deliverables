<?php

namespace App\Livewire\Imports;

use App\Imports\ImportEngine;
use App\Imports\ImportRefused;
use App\Imports\Imports;
use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * Steps 2 and 3 of an import: while the file is read, its progress; then the
 * counters (ready / warnings / errors) with only the problem rows, the error
 * report, and "Import": nothing is written before that button.
 */
#[Layout('components.layouts.app')]
class ImportJobPage extends Component
{
    use WithoutUrlPagination, WithPagination;

    public const PER_PAGE = 25;

    #[Locked]
    public string $jobId = '';

    public ?string $problem = null;

    public function mount(string $job): void
    {
        $this->jobId = $this->job($job)->id;
    }

    /**
     * Checks every row again (e.g. after adding a missing customer), in the
     * viewer's language.
     */
    public function recheck(): void
    {
        $job = $this->job();

        if ($job->status === 'VALIDATED') {
            app(ImportEngine::class)->check($job, $this->user());
            $this->problem = null;
            $this->resetPage();
        }
    }

    public function import(): void
    {
        $job = $this->job();

        try {
            app(ImportEngine::class)->confirm($job, $this->user());
            $this->problem = null;
        } catch (ImportRefused $e) {
            $this->problem = $e->getMessage();
            Notify::error($e->getMessage(), on: $this);
        }
    }

    public function render(): View
    {
        $job = $this->job();
        $engine = app(ImportEngine::class);

        return view('livewire.imports.job', [
            'job' => $job,
            'import' => Imports::forCode($job->job_type),
            'counts' => $engine->counts($job),
            'problems' => $job->status === 'VALIDATED'
                ? $job->rows()->whereIn('status', ImportRow::PROBLEMS)->orderByRaw("status = 'WARNING'")->orderBy('row_no')->paginate(self::PER_PAGE)
                : null,
        ])->title(Imports::forCode($job->job_type)->title());
    }

    private function job(?string $id = null): ImportJob
    {
        return app(ImportEngine::class)->visibleTo($this->user())->with(['creator', 'confirmer'])->findOrFail($id ?? $this->jobId);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
