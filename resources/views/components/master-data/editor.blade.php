{{--
    Inline add/edit card: fields in a two-column grid, then Cancel and Save.
    Save is disabled while any field shows an error. Calls the component's save() / cancel().
    <x-master-data.editor :heading="...">fields</x-master-data.editor>
--}}
@props(['heading'])

<form wire:submit="save" class="rounded-xl border border-default bg-card p-card shadow-card" novalidate>
    <h3 class="text-lead font-semibold text-fg">{{ $heading }}</h3>

    <div class="mt-4 grid gap-4 md:grid-cols-2">
        {{ $slot }}
    </div>

    {{ $after ?? '' }}

    <div class="mt-4 flex flex-wrap justify-end gap-3">
        <button type="button" wire:click="cancel" class="h-control rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover">
            {{ __('settings.cancel') }}
        </button>
        <x-submit-button target="save" icon="check" :disabled="$errors->any()" class="min-w-30">
            {{ __('settings.save') }}
        </x-submit-button>
    </div>
</form>
