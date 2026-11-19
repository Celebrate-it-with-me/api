@php
    $t = fn (string $key) => __("exports.catering.$key", [], $r['locale']);
    $type = fn (string $k) => __("exports.catering.type_$k", [], $r['locale']);
@endphp
<!doctype html>
<html lang="{{ $r['locale'] }}">
<head>
<meta charset="utf-8">
<title>{{ $t('title') }} - {{ $r['event'] }}</title>
<style>
    @page { margin: 70px 40px 60px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #2b2420; line-height: 1.4; }
    header { position: fixed; top: -50px; left: 0; right: 0; border-bottom: 2px solid #c9a24e; padding-bottom: 6px; }
    header .event { font-family: 'DejaVu Serif', serif; font-size: 15px; color: #933a50; }
    header .meta { font-size: 9px; color: #6f6460; }
    h2 { font-family: 'DejaVu Serif', serif; font-size: 13px; margin: 18px 0 6px; color: #933a50; }
    h3 { font-size: 11px; margin: 12px 0 4px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    th { background: #f5eee6; text-align: left; font-size: 8.5px; text-transform: uppercase; padding: 4px 6px; border-bottom: 1px solid #ede3d8; }
    td { padding: 4px 6px; border-bottom: 1px solid #ede3d8; vertical-align: top; }
    .r { text-align: right; }
    .muted { color: #6f6460; }
    .note { background: #fdf6e3; border: 1px solid #c9a24e; padding: 5px 8px; margin: 6px 0; }
    .table-block { page-break-inside: avoid; margin-bottom: 14px; }
    .break { page-break-before: always; }
</style>
</head>
<body>
<header>
    <div class="event">{{ $r['event'] }}</div>
    <div class="meta">{{ $t('title') }}@if($r['date']) &middot; {{ $t('date') }}: {{ $r['date'] }}@endif</div>
</header>

<p class="muted">{{ $r['include_pending'] ? $t('includes_pending') : $t('only_confirmed') }} <strong>{{ $t('total') }}: {{ $r['total_people'] }}</strong></p>
@unless($r['menus'])<div class="note">{{ $t('note_menus_off') }}</div>@endunless
@unless($r['seating'])<div class="note">{{ $t('note_seating_off') }}</div>@endunless

@if($r['menus'])
<h2>{{ $t('sheet_summary') }}</h2>
@foreach($r['summary'] as $k => $block)
    <h3>{{ $type($k) }}</h3>
    <table>
        <tr><th>{{ $t('dish') }}</th><th>{{ $t('diet') }}</th><th class="r">{{ $t('count') }}</th></tr>
        @foreach($block['items'] as $item)
            <tr><td>{{ $item['name'] }}</td><td>{{ $item['diet'] }}</td><td class="r">{{ $item['count'] }}</td></tr>
        @endforeach
        <tr><td class="muted">{{ $t('unchosen') }}</td><td></td><td class="r">{{ $block['unchosen'] }}</td></tr>
    </table>
@endforeach
@endif

@if($r['seating'])
<div class="break"></div>
<h2>{{ $t('sheet_tables') }}</h2>
@foreach($r['tables'] as $table)
    <div class="table-block">
        <h3>{{ $table['name'] }} <span class="muted">({{ $table['people'] }}/{{ $table['capacity'] }})</span></h3>
        @if($r['menus'])
        <table>
            <tr><th>{{ $t('type') }}</th><th>{{ $t('dish') }}</th><th>{{ $t('diet') }}</th><th class="r">{{ $t('count') }}</th></tr>
            @foreach($table['dishes'] as $k => $block)
                @foreach($block['items'] as $item)
                    <tr><td>{{ $type($k) }}</td><td>{{ $item['name'] }}</td><td>{{ $item['diet'] }}</td><td class="r">{{ $item['count'] }}</td></tr>
                @endforeach
                @if($block['unchosen'])
                    <tr><td>{{ $type($k) }}</td><td class="muted">{{ $t('unchosen') }}</td><td></td><td class="r">{{ $block['unchosen'] }}</td></tr>
                @endif
            @endforeach
        </table>
        @endif
        <table>
            <tr><th>{{ $t('seat') }}</th><th>{{ $t('guest') }}</th>
                @foreach($r['types'] as $k)<th>{{ $type($k) }}</th>@endforeach
                <th>{{ $t('restrictions') }}</th></tr>
            @foreach($table['guests'] as $p)
                <tr><td>{{ $p['seat'] }}</td>
                    <td>{{ $p['name'] }}@if($p['companion_of']) <span class="muted">({{ $t('companion_of') }} {{ $p['companion_of'] }})</span>@endif @if($r['include_pending'] && $p['status'] === 'pending')<span class="muted">[{{ $t('status_pending') }}]</span>@endif</td>
                    @foreach($r['types'] as $k)<td>{{ $p['dishes'][$k] }}</td>@endforeach
                    <td>{{ $p['diets'] }}</td></tr>
            @endforeach
        </table>
    </div>
@endforeach

@if(count($r['no_table']))
<h2>{{ $t('no_table_people') }}</h2>
<table>
    <tr><th>{{ $t('guest') }}</th>@foreach($r['types'] as $k)<th>{{ $type($k) }}</th>@endforeach<th>{{ $t('restrictions') }}</th></tr>
    @foreach($r['no_table'] as $p)
        <tr><td>{{ $p['name'] }}@if($p['companion_of']) <span class="muted">({{ $t('companion_of') }} {{ $p['companion_of'] }})</span>@endif</td>
            @foreach($r['types'] as $k)<td>{{ $p['dishes'][$k] }}</td>@endforeach
            <td>{{ $p['diets'] }}</td></tr>
    @endforeach
</table>
@endif
@endif
</body>
</html>
