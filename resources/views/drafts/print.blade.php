{{--
    A4 print of a draft receipt / order, opened by the details page's Print
    button: the same summary and lines as the details page.
--}}
<x-layouts.print
    :title="$draft['reference']"
    :heading="$kind->label('singular')"
    :reference="$draft['reference']"
>
    <x-transaction-summary :rows="\App\Support\Drafts\DraftDocuments::summary($kind, $draft, print: true)" compact class="avoid-break" />

    <x-print-lines-table :lines="$draft['lines']" />
</x-layouts.print>
