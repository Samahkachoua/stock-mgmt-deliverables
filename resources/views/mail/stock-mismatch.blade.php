{{ __('reconcile.mail.intro', ['count' => $total]) }}

@foreach ($shown as $row)
- {{ $row['warehouse'] }} / {{ $row['customer'] }} / {{ $row['item'] }}{{ $row['expiry'] ? ' / '.\App\Support\Format::date(\Illuminate\Support\Carbon::parse($row['expiry'])) : '' }}: {{ __('reconcile.mail.line', ['balance' => \App\Support\Format::qty($row['balance']), 'movements' => \App\Support\Format::qty($row['movements'])]) }}
@endforeach
@if ($total > count($shown))

{{ __('reconcile.mail.more', ['count' => $total - count($shown)]) }}
@endif

{{ __('reconcile.mail.what_next') }}
