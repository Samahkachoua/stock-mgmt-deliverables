{{--
    A4 print of a draft receipt / issue, opened by the details page's Print
    button: the same summary and lines (by warehouse) as the details page.
--}}
<x-layouts.print
    :title="$draft['reference']"
    :heading="$kind->label('singular')"
    :reference="$draft['reference']"
>
    <x-transaction-summary :rows="\App\Support\Drafts\DraftDocuments::summary($kind, $draft, print: true)" compact class="avoid-break" />

    <x-print-lines-table :groups="$draft['groups']" />
</x-layouts.print>
