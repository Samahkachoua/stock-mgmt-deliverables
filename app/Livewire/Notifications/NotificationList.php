<?php

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\DefersRows;
use App\Livewire\Concerns\RemembersListState;
use App\Livewire\Concerns\WithPerPage;
use App\Models\User;
use App\Support\CompanyTime;
use App\Support\DateRange;
use App\Support\Notifications\NotificationView;
use App\Support\Notifications\Notifier;
use App\Support\Notify;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * The user's own notifications, newest first (kept 90 days): what waits for
 * them and what happened to their requests. Filters (search by reference,
 * group, read state, dates) and page stay in the session, never in the URL.
 */
#[Layout('components.layouts.app')]
class NotificationList extends Component
{
    use DefersRows, RemembersListState, WithoutUrlPagination, WithPagination, WithPerPage;

    public const PER_PAGE = 15;

    /** Read states of the status filter. */
    public const STATES = ['unread', 'read', 'closed'];

    public string $search = '';

    public string $group = '';

    public string $status = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    /**
     * Columns hidden with "Columns" (x-list-columns sends them; hiding itself is CSS).
     *
     * @var list<string>
     */
    public array $hiddenColumns = [];

    public function updating(string $property): void
    {
        if (in_array($property, $this->listStateProperties(), true)) {
            $this->resetPage();
        }
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['dateFrom', 'dateTo'], true)) {
            return;
        }

        $this->{$property} = DateRange::day($this->{$property});

        if ($this->dates()->invalid()) {
            Notify::error(__('feedback.date_order'), on: $this);
        }
    }

    public function resetFilters(): void
    {
        $this->clearListState();
    }

    public function markAllRead(): void
    {
        DB::table('notification')->where('user_id', $this->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        $this->dispatch('notifications-read');
    }

    protected function listStateKey(): string
    {
        return 'notifications';
    }

    protected function listStateProperties(): array
    {
        return ['search', 'group', 'status', 'dateFrom', 'dateTo'];
    }

    protected function restoredListState(): void
    {
        $this->dateFrom = DateRange::day($this->dateFrom);
        $this->dateTo = DateRange::day($this->dateTo);
        $this->group = in_array($this->group, NotificationView::GROUPS, true) ? $this->group : '';
        $this->status = in_array($this->status, self::STATES, true) ? $this->status : '';
    }

    public function render(): View
    {
        $user = $this->user();
        $dates = $this->dates();

        return view('livewire.notifications.list', [
            'rows' => $this->rowsOnceLoaded(fn () => $this->pageInRange(fn () => NotificationView::query($user->id)
                ->when(trim($this->search) !== '', fn ($query) => $query->where('n.params->reference', 'ilike', '%'.addcslashes(trim($this->search), '%_\\').'%'))
                ->when($this->group === 'approvals', fn ($query) => $query->where('n.event', 'like', 'approval.%'))
                ->when($this->group === 'orders', fn ($query) => $query->where('n.event', 'like', 'draft.%')->where('n.params->type', 'OUT'))
                ->when($this->group === 'draft_receipts', fn ($query) => $query->where('n.event', 'like', 'draft.%')->where('n.params->type', 'IN'))
                ->when($this->status === 'unread', fn ($query) => $query->whereNull('n.read_at')->whereNull('n.resolved_at'))
                ->when($this->status === 'read', fn ($query) => $query->whereNotNull('n.read_at')->whereNull('n.resolved_at'))
                ->when($this->status === 'closed', fn ($query) => $query->whereNotNull('n.resolved_at'))
                ->when(! $dates->invalid(), fn ($query) => $dates->whereInstant($query, 'n.updated_at', CompanyTime::zone()))
                ->paginate($this->rowsPerPage())
                ->through(fn (object $row) => NotificationView::present($row)))),
            'groups' => collect(NotificationView::GROUPS)->mapWithKeys(fn (string $group) => [$group => __('notifications.groups.'.$group)])->all(),
            'statuses' => collect(self::STATES)->mapWithKeys(fn (string $state) => [$state => __('notifications.states.'.$state)])->all(),
            'dateError' => $dates->invalid(),
            'unread' => NotificationView::unreadCount($user->id),
            'keepDays' => Notifier::KEEP_DAYS,
        ])->title(__('notifications.title'));
    }

    private function dates(): DateRange
    {
        return new DateRange($this->dateFrom, $this->dateTo);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
