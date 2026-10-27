<?php

namespace App\Support\Search;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SerialNumber;
use App\Models\StockBalance;
use App\Models\StockDraft;
use App\Models\StockTransaction;
use App\Support\Batches;
use App\Support\Drafts\DraftTypes;
use App\Support\Drafts\DraftUi;
use App\Support\Transactions\Statuses;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The top bar search: documents (by number or the customer's own reference),
 * items (name, code, barcode), customers, serial numbers and batches.
 *
 * Every query goes through the models, so the warehouse scope (user_warehouse)
 * and the customer scope (customer portal users) always apply; each group also
 * needs the permission of the page its results open.
 *
 * @phpstan-type Row array{title: string, code: string|null, meta: string, url: string, icon: string, tone: string}
 * @phpstan-type Group array{key: string, label: string, rows: list<Row>}
 */
final class GlobalSearch
{
    /** Shortest text that searches. */
    public const MIN_LENGTH = 2;

    /** Results per group. */
    public const PER_GROUP = 5;

    /**
     * @return list<Group> only the groups with results, in a fixed order
     */
    public static function run(string $text): array
    {
        $text = trim(mb_substr($text, 0, 100));

        if (mb_strlen($text) < self::MIN_LENGTH) {
            return [];
        }

        $groups = [
            ['key' => 'documents', 'label' => __('search.groups.documents'), 'rows' => self::documents($text)],
            ['key' => 'items', 'label' => __('search.groups.items'), 'rows' => self::items($text)],
            ['key' => 'customers', 'label' => __('search.groups.customers'), 'rows' => self::customers($text)],
            ['key' => 'serials', 'label' => __('search.groups.serials'), 'rows' => self::serials($text)],
            ['key' => 'batches', 'label' => __('search.groups.batches'), 'rows' => self::batches($text)],
        ];

        return array_values(array_filter($groups, fn (array $group) => $group['rows'] !== []));
    }

    /**
     * Documents and drafts of the types the user may open, the exact number first,
     * then the newest.
     *
     * @return list<Row>
     */
    private static function documents(string $text): array
    {
        $like = self::contains($text);
        $exact = mb_strtoupper($text);

        $types = array_filter(TransactionTypes::all(), fn (TypeUi $type) => Gate::allows($type->viewGate()));
        $drafts = array_filter(DraftTypes::all(), fn (DraftUi $draft) => Gate::allows('drafts.'.strtolower($draft->code).'.open'));

        $found = [];

        if ($types !== []) {
            StockTransaction::query()
                ->join('customer', 'customer.id', '=', 'stock_transaction.customer_id')
                ->whereIn('stock_transaction.transaction_type', array_keys($types))
                ->where(fn ($query) => $query
                    ->whereRaw('lower(stock_transaction.doc_no) like ?', [$like])
                    ->orWhereRaw('lower(stock_transaction.reference_note) like ?', [$like]))
                ->orderByRaw('(stock_transaction.doc_no = ?) DESC', [$exact])
                ->orderByDesc('stock_transaction.created_at')
                ->limit(self::PER_GROUP)
                ->get(['stock_transaction.doc_no', 'stock_transaction.transaction_type', 'stock_transaction.status', 'stock_transaction.reference_note', 'stock_transaction.created_at', 'customer.name as customer_name'])
                ->each(function (StockTransaction $row) use ($types, $exact, &$found) {
                    $type = $types[$row->transaction_type];
                    $found[] = [
                        'exact' => $row->doc_no === $exact,
                        'at' => (string) $row->getAttribute('created_at'),
                        'row' => self::document($row->doc_no, $type->label('singular'), (string) $row->getAttribute('customer_name'), Statuses::label($row->status, $type), $row->getAttribute('reference_note'), $type->route('show', $row->doc_no), $type->icon(), $type->colours()['icon']),
                    ];
                });
        }

        if ($drafts !== []) {
            StockDraft::query()
                ->join('customer', 'customer.id', '=', 'stock_draft.customer_id')
                ->whereIn('stock_draft.transaction_type', array_keys($drafts))
                ->where(fn ($query) => $query
                    ->whereRaw('lower(stock_draft.doc_no) like ?', [$like])
                    ->orWhereRaw('lower(stock_draft.reference_note) like ?', [$like]))
                ->orderByRaw('(stock_draft.doc_no = ?) DESC', [$exact])
                ->orderByDesc('stock_draft.created_at')
                ->limit(self::PER_GROUP)
                ->get(['stock_draft.doc_no', 'stock_draft.transaction_type', 'stock_draft.status', 'stock_draft.reference_note', 'stock_draft.created_at', 'customer.name as customer_name'])
                ->each(function (StockDraft $row) use ($drafts, $exact, &$found) {
                    $draft = $drafts[$row->transaction_type];
                    $found[] = [
                        'exact' => $row->doc_no === $exact,
                        'at' => (string) $row->getAttribute('created_at'),
                        'row' => self::document($row->doc_no, $draft->label('singular'), (string) $row->getAttribute('customer_name'), DraftTypes::statusLabel($row->status), $row->getAttribute('reference_note'), $draft->route('show', $row->doc_no), $draft->icon(), $draft->colours()['icon']),
                    ];
                });
        }

        usort($found, fn (array $a, array $b) => [$b['exact'], $b['at']] <=> [$a['exact'], $a['at']]);

        return array_column(array_slice($found, 0, self::PER_GROUP), 'row');
    }

    /**
     * @return Row
     */
    private static function document(string $number, string $type, string $customer, string $status, mixed $note, string $url, string $icon, string $tone): array
    {
        $parts = [$type, $customer, $status];

        if (filled($note)) {
            $parts[] = __('search.reference_note', ['note' => mb_strimwidth((string) $note, 0, 40, '…')]);
        }

        return ['title' => $number, 'code' => null, 'meta' => implode(' · ', $parts), 'url' => $url, 'icon' => $icon, 'tone' => $tone];
    }

    /**
     * Items by code, name (any language) or barcode. They open the Stock page
     * filtered to the item; without stock.view, the item's edit page.
     *
     * @return list<Row>
     */
    private static function items(string $text): array
    {
        $stock = Gate::allows('stock.view');

        if (! $stock && ! Gate::allows('product.manage')) {
            return [];
        }

        $barcode = DB::table('product_barcode')->select('product_id')->where('barcode', $text);

        return Product::query()
            ->with('customer:id,name')
            ->when($stock, fn ($query) => $query->stocked())
            ->where('active', true)
            ->where(fn ($query) => $query->search($text)->orWhereIn('id', $barcode))
            ->orderByRaw('(upper(sku) = ?) DESC', [mb_strtoupper($text)])
            ->orderBy('name_en')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Product $product) => [
                'title' => $product->name(),
                'code' => $product->sku,
                'meta' => (string) $product->customer->name,
                'url' => $stock ? route('stock.for-item', $product->id) : route('items.edit', $product->id),
                'icon' => 'inventory_2',
                'tone' => 'text-muted',
            ])
            ->all();
    }

    /**
     * Customers by name or code; they open the Stock page filtered to them.
     *
     * @return list<Row>
     */
    private static function customers(string $text): array
    {
        if (! Gate::allows('stock.view')) {
            return [];
        }

        $like = self::contains($text);

        return Customer::query()
            ->where('active', true)
            ->where(fn ($query) => $query->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(code) like ?', [$like]))
            ->orderBy('name')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Customer $customer) => [
                'title' => $customer->name,
                'code' => $customer->code,
                'meta' => __('search.customer_stock'),
                'url' => route('stock.for-customer', $customer->id),
                'icon' => 'groups',
                'tone' => 'text-muted',
            ])
            ->all();
    }

    /**
     * Serials in one of the user's warehouses, starting with the text; they open
     * the item's stock in that warehouse.
     *
     * @return list<Row>
     */
    private static function serials(string $text): array
    {
        if (! Gate::allows('stock.view')) {
            return [];
        }

        return SerialNumber::query()
            ->join('product', 'product.id', '=', 'serial_number.product_id')
            ->join('warehouse', 'warehouse.id', '=', 'serial_number.warehouse_id')
            // Through Product, so the customer scope applies.
            ->whereIn('serial_number.product_id', Product::query()->select('id'))
            ->whereRaw('upper(serial_number.serial_no) like ?', [self::startsWith(mb_strtoupper($text))])
            ->orderBy('serial_number.serial_no')
            ->limit(self::PER_GROUP)
            ->get(['serial_number.serial_no', 'serial_number.product_id', 'serial_number.warehouse_id', 'product.name_en', 'product.name_ar', 'product.name_fr', 'product.sku', 'warehouse.name as warehouse_name'])
            ->map(fn (SerialNumber $serial) => [
                'title' => $serial->serial_no,
                'code' => null,
                'meta' => self::productName($serial).' · '.$serial->getAttribute('warehouse_name'),
                'url' => route('stock.item', [$serial->product_id, $serial->warehouse_id]),
                'icon' => 'qr_code_2',
                'tone' => 'text-muted',
            ])
            ->all();
    }

    /**
     * Batches in stock in one of the user's warehouses, starting with the text;
     * they open the item's stock in that warehouse.
     *
     * @return list<Row>
     */
    private static function batches(string $text): array
    {
        $batch = Batches::normalize($text);

        if (! Gate::allows('stock.view') || $batch === '') {
            return [];
        }

        return StockBalance::query()
            ->join('product', 'product.id', '=', 'stock_balance.product_id')
            ->join('warehouse', 'warehouse.id', '=', 'stock_balance.warehouse_id')
            ->where('stock_balance.qty_base', '>', 0)
            ->where('stock_balance.batch_no', 'like', self::startsWith($batch))
            ->orderBy('stock_balance.batch_no')
            ->orderBy('stock_balance.expiry_date')
            ->limit(self::PER_GROUP)
            ->get(['stock_balance.batch_no', 'stock_balance.expiry_date', 'stock_balance.product_id', 'stock_balance.warehouse_id', 'product.name_en', 'product.name_ar', 'product.name_fr', 'warehouse.name as warehouse_name'])
            ->map(fn (StockBalance $row) => [
                'title' => Batches::label($row->getAttribute('expiry_date'), $row->getAttribute('batch_no')),
                'code' => null,
                'meta' => self::productName($row).' · '.$row->getAttribute('warehouse_name'),
                'url' => route('stock.item', [$row->product_id, $row->warehouse_id]),
                'icon' => 'calendar_today',
                'tone' => 'text-muted',
            ])
            ->all();
    }

    /**
     * The item name in the current language, from a row that selected name_en / name_ar / name_fr.
     */
    private static function productName(Model $row): string
    {
        $name = $row->getAttribute('name_'.app()->getLocale());

        return filled($name) ? (string) $name : (string) $row->getAttribute('name_en');
    }

    private static function contains(string $text): string
    {
        return '%'.self::escape(mb_strtolower($text)).'%';
    }

    private static function startsWith(string $text): string
    {
        return self::escape($text).'%';
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);
    }
}
