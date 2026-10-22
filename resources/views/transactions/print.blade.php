{{--
    A4 print of a transaction (any type), opened by the details page's Print
    button. Same summary and lines as the details page, in the print layout.
--}}
<x-layouts.print
    :title="$document['reference']"
    :heading="$ui->label('singular')"
    :reference="$document['reference']"
>
    <x-transaction-summary :rows="\App\Support\Transactions\Documents::summary($ui, $document, print: true)" compact class="avoid-break" />

    <x-print-lines-table :type="$ui" :lines="$document['lines']" />
</x-layouts.print>
