@use('App\Services\Stock\UnitConverter')

{{-- Phone: while it is open the picker is a full-screen search panel (CLAUDE.md "Responsive layout"). --}}
<div
    @class([
        'relative',
        'max-md:fixed max-md:inset-0 max-md:z-50 max-md:flex max-md:flex-col max-md:gap-3 max-md:bg-card max-md:p-card' => $open && $customer !== '',
    ])
    @if ($open && $customer !== '') data-picker-panel @endif
    x-ref="field"
    x-data="{ active: 0 }"
    x-on:keydown.escape.prevent.stop="$wire.close()"
    x-on:keydown.tab="if ($wire.open) $wire.close()"
    x-on:click.outside="if ($wire.open) $wire.close()"
>
    @if ($open && $customer !== '')
        <div class="flex items-center justify-between gap-3 md:hidden">
            <p class="text-h3 font-semibold text-fg">{{ __('transactions.picker.title') }}</p>
            <button type="button" wire:click="close" class="grid size-control shrink-0 place-items-center rounded-lg text-muted hover:bg-card-hover hover:text-fg">
                <x-icon name="close" />
                <span class="sr-only">{{ __('feedback.close') }}</span>
            </button>
        </div>
    @endif

    @if ($this->chosen && ! $open)
        <button
            type="button"
            id="{{ $inputId }}"
            wire:click="change"
            @disabled($customer === '')
            class="flex min-h-control w-full items-center justify-between gap-2 rounded-lg border border-control bg-card px-3 py-1 text-start text-body text-fg hover:bg-card-hover focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
        >
            {{-- The whole name, on as many lines as it needs: never cut off. --}}
            <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                <bdi class="min-w-0 break-words font-semibold">{{ $this->chosen['name'] }}</bdi>
                <span dir="ltr" class="tabular shrink-0 rounded bg-card-hover px-1.5 text-small font-semibold tracking-wide text-muted">{{ $this->chosen['code'] }}</span>
                <x-storage-status :label="$this->chosen['storage']" class="shrink-0" />
            </span>
            <x-busy-icon name="keyboard_arrow_down" target="change" class="text-muted" />
        </button>
    @else
        <div class="relative">
            <x-input-spinner target="search" />
            <input
                type="text"
                id="{{ $inputId }}"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="{{ $inputId }}-options"
                aria-expanded="{{ $open ? 'true' : 'false' }}"
                autocomplete="off"
                wire:model.live.debounce.300ms="search"
                wire:focus="$set('open', true)"
                x-on:keydown.down.prevent="active = Math.min(active + 1, {{ max(count($this->results) - 1, 0) }})"
                x-on:keydown.up.prevent="active = Math.max(active - 1, 0)"
                x-on:keydown.enter.prevent="$refs['option-' + active]?.click()"
                x-on:input="active = 0"
                @disabled($customer === '')
                @if ($invalid) aria-invalid="true" @endif
                placeholder="{{ $customer === '' ? __('transactions.picker.choose_customer_first') : __('transactions.picker.placeholder') }}"
                @class([
                    'h-control w-full rounded-lg border bg-card pe-3 ps-10 text-start text-body text-fg placeholder:text-subtle focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-surface',
                    'border-danger focus:ring-danger/20' => $invalid,
                    'border-control focus:border-brand focus:ring-brand/20' => ! $invalid,
                ])
            >
        </div>
    @endif

    @if ($open && $customer !== '')
        {{-- Fixed + anchored, so the lines table's horizontal scroll never clips it.
             At least as wide as the field, and wider when the names need it. --}}
        <div
            x-anchor.fixed.bottom-start.offset.4="$refs.field"
            x-bind:style="{ minWidth: $refs.field.offsetWidth + 'px' }"
            class="z-40 w-max max-w-[min(32rem,calc(100vw-2rem))] overflow-hidden rounded-lg border border-control bg-card shadow-pop max-md:static! max-md:flex max-md:w-full! max-md:min-w-0! max-md:max-w-none max-md:min-h-0 max-md:flex-1 max-md:flex-col"
        >
            <p class="bg-surface px-3 py-1.5 text-small font-semibold uppercase tracking-wide text-muted">
                {{ __('transactions.picker.catalog', ['customer' => $customerName]) }}
            </p>
            <ul id="{{ $inputId }}-options" role="listbox" wire:loading.delay.class="opacity-50 pointer-events-none" wire:target="choose" class="overflow-y-auto py-1 transition-opacity max-md:min-h-0 max-md:flex-1 md:max-h-72">
                @forelse ($this->results as $i => $item)
                    <li
                        wire:key="option-{{ $item['id'] }}"
                        role="option"
                        tabindex="-1"
                        x-ref="option-{{ $i }}"
                        x-bind:aria-selected="active === {{ $i }}"
                        x-bind:class="active === {{ $i }} ? 'bg-card-hover' : ''"
                        x-on:mouseenter="active = {{ $i }}"
                        wire:click="choose('{{ $item['id'] }}')"
                        class="flex min-h-control cursor-pointer flex-col justify-center gap-0.5 px-3 py-1.5 text-body max-md:min-h-14"
                    >
                        <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                            <bdi class="min-w-0 break-words font-semibold text-fg">{{ $item['name'] }}</bdi>
                            <span dir="ltr" class="tabular shrink-0 rounded bg-card-hover px-1.5 text-small font-semibold tracking-wide text-muted">{{ $item['code'] }}</span>
                        </span>
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-small text-muted">
                            <x-storage-status :label="$item['storage']" />
                            {{ __('transactions.picker.in_stock', ['qty' => (new UnitConverter($item['units'], $item['base']))->breakdown($item['available'])]) }}
                        </span>
                    </li>
                @empty
                    <li class="px-3 py-2.5 text-body text-muted">{{ __('transactions.picker.no_results') }}</li>
                @endforelse
            </ul>
        </div>
    @endif
</div>
