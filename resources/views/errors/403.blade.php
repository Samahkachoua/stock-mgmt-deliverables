<x-layouts.guest :title="__('app.forbidden_title')">
    <div class="w-full max-w-md rounded-xl border border-default bg-card p-card text-center shadow-card">
        <x-icon name="lock" class="mx-auto size-10 text-muted" />
        <h1 class="mt-4 text-h2 font-semibold text-fg">{{ __('app.forbidden_title') }}</h1>
        <p class="mt-2 text-body text-muted">{{ __('app.forbidden_body') }}</p>

        @auth
            <div class="mt-4 flex justify-center gap-3">
                <a href="{{ route('home') }}" class="inline-flex h-control items-center rounded-lg bg-brand px-4 text-body font-semibold text-on-brand hover:bg-brand-hover">
                    {{ __('app.go_to_start') }}
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="h-control rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover">
                        {{ __('app.sign_out') }}
                    </button>
                </form>
            </div>
        @endauth
    </div>
</x-layouts.guest>
