<?php

namespace App\Providers;

use App\Http\Middleware\EnsureHasWarehouse;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CompanyTime;
use App\Support\Format;
use App\Support\Notify;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Livewire;
use Throwable;

use function Livewire\on;
use function Livewire\store;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The company time zone, read once per request.
        $this->app->singleton(CompanyTime::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tests (feature and browser) use the built assets (npm run build), never a
        // developer's running Vite server (public/hot).
        if ($this->app->environment('testing')) {
            Vite::useHotFile(storage_path('framework/vite.hot'));
        }

        // Livewire requests from warehouse-scoped pages re-run the warehouse check.
        Livewire::addPersistentMiddleware([
            EnsureHasWarehouse::class,
        ]);

        $this->registerPermissionGates();
        $this->registerFeedback();
    }

    /**
     * CLAUDE.md "Feedback messages": errors are never shown raw outside local,
     * and a Livewire action that fails validation gets, besides the messages
     * under its fields, a toast saying how many fields need attention.
     * (Validation while typing, outside an action, stays inline only.)
     */
    private function registerFeedback(): void
    {
        if (! $this->app->environment('local', 'testing')) {
            config(['app.debug' => false]);
        }

        on('call', function (Component $component, string $method): void {
            if (! str_starts_with($method, '$') && ! str_starts_with($method, '__')) {
                store($component)->set('feedbackAction', $method);
            }
        });

        on('exception', function (Component $component, Throwable $e): void {
            if ($e instanceof ValidationException && store($component)->get('feedbackAction') !== null) {
                $fields = count($e->validator->errors()->keys());

                Notify::error(trans_choice('feedback.fields_need_attention', $fields, ['count' => Format::qty($fields)]), on: $component);
            }
        });
    }

    /**
     * Every row in `permission` is a gate, usable with Gate::allows(),
     * the Blade can directive and can: route middleware. The codes are read
     * lazily from the cache (App\Support\Permissions), so booting never needs
     * the database. Abilities that are not permission codes fall through to
     * policies and other gates.
     */
    private function registerPermissionGates(): void
    {
        Gate::before(function (User $user, string $ability) {
            return Permissions::exists($ability) ? $user->hasPermission($ability) : null;
        });

        Gate::define('warehouse.access', fn (User $user, Warehouse|string $warehouse) => $user->canAccessWarehouse(
            $warehouse instanceof Warehouse ? $warehouse->id : $warehouse,
        ));

        // Reports open for anyone who may see a report (the audit log needs audit.view only).
        Gate::define('reports.open', fn (User $user) => $user->hasPermission('report.view') || $user->hasPermission('audit.view'));

        // Each type's list, details and print pages (documents.receive, documents.delivery-note...):
        // its view permission, or creating / approving it (no dead end after saving).
        foreach (config('transactions.types') as $type) {
            $codes = array_filter([$type['view'], $type['create'], $type['approve'] ?? null, ...($type['view_also'] ?? [])]);
            Gate::define('documents.'.$type['slug'], fn (User $user) => collect($codes)->contains(fn (string $code) => $user->hasPermission($code)));
        }

        // Draft receipts / orders (drafts.in.open, drafts.out.open): their view
        // permission, or creating / confirming them.
        foreach (config('transactions.drafts') as $code => $draft) {
            Gate::define('drafts.'.strtolower($code).'.open', fn (User $user) => $user->hasPermission($draft['view'])
                || $user->hasPermission($draft['create']) || $user->hasPermission($draft['confirm']));
        }

        // Settings opens for anyone who may open at least one of its tabs
        // (config/ui.php `settings_tabs`); each tab checks its own permission.
        Gate::define('settings.open', fn (User $user) => collect(config('ui.settings_tabs'))
            ->contains(fn (array $tab) => $user->hasPermission($tab['permission'])));
    }
}
