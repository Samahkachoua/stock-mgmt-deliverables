<?php

namespace App\Support;

use Livewire\Component;

/**
 * The one way to tell the user what happened (CLAUDE.md "Feedback messages"):
 * a toast, drawn by the x-toasts component of the layout.
 *
 *   Notify::success(__('feedback.saved', [...]), $url);         // after a redirect (session flash)
 *   Notify::error($e->getMessage(), on: $this);                 // on the page that is open (Livewire)
 *
 * Success (green) and warning (amber, e.g. "sent for approval") close after
 * 4 s; errors (red) stay until closed. Messages are already translated.
 *
 * @phpstan-type Toast array{type: 'success'|'warning'|'error', message: string, url: string|null, link: string|null}
 */
final class Notify
{
    /** Session flash key of the toasts for the next page. */
    public const SESSION_KEY = 'toasts';

    /** Browser event a Livewire component sends a toast with. */
    public const EVENT = 'toast';

    /**
     * @param  string|null  $url  where "View" leads (e.g. the document), if anywhere
     * @param  Component|null  $on  the Livewire component whose page stays open; null = the next page
     */
    public static function success(string $message, ?string $url = null, ?string $link = null, ?Component $on = null): void
    {
        self::send('success', $message, $url, $link, $on);
    }

    public static function warning(string $message, ?string $url = null, ?string $link = null, ?Component $on = null): void
    {
        self::send('warning', $message, $url, $link, $on);
    }

    public static function error(string $message, ?string $url = null, ?string $link = null, ?Component $on = null): void
    {
        self::send('error', $message, $url, $link, $on);
    }

    /**
     * Toasts flashed for the page being rendered.
     *
     * @return list<Toast>
     */
    public static function flashed(): array
    {
        return array_values((array) session(self::SESSION_KEY, []));
    }

    /**
     * @param  'success'|'warning'|'error'  $type
     */
    private static function send(string $type, string $message, ?string $url, ?string $link, ?Component $on): void
    {
        $toast = [
            'type' => $type,
            'message' => $message,
            'url' => $url,
            'link' => $url === null ? null : ($link ?? __('feedback.view')),
        ];

        if ($on !== null) {
            $on->dispatch(self::EVENT, toast: $toast);

            return;
        }

        // Several toasts in one request add up; toasts of an earlier request are not repeated.
        $pending = in_array(self::SESSION_KEY, (array) session('_flash.new', []), true) ? self::flashed() : [];

        session()->flash(self::SESSION_KEY, [...$pending, $toast]);
    }
}
