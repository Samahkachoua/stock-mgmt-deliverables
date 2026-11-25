{{--
    The Reports page: one card per section (Reports::GROUPS), one compact row
    per report, every icon in the brand colour. Reports are found by name from
    the top bar search (Pages group), so this page has no search of its own.
--}}
<x-layouts.app :title="__('reports.title')">
    <div class="flex flex-col gap-section">
        <x-page-header icon="description" :title="__('reports.title')" :subtitle="__('reports.subtitle')" />

        @foreach ($groups as $group => $items)
            <section
                data-report-group="{{ $group }}"
                aria-labelledby="report-group-{{ $group }}"
                class="flex flex-col gap-2"
            >
                <h2 id="report-group-{{ $group }}" class="px-1 text-label font-semibold text-muted">
                    {{ __('reports.groups.'.$group) }}
                </h2>

                <ul class="grid gap-1 rounded-xl border border-default bg-card p-2 shadow-card xl:grid-cols-2">
                    @foreach ($items as $report)
                        <li>
                            <a
                                href="{{ route('reports.show', $report->key()) }}"
                                wire:navigate
                                class="flex min-h-14 items-center gap-3 rounded-lg px-3 py-2 hover:bg-card-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
                            >
                                <span class="grid size-control shrink-0 place-items-center rounded-lg bg-brand/12 dark:bg-brand/16">
                                    <x-icon :name="$report->icon()" class="text-link" />
                                </span>
                                <span class="flex min-w-0 flex-col">
                                    <span class="text-body font-semibold text-fg">{{ $report->title() }}</span>
                                    <span class="line-clamp-1 text-small text-muted" title="{{ $report->description() }}">{{ $report->description() }}</span>
                                </span>
                                <x-icon name="chevron_right" class="ms-auto shrink-0 text-muted rtl:-scale-x-100" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</x-layouts.app>
