@php
    // Document numbers and serials are always left-to-right (CLAUDE.md "i18n and RTL").
    $ltrTitles = ['documents', 'serials'];
    $count = collect($this->groups)->sum(fn ($group) => count($group['rows']));
    $index = 0;
@endphp

{{--
    Top bar search (App\Support\Search\GlobalSearch).
    Tablet/desktop: a field in the top bar, results in a dropdown under it.
    Phone: a search button that opens a full-screen search panel.
    "/" or Ctrl+K puts the cursor in it from anywhere.
--}}
<div
    class="relative min-w-0 md:w-72 md:shrink xl:w-96"
    x-data="{
        open: false,
        panel: false,
        active: 0,
        start() {
            this.panel = true;
            this.open = true;
            this.$nextTick(() => this.$refs.input.focus());
        },
        close() {
            this.open = false;
            this.panel = false;
        },
    }"
    x-on:keydown.window="
        const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) || $event.target.isContentEditable;
        if ((($event.ctrlKey || $event.metaKey) && $event.key.toLowerCase() === 'k') || ($event.key === '/' && ! typing)) {
            $event.preventDefault();
            start();
        }
    "
    x-on:keydown.escape="close(); $refs.input.blur()"
    x-on:click.outside="close()"
    data-global-search
>
    {{-- Phone: opens the search panel. --}}
    <button
        type="button"
        x-on:click="start()"
        class="grid size-control shrink-0 place-items-center rounded-lg text-fg hover:bg-card-hover md:hidden"
    >
        <x-icon name="search" />
        <span class="sr-only">{{ __('search.open') }}</span>
    </button>

    <div
        class="max-md:hidden"
        x-bind:class="{
            'max-md:hidden': ! panel,
            'max-md:fixed max-md:inset-0 max-md:z-50 max-md:flex max-md:flex-col max-md:gap-3 max-md:bg-card max-md:p-card': panel,
        }"
    >
        <div class="flex items-center justify-between gap-3 md:hidden">
            <p class="text-h3 font-semibold text-fg">{{ __('search.title') }}</p>
            <button type="button" x-on:click="close()" class="grid size-control shrink-0 place-items-center rounded-lg text-muted hover:bg-card-hover hover:text-fg">
                <x-icon name="close" />
                <span class="sr-only">{{ __('feedback.close') }}</span>
            </button>
        </div>

        <div class="relative">
            <x-input-spinner target="search" />
            <input
                type="search"
                x-ref="input"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="global-search-results"
                x-bind:aria-expanded="open.toString()"
                aria-label="{{ __('search.label') }}"
                autocomplete="off"
                spellcheck="false"
                enterkeyhint="search"
                wire:model.live.debounce.300ms="search"
                x-on:focus="open = true"
                x-on:input="open = true; active = 0"
                x-on:keydown.down.prevent="open = true; active = Math.min(active + 1, {{ max($count - 1, 0) }})"
                x-on:keydown.up.prevent="active = Math.max(active - 1, 0)"
                x-on:keydown.enter.prevent="$refs['result-' + active]?.click()"
                placeholder="{{ __('search.placeholder') }}"
                class="h-control w-full rounded-lg border border-control bg-surface pe-3 ps-10 text-start text-body text-fg placeholder:text-subtle focus:border-brand focus:bg-card focus:outline-none focus:ring-2 focus:ring-brand/20 [&::-webkit-search-cancel-button]:hidden"
            >
        </div>

        <div
            x-cloak
            x-show="open"
            class="z-40 overflow-hidden rounded-lg border border-control bg-card shadow-pop md:absolute md:top-full md:mt-1 md:w-full md:min-w-96 md:max-w-[calc(100vw-2rem)] md:start-0 max-md:flex max-md:min-h-0 max-md:flex-1 max-md:flex-col"
        >
            <div
                id="global-search-results"
                role="listbox"
                aria-label="{{ __('search.label') }}"
                wire:loading.delay.class="opacity-50"
                wire:loading.attr="aria-busy"
                wire:target="search"
                class="overflow-y-auto py-1 transition-opacity max-md:min-h-0 max-md:flex-1 md:max-h-[min(32rem,calc(100vh-6rem))]"
            >
                @if (! $ready)
                    <p class="px-3 py-2.5 text-body text-muted">{{ __('search.hint') }}</p>
                @else
                    @forelse ($this->groups as $group)
                        <p class="bg-surface px-3 py-1.5 text-small font-semibold uppercase tracking-wide text-muted" role="presentation">{{ $group['label'] }}</p>
                        @foreach ($group['rows'] as $row)
                            <a
                                href="{{ $row['url'] }}"
                                wire:navigate
                                wire:key="result-{{ $group['key'] }}-{{ $loop->index }}"
                                role="option"
                                tabindex="-1"
                                x-ref="result-{{ $index }}"
                                x-bind:aria-selected="active === {{ $index }}"
                                x-bind:class="active === {{ $index }} ? 'bg-card-hover' : ''"
                                x-on:mouseenter="active = {{ $index }}"
                                x-on:click="close()"
                                class="flex min-h-control items-center gap-3 px-3 py-1.5 text-body max-md:min-h-14"
                            >
                                <x-icon :name="$row['icon']" :class="$row['tone']" />
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                                        @if (in_array($group['key'], $ltrTitles, true))
                                            <span dir="ltr" class="tabular font-semibold text-fg">{{ $row['title'] }}</span>
                                        @else
                                            <bdi class="min-w-0 break-words font-semibold text-fg">{{ $row['title'] }}</bdi>
                                        @endif
                                        @if ($row['code'])
                                            <span dir="ltr" class="tabular shrink-0 rounded bg-card-hover px-1.5 text-small font-semibold tracking-wide text-muted">{{ $row['code'] }}</span>
                                        @endif
                                    </span>
                                    @if ($row['meta'] !== '')
                                        <span class="truncate text-small text-muted">{{ $row['meta'] }}</span>
                                    @endif
                                </span>
                            </a>
                            @php($index++)
                        @endforeach
                    @empty
                        <p class="px-3 py-2.5 text-body text-muted">{{ __('search.no_results', ['text' => trim($search)]) }}</p>
                    @endforelse
                @endif
            </div>
        </div>
    </div>
</div>
