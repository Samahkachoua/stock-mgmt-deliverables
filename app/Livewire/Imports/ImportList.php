<?php

namespace App\Livewire\Imports;

use App\Imports\ImportEngine;
use App\Imports\Imports;
use App\Livewire\Concerns\DefersRows;
use App\Livewire\Concerns\RemembersListState;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * Import from Excel: start a new import, and past imports with who uploaded,
 * when, and the result. The page is kept in the session, never in the URL.
 */
#[Layout('components.layouts.app')]
class ImportList extends Component
{
    use DefersRows, RemembersListState, WithoutUrlPagination, WithPagination;

    public const PER_PAGE = 15;

    public function mount(): void
    {
        abort_if(Imports::allowedFor($this->user()) === [], 403);
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.imports.list', [
            'types' => Imports::allowedFor($user),
            'jobs' => $this->rowsOnceLoaded(fn () => $this->pageInRange(fn () => app(ImportEngine::class)->visibleTo($user)
                ->with(['creator', 'confirmer'])
                ->withCount([
                    'rows as error_rows' => fn ($query) => $query->where('status', 'ERROR'),
                    'rows as imported_rows' => fn ($query) => $query->where('status', 'IMPORTED'),
                ])
                ->orderByDesc('created_at')
                ->paginate(self::PER_PAGE))),
        ])->title(__('imports.title'));
    }

    protected function listStateKey(): string
    {
        return 'imports';
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
