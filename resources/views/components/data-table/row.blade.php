{{--
    A table row that opens something when clicked anywhere: a stretched link (href)
    or a stretched button calling a Livewire action (action="edit('...')").
    Neither (a read-only user): the row opens nothing.
--}}
@props(['label', 'href' => null, 'action' => null])

@php($target = 'inline-grid size-7 place-items-center rounded-md text-muted after:absolute after:inset-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40')

<tr {{ $attributes->class('relative h-row hover:bg-surface focus-within:bg-surface') }}>
    {{ $slot }}
    <td data-card="open" class="pe-edge text-end">
        @if ($href)
            <a href="{{ $href }}" wire:navigate class="{{ $target }}">
                <x-icon name="chevron_right" class="rtl:-scale-x-100" />
                <span class="sr-only">{{ $label }}</span>
            </a>
        @elseif ($action)
            {{-- The tooltip and hover tint sit on the icon itself: the button stretches over the whole row. --}}
            <button type="button" wire:click="{{ $action }}" class="inline-grid size-control place-items-center rounded-lg text-link after:absolute after:inset-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40">
                <span data-bs-toggle="tooltip" data-bs-title="{{ __('settings.edit') }}" class="relative z-10 grid size-control place-items-center rounded-lg hover:bg-brand-soft">
                    <x-busy-icon name="edit" :target="$action" class="size-4" />
                </span>
                <span class="sr-only">{{ $label }}</span>
            </button>
        @endif
    </td>
</tr>
