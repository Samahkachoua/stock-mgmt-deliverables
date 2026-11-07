{{--
    Card with a table, an empty state and pagination.
    <x-data-table :columns="['reference' => ['label' => ..., 'card' => 'title'], 'items' => ['label' => ..., 'align' => 'end', 'priority' => 2, 'card' => 'hide'], ...]" :paginator="$rows" :empty-title="..." :empty-body="...">
        <x-slot:empty-action>...</x-slot:empty-action>
        @foreach ($rows as $row)
            <x-data-table.row :href="..." :label="...">
                <x-data-table.cell col="reference">...</x-data-table.cell>
            </x-data-table.row>
        @endforeach
    </x-data-table>
    Whole rows are links (see data-table/row); the last column holds the row arrow.

    Columns are keyed; each cell names its column with `col`. Per column
    (CLAUDE.md "Responsive layout"; the layout itself is the `cards` rules in app.css):
      priority  2 = hidden on a tablet (low-priority columns such as Created by)
      card      on a phone every row is a card: 'title' and 'badge' share its
                first line, 'hide' leaves the column out, anything else is a
                "Label  value" line ('lead' = the first of those lines)

    While the component reloads, the rows stay but dimmed under a progress bar
    (x-table-loading; `loading-target` narrows what counts as a reload).
    `loading`: the rows are not fetched yet (App\Livewire\Concerns\DefersRows):
    skeleton rows are shown and the browser asks for the rows.

    `footer`: a totals row under the rows, ['label' => 'Total · 37 operations',
    'cells' => ['total' => '1,240 Piece']]: the label in the first column, each
    value under its column. On a phone it is one "label  value" line.
--}}
@props([
    'columns',
    'paginator',
    'emptyTitle' => '',
    'emptyBody' => '',
    'loading' => false,
    'loadingTarget' => null,
    'footer' => null,
])

<x-table-loading :target="$loadingTarget" :busy="$loading" {{ $attributes->class('overflow-hidden rounded-xl border border-default bg-card shadow-card') }}>
    @if (! $loading && $paginator->isEmpty())
        <div class="flex flex-col items-center px-card py-12 text-center">
            <x-icon name="inventory_2" class="size-9 text-muted" />
            <h2 class="mt-3 text-lead font-semibold text-fg">{{ $emptyTitle }}</h2>
            <p class="mt-1 max-w-md text-body text-muted">{{ $emptyBody }}</p>
            @isset($emptyAction)
                <div class="mt-4">{{ $emptyAction }}</div>
            @endisset
        </div>
    @else
        <div class="relative overflow-x-auto">
            <table data-list class="cards w-full text-table xl:min-w-[880px]">
                <thead>
                    <tr class="h-thead bg-surface text-small font-semibold uppercase tracking-wide text-muted">
                        @foreach ($columns as $column)
                            <th scope="col" @if (($column['priority'] ?? 1) > 1) data-priority="{{ $column['priority'] }}" @endif @class([
                                'px-cell first:ps-edge',
                                'text-end' => ($column['align'] ?? null) === 'end',
                                'text-start' => ($column['align'] ?? null) !== 'end',
                            ])>{{ $column['label'] }}</th>
                        @endforeach
                        <th scope="col" class="w-12 pe-edge"><span class="sr-only">{{ __('transactions.list.open') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-default" @if ($loading) wire:init="loadRows" @endif>
                    @if ($loading)
                        <x-skeleton-rows :columns="count($columns) + 1" />
                    @else
                        {{ $slot }}
                    @endif
                </tbody>
                @if (! $loading && $footer !== null)
                    <tfoot class="border-t border-default">
                        <tr class="h-row bg-surface font-semibold text-fg">
                            @foreach (array_keys($columns) as $index => $key)
                                @if ($index === 0)
                                    <th scope="row" class="whitespace-nowrap px-cell ps-edge text-start">{{ $footer['label'] }}</th>
                                @elseif (array_key_exists($key, $footer['cells']))
                                    <td @if (($columns[$key]['priority'] ?? 1) > 1) data-priority="{{ $columns[$key]['priority'] }}" @endif @class([
                                        'whitespace-nowrap px-cell tabular',
                                        'text-end' => ($columns[$key]['align'] ?? null) === 'end',
                                    ])>{{ $footer['cells'][$key] }}</td>
                                @else
                                    {{-- Keeps the values under their columns; not on a phone card. --}}
                                    <td aria-hidden="true" @if (($columns[$key]['priority'] ?? 1) > 1) data-priority="{{ $columns[$key]['priority'] }}" @endif></td>
                                @endif
                            @endforeach
                            <td aria-hidden="true"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        @unless ($loading)
            <x-data-table.pagination :paginator="$paginator" />
        @endunless
    @endif
</x-table-loading>
