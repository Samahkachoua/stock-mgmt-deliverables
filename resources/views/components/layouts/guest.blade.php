@props(['title' => null])

<x-layouts.base :title="$title">
    <div class="flex min-h-screen flex-col">
        <div class="flex items-center justify-end gap-3 p-4">
            <livewire:theme-switcher />
            <x-language-switch />
        </div>

        <main class="flex flex-1 items-center justify-center px-4 pb-16">
            {{ $slot }}
        </main>
    </div>
</x-layouts.base>
