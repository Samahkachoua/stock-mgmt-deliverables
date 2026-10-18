<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ConfirmsActions;
use App\Livewire\Concerns\DefersRows;
use App\Livewire\Concerns\RemembersListState;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Notify;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * Shared behaviour of the simple master data screens (warehouses, customers,
 * units): a searchable, paginated list and an inline add/edit form above it.
 * Search and page are kept in the session, never in the URL.
 * Every save writes the record's before/after state to audit_log.
 *
 * A subclass names its model, fields, rules and view; it can veto a save with
 * check() (e.g. "this warehouse still has stock").
 *
 * @template TModel of Model
 */
#[Layout('components.layouts.app')]
abstract class MasterDataPage extends Component
{
    use ConfirmsActions, DefersRows, RemembersListState, WithoutUrlPagination, WithPagination;

    public const PER_PAGE = 10;

    public string $search = '';

    /** null = form closed, '' = adding, otherwise the id being edited (set by add() / edit()). */
    #[Locked]
    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /**
     * @return class-string<TModel>
     */
    abstract protected function model(): string;

    /** audit_log.entity, e.g. "warehouse". */
    abstract protected function entity(): string;

    /**
     * Form values for a new record.
     *
     * @return array<string, mixed>
     */
    abstract protected function blank(): array;

    /**
     * Validation rules, keyed form.<field>.
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;

    /**
     * @return array<string, string>
     */
    abstract protected function messages(): array;

    /**
     * @param  Builder<TModel>  $query
     */
    abstract protected function applySearch(Builder $query, string $search): void;

    abstract protected function viewName(): string;

    abstract protected function pageTitle(): string;

    /** Translation key prefix for "added" / "updated" messages, e.g. settings.customers. */
    abstract protected function langPrefix(): string;

    public function mount(): void
    {
        $this->authorize('viewAny', $this->model());
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->clearListState();
    }

    protected function listStateKey(): string
    {
        return 'settings.'.$this->entity();
    }

    protected function listStateProperties(): array
    {
        return ['search'];
    }

    public function add(): void
    {
        $this->authorize('create', $this->model());

        $this->open('', $this->blank());
    }

    public function edit(string $id): void
    {
        $record = $this->find($id);
        $this->authorize('update', $record);

        $this->open($id, $this->formFrom($record));
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->resetValidation();
    }

    public function updated(string $property): void
    {
        if ($this->editing !== null && str_starts_with($property, 'form.')) {
            $this->form = $this->normalize($this->form);
            $this->validateOnly($property);
        }
    }

    public function save(): void
    {
        $this->store(deactivationConfirmed: false);
    }

    protected function confirmed(string $action, string $id, string $reason): void
    {
        if ($action === 'deactivate' && $this->editing === $id) {
            $this->store(deactivationConfirmed: true);
        }
    }

    /**
     * Saves the form. Turning an active record off asks first (CLAUDE.md
     * "Confirmations"); a record that cannot be turned off says why (check()).
     */
    private function store(bool $deactivationConfirmed): void
    {
        if ($this->editing === null) {
            return;
        }

        $model = $this->model();
        $record = $this->editing === '' ? new $model : $this->find($this->editing);
        $this->authorize($record->exists ? 'update' : 'create', $record->exists ? $record : $model);

        $this->form = $this->normalize($this->form);
        $data = $this->validate()['form'];

        if ($problems = $this->check($record, $data)) {
            foreach ($problems as $field => $message) {
                $this->addError('form.'.$field, $message);
            }

            Notify::error(trans_choice('feedback.fields_need_attention', count($problems), ['count' => Format::qty(count($problems))]), on: $this);

            return;
        }

        $isNew = ! $record->exists;

        if (! $deactivationConfirmed && ! $isNew && $record->getAttribute('active') && ($data['active'] ?? true) === false) {
            $name = $this->displayName($record);

            $this->askToConfirm(
                'deactivate', (string) $record->getKey(),
                __('confirm.deactivate.'.$this->entity().'.title', ['name' => $name]),
                __('confirm.deactivate.'.$this->entity().'.body', ['name' => $name]),
                __('confirm.deactivate.'.$this->entity().'.button'),
            );

            return;
        }

        DB::transaction(function () use ($record, $data, $isNew) {
            $before = $isNew ? null : $this->snapshot($record);
            $record->fill($this->attributes($data))->save();
            $after = $this->snapshot($record);

            if ($before !== $after) {
                Audit::record($this->entity(), $record->getKey(), $isNew ? 'CREATE' : 'UPDATE', $before, $after);
            }
        });

        $this->editing = null;
        Notify::success(__($this->langPrefix().($isNew ? '.added' : '.updated'), ['name' => $this->displayName($record)]), on: $this);
    }

    public function render(): View
    {
        $model = $this->model();
        $query = $model::query();

        if (trim($this->search) !== '') {
            $this->applySearch($query, trim($this->search));
        }

        return view($this->viewName(), [
            'rows' => $this->rowsOnceLoaded(fn () => $this->pageInRange(fn () => $this->paginateRows(clone $query))),
        ])->title($this->pageTitle());
    }

    /**
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    protected function paginateRows(Builder $query): LengthAwarePaginator
    {
        return $query->orderBy('name')->paginate(self::PER_PAGE);
    }

    /**
     * Reasons the save can't happen, keyed by field (shown under that field).
     *
     * @param  TModel  $record
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function check(Model $record, array $data): array
    {
        return [];
    }

    /**
     * Form values → model attributes (empty optional text becomes null).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function attributes(array $data): array
    {
        return array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value, $data);
    }

    /**
     * Trim text and upper-case codes, so "bey " and "BEY" are the same code.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    protected function normalize(array $form): array
    {
        foreach ($form as $field => $value) {
            if (is_string($value)) {
                $form[$field] = $field === 'code' ? mb_strtoupper(trim($value)) : $value;
            }
        }

        return $form;
    }

    /**
     * @param  TModel  $record
     * @return array<string, mixed>
     */
    protected function formFrom(Model $record): array
    {
        return collect(array_keys($this->blank()))
            ->mapWithKeys(fn (string $field) => [$field => $record->getAttribute($field) ?? $this->blank()[$field]])
            ->all();
    }

    /**
     * What audit_log stores about the record.
     *
     * @param  TModel  $record
     * @return array<string, mixed>
     */
    protected function snapshot(Model $record): array
    {
        return $record->only(array_keys($this->blank()));
    }

    /**
     * @param  TModel  $record
     */
    protected function displayName(Model $record): string
    {
        return (string) $record->getAttribute('name');
    }

    /**
     * @return TModel
     */
    protected function find(string $id): Model
    {
        $model = $this->model();

        return $model::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function open(string $editing, array $form): void
    {
        $this->resetValidation();
        $this->editing = $editing;
        $this->form = $form;
    }
}
