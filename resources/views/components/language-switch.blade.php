{{-- EN / عربي / FR. Saves the choice and reloads the page in that language. --}}
<div class="inline-flex h-control items-center gap-1 rounded-lg bg-card-hover p-1" role="group" aria-label="{{ __('app.language') }}">
    @foreach (config('ui.locales') as $code => $label)
        @php($active = app()->getLocale() === $code)
        <form method="POST" action="{{ route('locale.update', $code) }}">
            @csrf
            <button
                type="submit"
                lang="{{ $code }}"
                aria-pressed="{{ $active ? 'true' : 'false' }}"
                @class([
                    'h-full min-w-10 rounded-md px-2 text-small font-semibold transition-colors',
                    'bg-card text-fg shadow-card' => $active,
                    'text-muted hover:text-fg' => ! $active,
                ])
            >{{ $label }}</button>
        </form>
    @endforeach
</div>
