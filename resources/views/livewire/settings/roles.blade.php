{{-- Settings › Roles: the roles at the start (a dropdown on a phone), the chosen role's permissions as a tree. --}}
<x-leave-guard>
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
                    class="flex h-control items-center gap-2 rounded-lg bg-brand px-4 text-body font-semibold text-on-brand shadow-card hover:bg-brand-add-hover focus:outline-none focus:ring-2 focus:ring-brand/40 focus:ring-offset-2 focus:ring-offset-card"
                >
                    <x-busy-icon name="add" target="add" />
                    {{ __('settings.roles.add') }}
                </button>
            @endunless
        </div>

        @if ($adding)
            <form wire:submit="create" class="mx-card mb-card rounded-xl border border-default bg-surface p-card" novalidate>
                <h3 class="text-lead font-semibold text-fg">{{ __('settings.roles.new_heading') }}</h3>
                <p class="mt-3 text-body text-muted">{{ __('settings.roles.new_hint') }}</p>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <x-form.input model="newName" :label="__('settings.roles.name')" required autofocus />
                    <x-form.input model="newCode" :label="__('settings.roles.code')" :hint="__('settings.roles.code_hint')" ltr required class="uppercase tabular" />
                </div>
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
            <div class="grid border-t border-default md:grid-cols-[15rem_minmax(0,1fr)] xl:grid-cols-[17rem_minmax(0,1fr)]">
                {{-- The roles: a list from tablet up, a dropdown on a phone. Changes to several roles wait together for Save. --}}
                <nav aria-label="{{ __('settings.roles.list_label') }}" class="max-md:hidden border-e border-default p-3">
                    <ul class="flex flex-col gap-1">
                        @foreach ($this->roles as $role)
                            @php $current = $role->id === $selectedRoleId; @endphp
                            <li wire:key="role-{{ $role->id }}">
                                <button
                                    type="button"
                                    wire:click="selectRole('{{ $role->id }}')"
                                    @if ($current) aria-current="true" @endif
                                    @class([
                                        'flex min-h-control w-full items-center gap-2 rounded-lg px-3 py-2 text-start focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40',
                                        'bg-brand-soft' => $current,
                                        'hover:bg-card-hover' => ! $current,
                                    ])
                                >
                                    <span class="min-w-0 flex-1">
                                        <span @class(['block break-words text-body font-semibold', 'text-link' => $current, 'text-fg' => ! $current])>{{ $role->label() }}</span>
                                        <span class="block text-small text-muted">{{ trans_choice('settings.roles.user_count', $role->users_count) }}</span>
                                    </span>
                                    @if (in_array($role->id, $changedRoleIds, true))
                                        <span class="size-2.5 shrink-0 rounded-full bg-status-pending" title="{{ __('settings.roles.unsaved') }}"></span>
                                        <span class="sr-only">{{ __('settings.roles.unsaved') }}</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </nav>

                <div class="px-card pt-card md:hidden">
                    <x-form.select
                        model="selectedRoleId"
                        :label="__('settings.roles.role')"
                        :options="$this->roles->mapWithKeys(fn ($role) => [$role->id => $role->label().(in_array($role->id, $changedRoleIds, true) ? ' · '.__('settings.roles.unsaved') : '')])->all()"
                    />
                </div>

                @if ($role = $this->selectedRole)
                    @php
                        $permissions = $this->modules->flatten();
                        $labels = $permissions->mapWithKeys(fn ($permission) => [
                            (string) $permission->id => mb_strtolower($permission->label().' '.$permission->code),
                        ])->all();
                        $nodes = collect($this->tree)->flatMap(fn ($section) => ['section-'.$section['key'], ...array_column($section['modules'], 'key')])->unique()->values()->all();
                    @endphp
                    <div
                        wire:key="role-tree-{{ $role->id }}"
                        x-data="{
                            role: @js((string) $role->id),
                            all: @js($allPermissionIds),
                            needs: @js((object) $requiredViews),
                            labels: @js($labels),
                            nodes: @js($nodes),
                            q: '',
                            open: {},
                            init() {
                                try { this.open = JSON.parse(localStorage.getItem('roles-tree-open') ?? '{}') ?? {} } catch (e) {}
                            },
                            remember() {
                                try { localStorage.setItem('roles-tree-open', JSON.stringify(this.open)) } catch (e) {}
                            },
                            searching() { return this.q.trim() !== '' },
                            isOpen(key, byDefault) { return this.searching() || (this.open[key] ?? byDefault) },
                            toggleOpen(key, byDefault) { this.open[key] = ! (this.open[key] ?? byDefault); this.remember() },
                            openAll(on) { this.nodes.forEach(key => this.open[key] = on); this.remember() },
                            match(id) { return ! this.searching() || (this.labels[id] ?? '').includes(this.q.trim().toLowerCase()) },
                            any(ids) { return ids.some(id => this.match(id)) },
                            get granted() { return ($wire.grants[this.role] ?? []).map(String) },
                            has(id) { return this.granted.includes(id) },
                            count(ids) { const granted = this.granted; return ids.filter(id => granted.includes(id)).length },
                            state(ids) { const n = this.count(ids); return n === 0 ? 'none' : (n === ids.length ? 'all' : 'some') },
                            countText(ids) { return @js(__('settings.roles.count')).replace(':granted', this.count(ids)).replace(':total', ids.length) },
                            {{-- Ticking an action ticks the area's See too; unticking See unticks the actions that need it. --}}
                            set(ids, on) {
                                const granted = new Set(this.granted);
                                if (on) {
                                    ids.forEach(id => { granted.add(id); if (this.needs[id]) granted.add(this.needs[id]) });
                                } else {
                                    ids.forEach(id => granted.delete(id));
                                    Object.entries(this.needs).forEach(([id, view]) => { if (! granted.has(view)) granted.delete(id) });
                                }
                                $wire.set('grants.' + this.role, [...granted], false);
                            },
                        }"
                        class="min-w-0 p-card"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="break-words text-lead font-semibold text-fg">{{ $role->label() }}</h3>
                                <p class="mt-0.5 text-small text-muted">
                                    {{ trans_choice('settings.roles.user_count', $role->users_count) }}
                                    @if ($role->is_system)
                                        · {{ __('settings.roles.built_in') }}
                                    @endif
                                </p>
                            </div>
                            @unless ($role->is_system)
                                <button
                                    type="button"
                                    wire:click="askDelete('{{ $role->id }}')"
                                    class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-3 text-body font-semibold text-danger hover:bg-danger-soft"
                                >
                                    <x-busy-icon name="delete" target="askDelete('{{ $role->id }}')" class="size-5" />
                                    {{ __('settings.roles.delete') }}
                                    <span class="sr-only">{{ $role->label() }}</span>
                                </button>
                            @endunless
                        </div>
                        @error('delete.'.$role->id)
                            <p class="mt-2 text-body text-danger" role="alert">{{ $message }}</p>
                        @enderror

                        <div class="mt-4 flex items-center gap-2">
                            <div class="relative min-w-0 flex-1 md:max-w-sm">
                                <x-icon name="search" class="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-subtle" />
                                <label for="roles-search" class="sr-only">{{ __('settings.roles.search') }}</label>
                                <input
                                    id="roles-search"
                                    type="search"
                                    x-model="q"
                                    {{-- Typing a search is not a change to save. --}}
                                    x-on:input.stop
                                    x-on:change.stop
                                    placeholder="{{ __('settings.roles.search') }}"
                                    class="h-control w-full appearance-none rounded-lg border border-control bg-card pe-3 ps-10 text-body text-fg placeholder:text-subtle focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
                                >
                            </div>
                            {{-- Icon only on a phone, so the search and both buttons fit on one row. --}}
                            <button type="button" x-on:click="openAll(true)" title="{{ __('settings.roles.expand_all') }}" class="flex h-control min-w-control shrink-0 items-center justify-center gap-2 rounded-lg border border-control bg-card px-3 text-body font-semibold text-fg hover:bg-card-hover max-md:px-0">
                                <x-icon name="unfold_more" class="text-muted" />
                                <span class="max-md:sr-only">{{ __('settings.roles.expand_all') }}</span>
                            </button>
                            {{-- Icon only on a phone, so the search and both buttons fit on one row. --}}
                            <button type="button" x-on:click="openAll(false)" title="{{ __('settings.roles.collapse_all') }}" class="flex h-control min-w-control shrink-0 items-center justify-center gap-2 rounded-lg border border-control bg-card px-3 text-body font-semibold text-fg hover:bg-card-hover max-md:px-0">
                                <x-icon name="unfold_less" class="text-muted" />
                                <span class="max-md:sr-only">{{ __('settings.roles.collapse_all') }}</span>
                            </button>
                        </div>
                        <p class="mt-2 text-small text-muted">{{ __('settings.roles.implied_hint') }}</p>

                        {{-- Every permission of this role in one go; half-ticked when only some are granted. --}}
                        <label x-show="! searching()" class="mt-3 flex min-h-control cursor-pointer items-center gap-3 rounded-lg border border-default bg-surface px-3">
                            <input
                                type="checkbox"
                                x-bind:checked="state(all) === 'all'"
                                x-effect="$el.indeterminate = state(all) === 'some'"
                                x-on:change="set(all, $event.target.checked)"
                                class="size-5 shrink-0 rounded border-control accent-brand"
                            >
                            <span class="flex-1 text-body font-semibold text-fg">
                                <span aria-hidden="true">{{ __('settings.roles.select_all') }}</span>
                                <span class="sr-only">{{ __('settings.roles.select_all_for', ['role' => $role->label()]) }}</span>
                            </span>
                            <span class="text-small text-muted tabular" x-text="countText(all)"></span>
                        </label>

                        <p x-show="searching() && ! any(all)" x-cloak class="mt-4 text-body text-muted" x-text="@js(__('settings.roles.no_results')).replace(':search', q.trim())"></p>

                        <ul class="mt-2 flex flex-col gap-1 text-table">
                            @foreach ($this->tree as $section)
                                @php $sectionIds = collect($section['modules'])->flatMap(fn ($module) => $module['permissions']->map(fn ($permission) => (string) $permission->id))->values()->all(); @endphp
                                <x-roles.tree-branch :key="'section-'.$section['key']" :label="$section['label']" :ids="$sectionIds" :open="true" section>
                                    @foreach ($section['modules'] as $module)
                                        @if (count($section['modules']) === 1)
                                            {{-- A section of one module (Reports): its permissions straight under it. --}}
                                            @include('livewire.settings.partials.role-permissions', ['permissions' => $module['permissions']])
                                        @else
                                            <x-roles.tree-branch :key="$module['key']" :label="$module['label']" :ids="$module['permissions']->map(fn ($permission) => (string) $permission->id)->values()->all()">
                                                @include('livewire.settings.partials.role-permissions', ['permissions' => $module['permissions']])
                                            </x-roles.tree-branch>
                                        @endif
                                    @endforeach
                                </x-roles.tree-branch>
                            @endforeach
                        </ul>
                    </div>
                @endif
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
</x-settings.layout>

<x-confirm-modal :confirm="$confirm" />
</x-leave-guard>
