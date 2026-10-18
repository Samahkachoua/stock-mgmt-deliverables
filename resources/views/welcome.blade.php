{{-- The public website, for now a coming-soon page. The WMS lives under /app. --}}
<x-layouts.base :title="__('welcome.badge')">
    <div class="flex min-h-screen flex-col px-4 py-8">
        <main class="flex flex-1 items-center justify-center">
            <div class="flex max-w-2xl flex-col items-center gap-section text-center">
                <div class="grid size-14 place-items-center rounded-2xl bg-brand text-on-brand shadow-card">
                    <x-icon name="warehouse" />
                </div>

                <p class="text-h2 font-semibold tracking-tight text-fg">{{ config('app.name') }}</p>

                <span class="rounded-full border border-default bg-card px-3 py-1 text-small font-semibold uppercase tracking-wider text-muted">
                    {{ __('welcome.badge') }}
                </span>

                <h1 class="text-display font-bold tracking-tight text-fg">{{ __('welcome.title') }}</h1>

                <p class="max-w-xl text-lead text-muted">{{ __('welcome.tagline') }}</p>
            </div>
        </main>

        <footer class="pt-8 text-center text-small text-muted">
            {{ __('welcome.copyright', ['year' => now()->year, 'name' => config('app.name')]) }}
        </footer>
    </div>
</x-layouts.base>
