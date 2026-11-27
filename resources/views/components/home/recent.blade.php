{{--
    Home "Recent activity": the documents and drafts the user last worked on
    (App\Support\Home\RecentActivity), one card of rows. Sized by its own
    width (container queries): narrow in the side column, wide on a phone.
    <x-home.recent :rows="$recent" />
--}}
@use('App\Support\Format')

@props(['rows'])

<section aria-labelledby="home-recent" class="@container flex min-w-0 flex-col overflow-hidden rounded-2xl border border-default bg-card shadow-card">
    <h2 id="home-recent" class="border-b border-default px-card py-3 text-h3 font-semibold text-fg">{{ __('home.recent.title') }}</h2>
    @if ($rows === [])
        <p class="p-card text-body text-muted">{{ __('home.recent.empty') }}</p>
    @else
        <ul class="divide-y divide-default">
            @foreach ($rows as $row)
                <li>
                    <a
                        href="{{ $row['url'] }}"
                        wire:navigate
                        data-recent-row
                        class="flex min-h-16 items-start gap-3 px-card py-3 transition-colors duration-150 hover:bg-card-hover active:bg-card-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand"
                    >
                        <span title="{{ $row['kind']->label('singular') }}" @class(['grid size-9 shrink-0 place-items-center rounded-xl', $row['kind']->colours()['soft']])>
                            <x-icon :name="$row['kind']->icon()" @class(['size-5', $row['kind']->colours()['icon']]) />
                        </span>
                        <span class="flex min-w-0 flex-1 flex-col gap-1">
                            <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-semibold text-fg tabular" dir="ltr">{{ $row['number'] }}</span>
                                @if ($row['draft'])
                                    <x-draft-status-badge :code="$row['status']" />
                                @else
                                    <x-status-badge :code="$row['status']" :type="$row['kind']" />
                                @endif
                            </span>
                            <span class="break-words text-small text-muted">{{ __('home.recent.line', ['customer' => $row['customer'], 'event' => __('home.recent.events.'.$row['event'])]) }}</span>
                            <time datetime="{{ $row['at']->toIso8601String() }}" class="text-small text-muted tabular">{{ Format::dateTime($row['at']) }}</time>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
