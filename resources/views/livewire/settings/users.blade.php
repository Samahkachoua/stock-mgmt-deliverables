<x-settings.layout>
    <section class="rounded-xl border border-default bg-card shadow-card">
        <div class="flex flex-wrap items-start justify-between gap-4 p-card">
            <div>
                <h2 class="text-h2 font-semibold text-fg">{{ __('settings.users.heading') }}</h2>
                <p class="mt-1 text-body text-muted">{{ __('settings.users.description') }}</p>
            </div>

            @if ($editing === null)
                <button
                    type="button"
                    wire:click="add"
                    class="flex h-control items-center gap-2 rounded-lg bg-brand px-4 text-body font-semibold text-on-brand shadow-card hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand/40 focus:ring-offset-2 focus:ring-offset-card"
                >
                    <x-busy-icon name="add" target="add" />
                    {{ __('settings.users.add') }}
                </button>
            @endif
        </div>


        @if ($editing !== null)
            <form wire:submit="save" class="mx-card mb-card rounded-xl border border-default bg-surface p-card" novalidate>
                <h3 class="text-lead font-semibold text-fg">
                    {{ $editing === '' ? __('settings.users.new_heading') : __('settings.users.edit_heading', ['name' => $full_name]) }}
                </h3>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <x-form.input model="full_name" :label="__('settings.users.name')" required autofocus autocomplete="off" />
                    <x-form.input model="email" type="email" :label="__('settings.users.email')" ltr required autocomplete="off" />
                    <x-form.input
                        model="password"
                        type="password"
                        :label="__('settings.users.password')"
                        :hint="$editing === '' ? __('settings.users.password_hint_new') : __('settings.users.password_hint_edit')"
                        :required="$editing === ''"
                        autocomplete="new-password"
                    />
                    <x-form.select
                        model="preferred_lang"
                        :label="__('settings.users.language')"
                        :hint="__('settings.users.language_hint')"
                        :options="__('settings.users.language_names')"
                        required
                    />
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    @foreach ([
                        ['model' => 'roleIds', 'legend' => __('settings.users.roles'), 'hint' => __('settings.users.roles_hint'), 'options' => $this->roles->mapWithKeys(fn ($role) => [$role->id => $role->label()])],
                        ['model' => 'warehouseIds', 'legend' => __('settings.users.warehouses'), 'hint' => __('settings.users.warehouses_hint'), 'options' => $this->allWarehouses->mapWithKeys(fn ($warehouse) => [$warehouse->id => $warehouse->name.($warehouse->active ? '' : ' · '.__('settings.inactive'))])],
                    ] as $group)
                        <fieldset @error($group['model']) aria-invalid="true" aria-describedby="{{ $group['model'] }}-error" @enderror>
                            <legend class="text-body font-semibold text-fg">
                                {{ $group['legend'] }} <span class="text-danger" aria-hidden="true">*</span>
                            </legend>
                            <p class="mt-0.5 text-body text-muted">{{ $group['hint'] }}</p>
                            <div @class([
                                'mt-2 grid gap-x-4 rounded-lg border bg-card px-3 py-1 sm:grid-cols-2',
                                'border-danger' => $errors->has($group['model']),
                                'border-control' => ! $errors->has($group['model']),
                            ])>
                                @foreach ($group['options'] as $value => $text)
                                    <label wire:key="{{ $group['model'] }}-{{ $value }}" class="flex min-h-control cursor-pointer items-center gap-3">
                                        <input type="checkbox" wire:model.live="{{ $group['model'] }}" value="{{ $value }}" class="size-5 shrink-0 rounded border-control accent-brand">
                                        <span class="text-body text-fg">{{ $text }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error($group['model'])
                                <p id="{{ $group['model'] }}-error" class="mt-1 text-body text-danger" role="alert">{{ $message }}</p>
                            @enderror
                        </fieldset>
                    @endforeach
                </div>

                <label class="mt-4 flex min-h-control cursor-pointer items-center gap-3">
                    <input type="checkbox" wire:model.live="active" class="size-5 rounded border-control accent-brand">
                    <span class="text-body text-fg">{{ __('settings.users.can_sign_in') }}</span>
                </label>
                @error('active')
                    <p class="ms-8 text-body text-danger" role="alert">{{ $message }}</p>
                @enderror

                <div class="mt-4 flex flex-wrap justify-end gap-3">
                    <button type="button" wire:click="cancel" class="h-control rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover">
                        {{ __('settings.cancel') }}
                    </button>
                    <x-submit-button target="save" icon="check" :disabled="$errors->any()" class="min-w-30">
                        {{ __('settings.save') }}
                    </x-submit-button>
                </div>
            </form>
        @endif

        <div class="relative overflow-x-auto border-t border-default">
            <table class="cards w-full text-table xl:min-w-[760px]">
                <thead>
                    <tr class="h-10 bg-surface text-small font-semibold uppercase tracking-wide text-muted">
                        <th scope="col" class="px-edge text-start">{{ __('settings.users.name') }}</th>
                        <th scope="col" class="px-cell text-start">{{ __('settings.users.roles') }}</th>
                        <th scope="col" data-priority="2" class="px-cell text-start">{{ __('settings.users.warehouses') }}</th>
                        <th scope="col" class="px-cell text-start">{{ __('settings.status') }}</th>
                        <th scope="col" class="px-edge text-end"><span class="sr-only">{{ __('settings.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-default">
                    @foreach ($this->users as $user)
                        <tr wire:key="user-{{ $user->id }}" class="h-13 hover:bg-surface">
                            <td data-card="title" class="px-edge py-cell-y">
                                <div class="font-semibold text-fg">{{ $user->full_name }}</div>
                                <div class="text-muted"><span dir="ltr">{{ $user->email }}</span></div>
                            </td>
                            <td data-label="{{ __('settings.users.roles') }}" class="px-cell py-cell-y">
                                <div class="flex flex-wrap gap-1.5 max-md:justify-end">
                                    @forelse ($user->roles->sortBy('name') as $role)
                                        <span class="inline-flex h-6 items-center rounded-md border border-default bg-card-hover px-2 text-small font-semibold text-fg">{{ $role->label() }}</span>
                                    @empty
                                        <span class="text-muted">{{ __('settings.users.no_roles') }}</span>
                                    @endforelse
                                </div>
                            </td>
                            <td data-label="{{ __('settings.users.warehouses') }}" data-priority="2" class="px-cell py-cell-y">
                                <div class="flex flex-wrap gap-1.5 max-md:justify-end">
                                    @forelse ($user->warehouses->sortBy('name') as $warehouse)
                                        <span dir="ltr" title="{{ $warehouse->name }}" class="tabular rounded bg-card-hover px-1.5 py-0.5 text-small font-semibold tracking-wide text-muted">{{ $warehouse->code }}</span>
                                    @empty
                                        <span class="text-muted">{{ __('settings.users.no_warehouses') }}</span>
                                    @endforelse
                                </div>
                            </td>
                            <td data-card="badge" class="px-cell py-cell-y">
                                <span class="inline-flex h-6 items-center gap-1.5 rounded-md border px-2 text-small font-semibold {{ config('ui.statuses.'.($user->active ? 'completed' : 'cancelled')) }}">
                                    {{ $user->active ? __('settings.active') : __('settings.inactive') }}
                                </span>
                            </td>
                            <td class="px-edge py-cell-y text-end">
                                <button
                                    type="button"
                                    wire:click="edit('{{ $user->id }}')"
                                    class="inline-flex h-control items-center gap-1.5 rounded-lg px-3 text-body font-semibold text-fg hover:bg-card-hover"
                                >
                                    <x-busy-icon name="edit" target="edit('{{ $user->id }}')" class="size-4 text-muted" />
                                    {{ __('settings.edit') }}
                                    <span class="sr-only">{{ $user->full_name }}</span>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <x-confirm-modal :confirm="$confirm" />
</x-settings.layout>
