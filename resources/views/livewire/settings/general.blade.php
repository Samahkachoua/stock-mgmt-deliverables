<x-settings.layout>
    <form wire:submit="save" class="rounded-xl border border-default bg-card p-card shadow-card" novalidate>
        <h2 class="text-h2 font-semibold text-fg">{{ __('settings.general.heading') }}</h2>
        <p class="mt-1 text-body text-muted">{{ __('settings.general.description') }}</p>

        <div class="mt-4 grid gap-section border-t border-default pt-4 md:grid-cols-2">
            <x-form.select
                model="timezone"
                :label="__('settings.general.timezone')"
                :options="$zones"
                :hint="__('settings.general.timezone_hint')"
                required
            />

            {{-- What the clock shows in the chosen time zone, so the user can check it before saving. --}}
            <div aria-live="polite">
                <p class="mb-1 text-body font-semibold text-fg">{{ __('settings.general.now') }}</p>
                <p class="flex h-control items-center rounded-lg border border-default bg-surface px-3 text-body text-fg tabular">{{ $now }}</p>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-end gap-4 border-t border-default pt-4">
            <x-submit-button target="save" icon="check" :disabled="$errors->any()" class="min-w-30">
                {{ __('settings.save') }}
            </x-submit-button>
        </div>
    </form>
</x-settings.layout>
