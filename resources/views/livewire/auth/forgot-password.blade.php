<div class="w-full max-w-md">
    <div class="p-card sm:p-dialog">
        <x-brand />

        <div class="mt-section">
            <h1 class="text-h1 font-semibold tracking-tight text-fg">{{ __('auth.forgot.title') }}</h1>
            @unless ($sent)
                <p class="mt-1 text-body text-muted">{{ __('auth.forgot.subtitle') }}</p>
            @endunless
        </div>

        @if ($sent)
            {{-- The same message whether or not the email is registered. --}}
            <div class="mt-4 flex items-start gap-3 rounded-lg border border-default bg-surface p-4" role="status">
                <x-icon name="mail" class="mt-0.5 size-5 shrink-0 text-brand" />
                <p class="text-body text-fg">{{ __('auth.forgot.sent') }}</p>
            </div>
        @else
            <form wire:submit="send" class="mt-4 flex flex-col gap-4" novalidate>
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

                <x-submit-button target="send" :busy="__('auth.forgot.sending')" class="w-full text-lead transition-colors">
                    {{ __('auth.forgot.submit') }}
                </x-submit-button>
            </form>
        @endif

        <a href="{{ route('login') }}" class="mt-4 inline-flex min-h-control items-center gap-2 text-body font-semibold text-brand hover:underline">
            <x-icon name="arrow_back" class="size-4 rtl:-scale-x-100" />
            {{ __('auth.back_to_sign_in') }}
        </a>
    </div>
</div>
