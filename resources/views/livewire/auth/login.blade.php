<div class="w-full max-w-md">
    <div class="rounded-xl border border-default bg-card p-card shadow-card sm:p-5">
        <x-brand />

        <div class="mt-section">
            <h1 class="text-h1 font-semibold tracking-tight text-fg">{{ __('auth.title') }}</h1>
            <p class="mt-1 text-body text-muted">{{ __('auth.subtitle') }}</p>
        </div>

        <form wire:submit="login" class="mt-4 flex flex-col gap-4" novalidate>
            <div>
                <label for="email" class="mb-1 block text-body font-semibold text-fg">{{ __('auth.email') }}</label>
                <div class="relative">
                    <x-icon name="mail" class="pointer-events-none absolute start-3 top-1/2 size-5 -translate-y-1/2 text-muted" />
                    <input
                        id="email"
                        type="email"
                        wire:model="email"
                        autocomplete="username"
                        autofocus
                        required
                        @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                        @class([
                            'h-control w-full rounded-lg border bg-card pe-3 ps-10 text-start text-body text-fg placeholder:text-subtle focus:outline-none focus:ring-2',
                            'border-danger focus:ring-danger/20' => $errors->has('email'),
                            'border-control focus:border-brand focus:ring-brand/20' => ! $errors->has('email'),
                        ])
                    >
                </div>
                @error('email')
                    <p id="email-error" class="mt-1 text-body text-danger" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="mb-1 block text-body font-semibold text-fg">{{ __('auth.password') }}</label>
                <div class="relative">
                    <x-icon name="lock" class="pointer-events-none absolute start-3 top-1/2 size-5 -translate-y-1/2 text-muted" />
                    <input
                        id="password"
                        type="password"
                        wire:model="password"
                        autocomplete="current-password"
                        required
                        @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                        @class([
                            'h-control w-full rounded-lg border bg-card pe-3 ps-10 text-start text-body text-fg focus:outline-none focus:ring-2',
                            'border-danger focus:ring-danger/20' => $errors->has('password'),
                            'border-control focus:border-brand focus:ring-brand/20' => ! $errors->has('password'),
                        ])
                    >
                </div>
                @error('password')
                    <p id="password-error" class="mt-1 text-body text-danger" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <label class="-my-1 flex min-h-control cursor-pointer items-center gap-3 self-start">
                <input type="checkbox" wire:model="remember" class="size-5 rounded border-control accent-brand">
                <span class="text-body text-fg">{{ __('auth.remember') }}</span>
            </label>

            <x-submit-button target="login" :busy="__('auth.signing_in')" class="w-full text-lead transition-colors">
                {{ __('auth.submit') }}
            </x-submit-button>
        </form>
    </div>

    <p class="mt-4 text-center text-body text-muted">{{ __('auth.no_account') }}</p>
</div>
