{!! $note['subject'] !!}

{!! $note['intro'] !!}

@foreach ($note['facts'] as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach

@foreach ($note['lines'] as $line)
- {!! $line['item'] !!} ({!! $line['code'] !!}){!! $line['lot'] !== '' ? ', '.$line['lot'] : '' !!}: {!! $line['ordered'] !== '' ? __('deliveries.mail.ordered').' '.$line['ordered'].', ' : '' !!}{!! __('deliveries.mail.delivered') !!} {!! $line['delivered'] !!}{!! $line['note'] !== '' ? ' — '.$line['note'] : '' !!}
@endforeach

{!! $note['why'] !!}
