<div class="flex flex-col gap-section">
    <x-page-header
        icon="lock"
        :title="__('account.password.title')"
        :subtitle="__('account.password.subtitle')"
    />

    <form wire:submit="save" class="w-full max-w-xl rounded-xl border border-default bg-card p-card shadow-card" novalidate>
        <fieldset class="flex flex-col gap-4" wire:loading.attr="disabled" wire:target="save">
            <x-form.input
                model="current"
                type="password"
                :label="__('account.password.current')"
                autocomplete="current-password"
                autofocus
                required
            />

            <x-form.input
                model="password"
                type="password"
                :label="__('account.password.new')"
                :hint="__('account.password.hint', ['min' => $minLength])"
                autocomplete="new-password"
                required
            />

            <x-form.input
                model="password_confirmation"
                type="password"
                :label="__('account.password.confirm')"
                autocomplete="new-password"
                required
            />
        </fieldset>

        <p class="mt-4 text-body text-muted">{{ __('account.password.other_devices') }}</p>

        <div class="mt-4 flex flex-wrap items-center justify-end gap-4 border-t border-default pt-4">
            <a href="{{ route('home') }}" wire:navigate class="inline-flex h-control items-center rounded-lg px-3 text-body font-semibold text-muted hover:bg-card-hover hover:text-fg">
                {{ __('account.password.cancel') }}
            </a>
            <x-submit-button target="save" icon="check" class="min-w-30">
                {{ __('account.password.submit') }}
            </x-submit-button>
        </div>
    </form>
</div>
