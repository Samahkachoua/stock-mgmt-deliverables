<div class="w-full max-w-md">
    <div class="p-card sm:p-dialog">
        <x-brand />

        <div class="mt-section">
            <h1 class="text-h1 font-semibold tracking-tight text-fg">{{ __('auth.reset.title') }}</h1>
            @unless ($invalid)
                <p class="mt-1 text-body text-muted">{{ __('auth.reset.subtitle') }}</p>
            @endunless
        </div>

        @if ($invalid)
            <div class="mt-4 flex items-start gap-3 rounded-lg border border-danger bg-surface p-4" role="alert">
                <x-icon name="warning" class="mt-0.5 size-5 shrink-0 text-danger" />
                <p class="text-body text-fg">{{ __('auth.reset.invalid', ['minutes' => \App\Support\Auth\PasswordReset::expiresIn()]) }}</p>
            </div>

            <a href="{{ route('password.request') }}" class="mt-4 inline-flex h-control w-full items-center justify-center rounded-lg bg-brand px-4 text-lead font-semibold text-on-brand hover:bg-brand-hover">
                {{ __('auth.reset.request_new') }}
            </a>
        @else
            <form wire:submit="save" class="mt-4 flex flex-col gap-4" novalidate>
                @foreach (['password' => ['auth.reset.new', true], 'password_confirmation' => ['auth.reset.confirm', false]] as $field => [$label, $hint])
                    <div wire:key="{{ $field }}">
                        <label for="{{ $field }}" class="mb-1 block text-body font-semibold text-fg">{{ __($label) }}</label>
                        {{-- Hidden until the eye button is pressed; pressing it again hides it (each field on its own, as on Sign in). --}}
                        <div class="relative" x-data="{ shown: false }">
                            <x-icon name="lock" class="pointer-events-none absolute start-3 top-1/2 size-5 -translate-y-1/2 text-muted" />
                            <input
                                id="{{ $field }}"
                                type="password"
                                x-bind:type="shown ? 'text' : 'password'"
                                wire:model="{{ $field }}"
                                autocomplete="new-password"
                                @if ($hint) autofocus @endif
                                required
                                @error($field)
                                    aria-invalid="true" aria-describedby="{{ $field }}-error"
                                @else
                                    @if ($hint) aria-describedby="{{ $field }}-hint" @endif
                                @enderror
                                @class([
                                    'h-control w-full rounded-lg border bg-card pe-11 ps-10 text-start text-body text-fg focus:outline-none focus:ring-2',
                                    'border-danger focus:ring-danger/20' => $errors->has($field),
                                    'border-control focus:border-brand focus:ring-brand/20' => ! $errors->has($field),
                                ])
                            >
                            <button
                                type="button"
                                aria-controls="{{ $field }}"
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
                        @error($field)
                            <p id="{{ $field }}-error" class="mt-1 text-body text-danger" role="alert">{{ $message }}</p>
                        @else
                            @if ($hint)
                                <p id="{{ $field }}-hint" class="mt-1 text-body text-muted">{{ __('account.password.hint', ['min' => $minLength]) }}</p>
                            @endif
                        @enderror
                    </div>
                @endforeach

                <p class="text-body text-muted">{{ __('auth.reset.signs_out') }}</p>

                <x-submit-button target="save" :busy="__('app.loading.saving')" class="w-full text-lead transition-colors">
                    {{ __('auth.reset.submit') }}
                </x-submit-button>
            </form>
        @endif

        <a href="{{ route('login') }}" class="mt-4 inline-flex min-h-control items-center gap-2 text-body font-semibold text-brand hover:underline">
            <x-icon name="arrow_back" class="size-4 rtl:-scale-x-100" />
            {{ __('auth.back_to_sign_in') }}
        </a>
    </div>
</div>
