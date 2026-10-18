<x-settings.layout>
    <section class="rounded-xl border border-default bg-card shadow-card">
        <div class="flex flex-wrap items-start justify-between gap-4 p-card">
            <div>
                <h2 class="text-h2 font-semibold text-fg">{{ __('settings.roles.heading') }}</h2>
                <p class="mt-1 text-body text-muted">{{ __('settings.roles.description') }}</p>
            </div>

            @unless ($adding)
                <button
                    type="button"
                    wire:click="add"
                    class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover"
                >
                    <x-busy-icon name="add" target="add" class="text-muted" />
                    {{ __('settings.roles.add') }}
                </button>
            @endunless
        </div>


        @if ($adding)
            <form wire:submit="create" class="mx-card mb-card rounded-xl border border-default bg-surface p-card" novalidate>
                <h3 class="text-lead font-semibold text-fg">{{ __('settings.roles.new_heading') }}</h3>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <x-form.input model="newName" :label="__('settings.roles.name')" required autofocus />
                    <x-form.input model="newCode" :label="__('settings.roles.code')" :hint="__('settings.roles.code_hint')" ltr required class="uppercase tabular" />
                </div>
                <p class="mt-3 text-body text-muted">{{ __('settings.roles.new_hint') }}</p>

                <div class="mt-4 flex flex-wrap justify-end gap-3">
                    <button type="button" wire:click="cancelAdd" class="h-control rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover">
                        {{ __('settings.cancel') }}
                    </button>
                    <x-submit-button target="create" icon="check" :disabled="$errors->hasAny(['newCode', 'newName'])" class="min-w-30">
                        {{ __('settings.roles.create') }}
                    </x-submit-button>
                </div>
            </form>
        @endif

        <form wire:submit="save" novalidate>
            <div class="relative overflow-x-auto border-t border-default">
                {{-- A matrix: it keeps its own sideways scroll on small screens, with the permission column pinned. --}}
                <table data-matrix class="w-full min-w-[720px] text-table xl:min-w-[960px]">
                    <thead>
                        <tr class="bg-surface align-top">
                            <th scope="col" class="sticky start-0 z-10 w-40 bg-surface px-cell py-cell-y text-start md:w-64 md:px-edge xl:w-80 text-small font-semibold uppercase tracking-wide text-muted">
                                {{ __('settings.roles.permission') }}
                            </th>
                            @foreach ($this->roles as $role)
                                <th scope="col" wire:key="role-head-{{ $role->id }}" class="min-w-28 px-2 py-3 text-center">
                                    <span class="block text-body font-semibold text-fg">{{ $role->label() }}</span>
                                    <span class="block text-small font-normal text-muted">{{ trans_choice('settings.roles.user_count', $role->users_count) }}</span>
                                    @unless ($role->is_system)
                                        <button
                                            type="button"
                                            wire:click="askDelete('{{ $role->id }}')"
                                            class="mt-1 inline-flex h-control items-center gap-1 rounded-lg px-2 text-small font-semibold text-danger hover:bg-danger-soft"
                                        >
                                            <x-busy-icon name="delete" target="askDelete('{{ $role->id }}')" class="size-4" />
                                            {{ __('settings.roles.delete') }}
                                            <span class="sr-only">{{ $role->label() }}</span>
                                        </button>
                                        @error('delete.'.$role->id)
                                            <span class="mt-1 block text-small font-normal text-danger" role="alert">{{ $message }}</span>
                                        @enderror
                                    @endunless
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    @foreach ($this->modules as $module => $permissions)
                        <tbody wire:key="module-{{ $module }}" class="border-t border-default">
                            <tr>
                                <th scope="colgroup" colspan="{{ $this->roles->count() + 1 }}" class="sticky start-0 bg-card px-3 pb-1 pt-4 text-start md:px-edge text-small font-semibold uppercase tracking-wide text-muted">
                                    {{ \App\Models\Permission::moduleLabel($module) }}
                                </th>
                            </tr>
                            @foreach ($permissions as $permission)
                                <tr wire:key="permission-{{ $permission->id }}" class="hover:bg-surface">
                                    <th scope="row" class="sticky start-0 z-10 max-w-40 bg-card px-cell py-1 text-start font-normal break-words md:max-w-none md:px-edge">
                                        <span class="block text-fg">{{ $permission->label() }}</span>
                                        <span dir="ltr" class="tabular text-small text-muted">{{ $permission->code }}</span>
                                    </th>
                                    @foreach ($this->roles as $role)
                                        <td class="px-2 text-center">
                                            <label class="inline-flex size-control cursor-pointer items-center justify-center">
                                                <input
                                                    type="checkbox"
                                                    wire:model="grants.{{ $role->id }}"
                                                    value="{{ $permission->id }}"
                                                    class="size-5 rounded border-control accent-brand"
                                                >
                                                <span class="sr-only">{{ $role->label() }}: {{ $permission->label() }}</span>
                                            </label>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-4 border-t border-default p-card">
                @error('grants')
                    <p class="me-auto text-body text-danger" role="alert">{{ $message }}</p>
                @enderror
                <p class="text-body text-muted">{{ __('settings.roles.save_hint') }}</p>
                <x-submit-button target="save" icon="check" class="min-w-30">
                    {{ __('settings.save') }}
                </x-submit-button>
            </div>
        </form>
    </section>

    <x-confirm-modal :confirm="$confirm" />
</x-settings.layout>
