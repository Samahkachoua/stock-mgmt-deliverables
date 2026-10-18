<x-layouts.guest :title="__('app.no_warehouses_title')">
    <div class="w-full max-w-md rounded-xl border border-default bg-card p-card text-center shadow-card">
        <x-icon name="warehouse" class="mx-auto size-10 text-muted" />
        <h1 class="mt-4 text-h2 font-semibold text-fg">{{ __('app.no_warehouses_title') }}</h1>
        <p class="mt-2 text-body text-muted">{{ __('app.no_warehouses_body') }}</p>

        <form method="POST" action="{{ route('logout') }}" class="mt-4">
            @csrf
            <button type="submit" class="h-control rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover">
                {{ __('app.sign_out') }}
            </button>
        </form>
    </div>
</x-layouts.guest>
