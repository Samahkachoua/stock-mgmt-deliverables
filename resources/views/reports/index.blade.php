<x-layouts.app :title="__('reports.title')">
    <div class="flex flex-col gap-section">
        <x-page-header icon="description" :title="__('reports.title')" :subtitle="__('reports.subtitle')" />

        <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($reports as $report)
                <li>
                    <a
                        href="{{ route('reports.show', $report->key()) }}"
                        wire:navigate
                        class="flex h-full min-h-24 items-start gap-4 rounded-xl border border-default bg-card p-card shadow-card hover:bg-card-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
                    >
                        <span class="grid size-control shrink-0 place-items-center rounded-lg bg-surface">
                            <x-icon :name="$report->icon()" class="text-muted" />
                        </span>
                        <span class="flex flex-col gap-1">
                            <span class="text-lead font-semibold text-fg">{{ $report->title() }}</span>
                            <span class="text-body text-muted">{{ $report->description() }}</span>
                        </span>
                        <x-icon name="chevron_right" class="ms-auto shrink-0 self-center text-muted rtl:-scale-x-100" />
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</x-layouts.app>
