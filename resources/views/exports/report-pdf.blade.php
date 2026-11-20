{{--
    A report as a PDF (mPDF). Always light: the light theme's colours (App\Support\Palette: mPDF has no CSS variables).
    Arabic: the whole page is right to left; codes and references stay left to right.
--}}
@php
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
    $c = \App\Support\Palette::light();
    $tones = collect(['completed', 'pending', 'rejected', 'transit', 'cancelled'])->mapWithKeys(fn ($tone) => [$tone => $c["status-{$tone}"]])->all();
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: {{ \App\Reports\Export\Exporter::PDF_FONT }}; font-size: 9pt; color: {{ $c['fg'] }}; background: {{ $c['card'] }}; }
        h1 { font-size: 15pt; margin: 0 0 2mm 0; }
        .meta { color: {{ $c['muted'] }}; font-size: 8pt; margin: 0 0 1mm 0; }
        .summary { margin: 3mm 0; border-collapse: collapse; }
        .summary td { padding: 1.5mm 4mm 1.5mm 0; font-size: 9pt; }
        .summary .label { color: {{ $c['muted'] }}; }
        table.rows { width: 100%; border-collapse: collapse; margin-top: 3mm; }
        .logo { height: 12mm; margin: 0 0 3mm 0; }
        table.rows th { background: {{ $c['card-hover'] }}; color: {{ $c['brand-active'] }}; font-size: 7.5pt; text-transform: uppercase; text-align: {{ $start }}; padding: 2mm; border-bottom: 0.3mm solid {{ $c['subtle'] }}; }
        table.rows td { padding: 2mm; border-bottom: 0.2mm solid {{ $c['border'] }}; vertical-align: top; text-align: {{ $start }}; }
        table.rows .end { text-align: {{ $end }}; }
        .strong { font-weight: bold; }
        .empty { color: {{ $c['muted'] }}; margin-top: 6mm; }
    </style>
</head>
<body>
    <img class="logo" src="{{ public_path('images/brand/etl-logo-print.png') }}" alt="{{ __('app.logo_alt') }}">
    <h1>{{ $title }}</h1>
    <p class="meta">{{ __('reports.export.generated', ['date' => \App\Support\Format::dateTime($generatedAt), 'name' => $by]) }}</p>
    @foreach ($filters as $line)
        <p class="meta">{{ $line }}</p>
    @endforeach

    @if ($summary !== [])
        <table class="summary">
            <tr>
                @foreach ($summary as $item)
                    <td><span class="label">{{ $item['label'] }}:</span> <span class="strong">{{ $item['value'] }}</span></td>
                @endforeach
            </tr>
        </table>
    @endif

    @if ($rows->isEmpty())
        <p class="empty">{{ __('reports.page.empty_title') }}</p>
    @else
        <table class="rows">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th @class(['end' => ($column['align'] ?? null) === 'end'])>{{ __($column['label']) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $cells)
                    <tr>
                        @foreach ($columns as $key => $column)
                            @php
                                $cell = $cells[$key] ?? '';
                                $cell = is_string($cell) ? ['text' => $cell] : $cell;
                                $colour = isset($cell['tone']) ? ($tones[$cell['tone']] ?? null) : null;
                            @endphp
                            <td @class(['end' => ($column['align'] ?? null) === 'end', 'strong' => $cell['strong'] ?? false]) @if ($colour) style="color: {{ $colour }}" @endif>
                                @if ($cell['ltr'] ?? false)
                                    <span dir="ltr">{{ $cell['text'] }}</span>
                                @else
                                    {!! nl2br(e($cell['text'])) !!}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
