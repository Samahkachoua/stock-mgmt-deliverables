{!! $draft['subject'] !!}

{!! $draft['intro'] !!}

@if (filled($draft['reason']))
{!! __('drafts.mail.reason') !!}: {!! $draft['reason'] !!}
@endif
{!! __('drafts.mail.customer') !!}: {!! $draft['customer'] !!}
{!! $draft['branch_label'] !!}: {!! $draft['branch'] !!}
{!! __('drafts.mail.warehouse') !!}: {!! $draft['warehouse'] !!}
{!! __('drafts.mail.date') !!}: {!! $draft['date'] !!}
{!! __('drafts.mail.items') !!}: {!! $draft['summary'] !!}
@if (filled($draft['note']))
{!! __('drafts.mail.note') !!}: {!! $draft['note'] !!}
@endif

{!! $draft['open'] !!}: {!! $draft['url'] !!}

{!! $draft['why'] !!}
