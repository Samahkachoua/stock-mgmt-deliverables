<?php

use App\Http\Controllers\DraftController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImportFileController;
use App\Http\Controllers\ItemStockController;
use App\Http\Controllers\ListPresetController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TransactionController;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Drafts\DraftForm;
use App\Livewire\Drafts\DraftList;
use App\Livewire\Drafts\DraftPage;
use App\Livewire\Imports\ImportJobPage;
use App\Livewire\Imports\ImportList;
use App\Livewire\Imports\ImportUpload;
use App\Livewire\Items\ItemForm;
use App\Livewire\Items\ItemList;
use App\Livewire\Reports\ReportPage;
use App\Livewire\Settings;
use App\Livewire\Stock\StockPage;
use App\Livewire\Transactions\TransactionForm;
use App\Livewire\Transactions\TransactionList;
use Illuminate\Support\Facades\Route;

// No self-registration: accounts are created by an administrator.
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

Route::post('/locale/{locale}', LocaleController::class)->name('locale.update');

Route::post('/logout', LogoutController::class)->middleware('auth')->name('logout');

Route::middleware(['auth', 'warehouse'])->group(function () {
    // Home: large links to what the user can do; no dashboard widgets by design.
    Route::get('/', HomeController::class)->name('home');

    // Owner dashboard: figures, charts and short tables (dashboard.view, ADMIN for now).
    Route::get('/dashboard', Dashboard::class)->name('dashboard')->middleware('can:dashboard.view');

    // Each settings tab is its own page; permissions come from config('ui.settings_tabs').
    Route::get('/settings', SettingsController::class)->name('settings')->middleware('can:settings.open');

    $settingsPages = [
        'settings.warehouses' => Settings\Warehouses::class,
        'settings.customers' => Settings\Customers::class,
        'settings.branches' => Settings\Branches::class,
        'settings.units' => Settings\Units::class,
        'settings.approval-limits' => Settings\ApprovalLimits::class,
        'settings.users' => Settings\Users::class,
        'settings.roles' => Settings\Roles::class,
        'settings.general' => Settings\General::class,
    ];

    foreach (config('ui.settings_tabs') as $tab) {
        Route::get('/settings/'.str($tab['route'])->after('settings.'), $settingsPages[$tab['route']])
            ->name($tab['route'])
            ->middleware('can:'.$tab['permission']);
    }

    // Transfer lines that arrived short, for managers: now a report with exports.
    Route::redirect('/transfer/shortages', '/reports/transfer-shortages')->name('transfer.shortages');

    // Stock (stock.view) and one item's stock in one warehouse.
    Route::get('/stock', StockPage::class)->name('stock')->middleware('can:stock.view');
    Route::get('/stock/{product}/{warehouse}', [ItemStockController::class, 'show'])
        ->name('stock.item')
        ->whereNumber(['product', 'warehouse'])
        ->middleware('can:stock.view');

    // Reports (the add-report skill). Each report checks its own permission
    // (report.view; the audit log audit.view). Filters live in the session,
    // never in the URL; Excel/PDF come from the page's export() action.
    Route::get('/reports', [ReportController::class, 'index'])->name('reports')->middleware('can:reports.open');
    Route::get('/reports/{report}', ReportPage::class)->name('reports.show')->where('report', '[a-z-]+');
    // An item's stock card: sets the report's filters, then opens /reports/stock-card.
    Route::get('/reports/stock-card/item/{product}/{warehouse}', [ListPresetController::class, 'stockCard'])
        ->name('reports.stock-card.item')
        ->whereNumber(['product', 'warehouse']);

    // Every transaction type gets the same three pages: list, new, details.
    // Built from config only, so registering routes never needs the database.
    foreach (config('transactions.types') as $code => $type) {
        $slug = $type['slug'];
        // The type's view permission, or creating / approving it (AppServiceProvider).
        $view = 'can:documents.'.$slug;

        Route::get('/'.$slug, TransactionList::class)
            ->name($slug)
            ->defaults('type', $code)
            ->middleware($view);

        // Opens the list showing what is on its way here (dispatch/receive types only).
        Route::get('/'.$slug.'/incoming', [ListPresetController::class, 'incoming'])
            ->name($slug.'.incoming')
            ->defaults('type', $code)
            ->middleware($view);

        Route::get('/'.$slug.'/new', TransactionForm::class)
            ->name($slug.'.create')
            ->defaults('type', $code)
            ->middleware('can:'.$type['create']);

        Route::get('/'.$slug.'/{reference}', [TransactionController::class, 'show'])
            ->name($slug.'.show')
            ->defaults('type', $code)
            ->where('reference', '[A-Z]+-\d{4}-\d+')
            ->middleware($view);

        // A4 print view of the details page (CLAUDE.md "Printing").
        Route::get('/'.$slug.'/{reference}/print', [TransactionController::class, 'print'])
            ->name($slug.'.print')
            ->defaults('type', $code)
            ->where('reference', '[A-Z]+-\d{4}-\d+')
            ->middleware($view);
    }

    // Draft receipts / orders: list, new, details, edit, print. They never touch
    // stock until confirmed (on the details page, or "Save and confirm" on edit).
    foreach (config('transactions.drafts') as $code => $draft) {
        $slug = $draft['slug'];
        $open = 'can:drafts.'.strtolower($code).'.open';
        $reference = '[A-Z]+-\d{4}-\d+';

        Route::get('/'.$slug, DraftList::class)->name($slug)->defaults('type', $code)->middleware($open);
        Route::get('/'.$slug.'/new', DraftForm::class)->name($slug.'.create')->defaults('type', $code)->middleware('can:'.$draft['create']);
        Route::get('/'.$slug.'/{reference}', DraftPage::class)->name($slug.'.show')->defaults('type', $code)->where('reference', $reference)->middleware($open);
        Route::get('/'.$slug.'/{reference}/edit', DraftForm::class)->name($slug.'.edit')->defaults('type', $code)->where('reference', $reference)->middleware($open);
        Route::get('/'.$slug.'/{reference}/print', [DraftController::class, 'print'])->name($slug.'.print')->defaults('type', $code)->where('reference', $reference)->middleware($open);
    }

    // Excel import (staged: upload, check, import). Each kind checks its own permissions too.
    Route::middleware('can:product.import')->group(function () {
        Route::get('/imports', ImportList::class)->name('imports');
        Route::get('/imports/new/{type}', ImportUpload::class)->name('imports.create')->where('type', '[a-z-]+');
        Route::get('/imports/template/{type}', [ImportFileController::class, 'template'])->name('imports.template')->where('type', '[a-z-]+');
        Route::get('/imports/{job}', ImportJobPage::class)->name('imports.show')->whereNumber('job');
        Route::get('/imports/{job}/errors', [ImportFileController::class, 'errors'])->name('imports.errors')->whereNumber('job');
    });

    Route::middleware('can:product.manage')->group(function () {
        Route::get('/items', ItemList::class)->name('items');
        Route::get('/items/new', ItemForm::class)->name('items.create');
        Route::get('/items/{product}/edit', ItemForm::class)->name('items.edit')->whereNumber('product');
    });

    // Placeholders until each screen is built.
    $built = ['home', 'dashboard', 'settings', 'items', 'imports', 'stock', 'reports', ...array_column(config('transactions.types'), 'slug'), ...array_column(config('transactions.drafts'), 'slug')];

    foreach (config('ui.nav') as $item) {
        if (isset($item['route']) && ! in_array($item['route'], $built, true)) {
            Route::get('/'.$item['route'], PageController::class)
                ->name($item['route'])
                ->middleware('can:'.$item['permission']);
        }
    }
});
