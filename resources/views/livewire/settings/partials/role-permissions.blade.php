{{-- The permissions at the end of a Roles tree branch: one box each (x-data in roles.blade.php). The code is in the tooltip. --}}
@foreach ($permissions as $permission)
    @php $id = (string) $permission->id; @endphp
    <li x-show="match(@js($id))" wire:key="permission-{{ $id }}">
        <label class="flex min-h-control cursor-pointer items-center gap-3 rounded-lg px-3 hover:bg-surface" title="{{ $permission->code }}">
            <input
                type="checkbox"
                value="{{ $id }}"
                x-bind:checked="has(@js($id))"
                x-on:change="set([@js($id)], $event.target.checked)"
                class="size-5 shrink-0 rounded border-control accent-brand"
            >
            <span class="min-w-0 break-words text-fg">{{ $permission->label() }}</span>
        </label>
    </li>
@endforeach
