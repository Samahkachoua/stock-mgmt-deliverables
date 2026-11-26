{{--
    The top bar's bell: unread count, the latest notifications, "Mark all as read" and "See all".
    Checked again every minute (wire:poll; no Realtime). Unread rows in bold with a dot;
    closed ones (someone else approved / confirmed it) greyed with the outcome.
--}}
<div class="relative" wire:poll.60s x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false" data-notification-bell>
    <button
        type="button"
        class="relative grid size-control place-items-center rounded-lg border border-default bg-card text-fg transition-colors hover:bg-card-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
        :aria-expanded="open.toString()"
        aria-haspopup="true"
        aria-label="{{ $unread > 0 ? trans_choice('notifications.bell_unread', $unread, ['count' => $unread]) : __('notifications.title') }}"
        title="{{ __('notifications.title') }}"
        @click="open = ! open"
    >
        <x-icon name="notifications" class="size-5" />
        @if ($unread > 0)
            <span class="absolute -end-1.5 -top-1.5 grid h-5 min-w-5 place-items-center rounded-full bg-status-rejected-solid px-1 text-small font-bold leading-none text-on-status tabular" data-unread-count>
                {{ $unread > 9 ? '9+' : $unread }}
            </span>
        @endif
    </button>

    <div
        x-cloak
        x-show="open"
        x-transition.opacity
        class="fixed inset-x-3 top-topbar z-50 mt-1 flex max-h-[80vh] flex-col rounded-xl border border-default bg-card shadow-pop md:absolute md:inset-x-auto md:end-0 md:top-full md:mt-2 md:w-96"
    >
        <div class="flex items-center justify-between gap-3 border-b border-default px-4 py-3">
            <h2 class="text-body font-semibold text-fg">{{ __('notifications.title') }}</h2>
            @if ($unread > 0)
                <button type="button" wire:click="markAllRead" class="text-small font-semibold text-link hover:underline">
                    {{ __('notifications.mark_all_read') }}
                </button>
            @endif
        </div>

        @if ($latest->isEmpty())
            <p class="p-card text-center text-body text-muted">{{ __('notifications.empty_short') }}</p>
        @else
            <ul class="min-h-0 flex-1 divide-y divide-default overflow-y-auto">
                @foreach ($latest as $item)
                    <li wire:key="bell-{{ $item['id'] }}">
                        <a href="{{ $item['url'] }}" @class(['flex gap-3 px-4 py-3 hover:bg-card-hover', 'opacity-70' => $item['resolved']])>
                            <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-brand' => $item['unread'], 'bg-transparent' => ! $item['unread']]) aria-hidden="true"></span>
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span @class(['text-body text-fg', 'font-semibold' => $item['unread']])>
                                    @if ($item['unread'])<span class="sr-only">{{ __('notifications.states.unread') }}: </span>@endif
                                    {{ $item['message'] }}
                                </span>
                                @if ($item['reason'])
                                    <span class="text-small text-muted">{{ __('notifications.reason') }}: {{ $item['reason'] }}</span>
                                @endif
                                @if ($item['outcome'])
                                    <span class="text-small text-muted">{{ $item['outcome'] }}</span>
                                @endif
                                <span class="text-small text-muted">{{ $item['when'] }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        <a href="{{ route('notifications') }}" wire:navigate class="flex h-control items-center justify-center border-t border-default text-body font-semibold text-link hover:bg-card-hover">
            {{ __('notifications.see_all') }}
        </a>
    </div>
</div>
