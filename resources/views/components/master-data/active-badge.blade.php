{{-- "Active" / "Inactive" badge for master data rows. <x-master-data.active-badge :active="$row->active" /> --}}
@props(['active'])

<span {{ $attributes->class(['inline-flex h-6 items-center gap-1.5 whitespace-nowrap rounded-md border px-2 text-small font-semibold', config('ui.statuses.'.($active ? 'completed' : 'cancelled'))]) }}>
    {{ $active ? __('settings.active') : __('settings.inactive') }}
</span>
