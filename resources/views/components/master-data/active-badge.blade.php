{{-- "Active" / "Inactive" badge for master data rows. <x-master-data.active-badge :active="$row->active" /> --}}
@props(['active'])

<x-badge :attributes="$attributes" :tone="$active ? 'completed' : 'cancelled'">{{ $active ? __('settings.active') : __('settings.inactive') }}</x-badge>
