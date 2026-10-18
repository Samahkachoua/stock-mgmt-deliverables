@use('App\Support\Format')

<div class="flex flex-col gap-section">
    <x-page-header icon="upload" :title="$import->title()" :subtitle="__('imports.upload.subtitle')" :back="route('imports')" :back-label="__('imports.title')" />

    <x-import.steps :current="1" />

    <form wire:submit="send" class="flex flex-col gap-section rounded-xl border border-default bg-card p-card shadow-card" novalidate>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lead font-semibold text-fg">{{ __('imports.upload.template_heading') }}</h2>
                <p class="mt-1 max-w-xl text-body text-muted">{{ __('imports.upload.template_hint') }}</p>
            </div>
            <a href="{{ route('imports.template', $import->slug()) }}" download class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg shadow-card hover:bg-card-hover">
                <x-icon name="download" class="text-muted" />
                {{ __('imports.upload.download_template') }}
            </a>
        </div>

        <div class="border-t border-default pt-4">
            <label for="import-file" class="mb-1 block text-body font-semibold text-fg">{{ __('imports.upload.file') }} <span class="text-danger" aria-hidden="true">*</span></label>
            <input
                id="import-file"
                type="file"
                wire:model="file"
                accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                @error('file') aria-invalid="true" aria-describedby="import-file-error" @enderror
                class="block w-full max-w-xl rounded-lg border border-control bg-card text-body text-fg file:me-4 file:h-control file:border-0 file:bg-surface file:px-4 file:font-semibold file:text-fg"
            >
            <p wire:loading.flex wire:target="file" class="mt-1 items-center gap-2 text-body text-muted"><x-spinner />{{ __('imports.upload.sending') }}</p>
            @error('file')
                <p id="import-file-error" class="mt-1 text-body text-danger" role="alert">{{ $message }}</p>
            @else
                <p class="mt-1 text-body text-muted">{{ __('imports.upload.file_hint', ['max' => Format::qty(config('imports.max_rows')), 'size' => Format::qty((int) (config('imports.max_kilobytes') / 1024))]) }}</p>
            @enderror
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-default pt-4">
            <a href="{{ route('imports') }}" wire:navigate class="flex h-control items-center rounded-lg px-4 text-body font-semibold text-fg hover:bg-card-hover">{{ __('transactions.form.cancel') }}</a>
            <x-submit-button target="send, file" icon="upload" :disabled="$file === null || $errors->has('file')" :busy="__('imports.upload.sending')">
                {{ __('imports.upload.button') }}
            </x-submit-button>
        </div>
    </form>
</div>
