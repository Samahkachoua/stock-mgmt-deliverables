<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\Notifications\NotificationView;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The top bar's bell: the unread count and the latest notifications, checked
 * again every minute (no Realtime). Opening one marks it read and goes to its
 * record (NotificationController); "See all" opens the Notifications page.
 */
class NotificationBell extends Component
{
    /** How many the panel shows. */
    public const LATEST = 10;

    public function markAllRead(): void
    {
        DB::table('notification')->where('user_id', $this->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    #[On('notifications-read')]
    public function refresh(): void {}

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.notification-bell', [
            'unread' => NotificationView::unreadCount($user->id),
            'latest' => NotificationView::query($user->id)->limit(self::LATEST)->get()->map(fn (object $row) => NotificationView::present($row)),
        ]);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
