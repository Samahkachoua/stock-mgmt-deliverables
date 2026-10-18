{{--
    Heading of a master data list: title, description, an "Add" button while no
    form is open, and the confirmation of the last save.
    <x-master-data.header :heading="..." :description="..." :add-label="..." :editing="$editing" />
--}}
@props(['heading', 'description' => null, 'addLabel' => null, 'editing' => null])

<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="text-h2 font-semibold text-fg">{{ $heading }}</h2>
                {{ $badge ?? '' }}
            </div>
            @if ($description)
                <p class="mt-1 text-body text-muted">{{ $description }}</p>
            @endif
        </div>

        @if ($addLabel && $editing === null)
            <button
                type="button"
                wire:click="add"
                class="flex h-control items-center gap-2 rounded-lg bg-brand px-4 text-body font-semibold text-on-brand shadow-card hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand/40 focus:ring-offset-2 focus:ring-offset-surface"
            >
                <x-busy-icon name="add" target="add" />
                {{ $addLabel }}
            </button>
        @endif
    </div>

</div>
