{{--
    Excel and PDF export of what the page shows: calls the page's export()
    Livewire action, which uses its current filters (never URL parameters).
    The clicked button shows a spinner and "Preparing file…" until the download starts.
    <x-report.export-buttons :exportable="$exportable" /> false: nothing to export yet.
--}}
@props(['exportable' => false])

@if ($exportable)
    @foreach (['xlsx' => 'reports.export.excel', 'pdf' => 'reports.export.pdf'] as $format => $label)
        <x-submit-button
            :action="sprintf('export(\'%s\')', $format)"
            :target="sprintf('export(\'%s\')', $format)"
            icon="download"
            variant="outline"
            :busy="__('app.loading.preparing_file')"
            class="print:hidden"
        >
            {{ __($label) }}
        </x-submit-button>
    @endforeach
@endif
