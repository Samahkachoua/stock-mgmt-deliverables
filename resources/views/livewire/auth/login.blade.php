<div class="w-full max-w-md">
    <div class="p-card sm:p-dialog">
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
                {{-- Hidden until the eye button is pressed; pressing it again hides it. --}}
                <div class="relative" x-data="{ shown: false }">
                    <x-icon name="lock" class="pointer-events-none absolute start-3 top-1/2 size-5 -translate-y-1/2 text-muted" />
                    <input
                        id="password"
                        type="password"
                        x-bind:type="shown ? 'text' : 'password'"
                        wire:model="password"
                        autocomplete="current-password"
                        required
                        @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                        @class([
                            'h-control w-full rounded-lg border bg-card pe-11 ps-10 text-start text-body text-fg focus:outline-none focus:ring-2',
                            'border-danger focus:ring-danger/20' => $errors->has('password'),
                            'border-control focus:border-brand focus:ring-brand/20' => ! $errors->has('password'),
                        ])
                    >
                    <button
                        type="button"
                        aria-controls="password"
                        aria-pressed="false"
                        x-bind:aria-pressed="shown.toString()"
                        aria-label="{{ __('auth.show_password') }}"
                        title="{{ __('auth.show_password') }}"
                        x-bind:aria-label="shown ? @js(__('auth.hide_password')) : @js(__('auth.show_password'))"
                        x-bind:title="shown ? @js(__('auth.hide_password')) : @js(__('auth.show_password'))"
                        x-on:click="shown = ! shown"
                        class="absolute inset-y-0 end-0 grid w-control place-items-center rounded-e-lg text-muted hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
                    >
                        {{-- The icon shows the current state: crossed out = hidden, open eye = shown. --}}
                        <x-icon name="visibility_off" x-show="! shown" />
                        <x-icon name="visibility" x-show="shown" x-cloak />
                    </button>
                </div>
                @error('password')
                    <p id="password-error" class="mt-1 text-body text-danger" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="-my-1 flex flex-wrap items-center justify-between gap-x-3">
                <label class="flex min-h-control cursor-pointer items-center gap-3">
                    <input type="checkbox" wire:model="remember" class="size-5 rounded border-control accent-brand">
                    <span class="text-body text-fg">{{ __('auth.remember') }}</span>
                </label>
                <a href="{{ route('password.request') }}" class="inline-flex min-h-control items-center text-body font-semibold text-link hover:underline">{{ __('auth.forgot.link') }}</a>
            </div>

            <x-submit-button target="login" :busy="__('auth.signing_in')" class="w-full text-lead transition-colors">
                {{ __('auth.submit') }}
            </x-submit-button>
        </form>
    </div>
</div>
