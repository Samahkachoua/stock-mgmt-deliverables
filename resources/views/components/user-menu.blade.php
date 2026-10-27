@php($user = auth()->user())

<div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
    <button
        type="button"
        class="flex h-control items-center gap-3 rounded-lg px-1 hover:bg-card-hover"
        :aria-expanded="open.toString()"
        aria-haspopup="menu"
        aria-label="{{ __('app.user_menu') }}"
        @click="open = ! open"
    >
        <span class="hidden flex-col text-end leading-tight md:flex">
            <span class="text-body font-semibold text-fg">{{ $user->full_name }}</span>
        </span>
        <span class="grid size-9 place-items-center rounded-full bg-brand text-on-brand">
            <x-icon name="person" class="size-5" />
        </span>
    </button>

    <div
        x-cloak
        x-show="open"
        x-transition.opacity
        role="menu"
        class="absolute end-0 top-full z-50 mt-2 w-64 max-w-[calc(100vw-1.5rem)] rounded-xl border border-default bg-card p-2 shadow-pop"
    >
        <div class="border-b border-default px-3 pb-3 pt-1">
            <p class="text-body font-semibold text-fg">{{ $user->full_name }}</p>
            <p class="truncate text-small text-muted" dir="ltr">{{ $user->email }}</p>
        </div>

        {{-- Phone: theme and language are here, not in the top bar. --}}
        <div class="flex flex-col gap-3 border-b border-default px-3 py-3 md:hidden" data-menu-settings>
            <div class="flex items-center justify-between gap-3">
                <span class="text-body font-semibold text-fg">{{ __('app.theme') }}</span>
                <livewire:theme-switcher key="menu-theme" />
            </div>
            <div class="flex items-center justify-between gap-3">
                <span class="text-body font-semibold text-fg">{{ __('app.language') }}</span>
                <x-language-switch />
            </div>
        </div>

        <a href="{{ route('account.password') }}" wire:navigate role="menuitem" class="mt-2 flex h-control w-full items-center gap-3 rounded-lg px-3 text-body font-semibold text-fg hover:bg-card-hover">
            <x-icon name="lock" class="size-5 text-muted" />
            {{ __('account.password.menu') }}
        </a>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" role="menuitem" class="flex h-control w-full items-center gap-3 rounded-lg px-3 text-body font-semibold text-fg hover:bg-card-hover">
                <x-icon name="logout" class="size-5 text-muted rtl:-scale-x-100" />
                {{ __('app.sign_out') }}
            </button>
        </form>
    </div>
</div>
