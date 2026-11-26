<?php

namespace App\Http\Controllers;

use App\Support\Notifications\NotificationView;
use App\Support\Notify;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    /**
     * Opens a notification (from the bell, the Notifications page or an
     * email): marks it read and goes to its record. Someone who can no longer
     * open the record is told so on the Notifications page instead of an
     * error page.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();
        $row = DB::table('notification')->where('id', $notification)->where('user_id', $user->id)->first();

        abort_if($row === null, 404);

        if ($row->read_at === null) {
            DB::table('notification')->where('id', $row->id)->update(['read_at' => now()]);
        }

        $target = NotificationView::target($row, $user);

        if ($target === null) {
            Notify::error(__('notifications.no_access'));

            return redirect()->route('notifications');
        }

        return redirect()->to($target);
    }
}
