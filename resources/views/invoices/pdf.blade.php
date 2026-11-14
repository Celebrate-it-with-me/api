<!doctype html>
<html lang="{{ $doc->locale }}">
<head>
<meta charset="utf-8">
<title>{{ $doc->number }}</title>
<style>
    @page { margin: 42px 48px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #4a413c; line-height: 1.45; }
    .brand { font-family: 'DejaVu Serif', serif; font-size: 24px; color: #933a50; letter-spacing: 1px; }
    .rule { border-bottom: 2px solid #c9a24e; margin: 8px 0 22px; }
    h1 { font-family: 'DejaVu Serif', serif; font-size: 17px; color: #2b2420; margin: 0 0 2px; }
    .muted { color: #6f6460; }
    table { width: 100%; border-collapse: collapse; }
    .cols td { vertical-align: top; width: 50%; padding-right: 16px; }
    .label { font-size: 8.5px; text-transform: uppercase; letter-spacing: 1px; color: #6f6460; margin-bottom: 3px; }
    .items { margin-top: 24px; }
    .items th { background: #f5eee6; color: #2b2420; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .6px; padding: 7px 8px; border-bottom: 1px solid #ede3d8; }
    .items td { padding: 8px; border-bottom: 1px solid #ede3d8; }
    .r { text-align: right; }
    .totals { margin-top: 14px; width: 46%; margin-left: 54%; }
    .totals td { padding: 4px 8px; }
    .totals .grand td { font-size: 13px; color: #933a50; border-top: 2px solid #c9a24e; padding-top: 8px; font-weight: bold; }
    .badge { display: inline-block; padding: 2px 8px; border: 1px solid #933a50; color: #933a50; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; }
    .foot { margin-top: 34px; font-size: 9px; color: #6f6460; border-top: 1px solid #ede3d8; padding-top: 10px; }
</style>
</head>
<body>
<table>
    <tr>
        <td><div class="brand">Quinvite</div></td>
        <td class="r">
            <h1>{{ $isCredit ? __('invoice.credit_note') : __('invoice.invoice') }}</h1>
            <div>{{ $doc->number }}</div>
            @if (! $isCredit && $doc->status === 'void')<div class="badge">{{ __('invoice.void') }}</div>@endif
        </td>
    </tr>
</table>
<div class="rule"></div>

<table class="cols">
    <tr>
        <td>
            <div class="label">{{ __('invoice.issuer') }}</div>
            <strong>{{ $invoice->issuer['name'] }}</strong><br>
            {{ $invoice->issuer['address'] }}<br>
            {{ $invoice->issuer['tax_id'] }}<br>
            {{ $invoice->issuer['email'] }}
        </td>
        <td>
            <div class="label">{{ __('invoice.bill_to') }}</div>
            <strong>{{ $invoice->recipient['name'] }}</strong><br>
            @foreach (['address', 'city', 'state', 'postal_code', 'country', 'tax_id'] as $field)
                @if (! empty($invoice->recipient[$field])){{ $invoice->recipient[$field] }}<br>@endif
            @endforeach
            {{ $invoice->recipient['email'] }}
        </td>
    </tr>
    <tr><td colspan="2" style="padding-top:14px">
        <span class="muted">{{ __('invoice.date') }}:</span> {{ $date($doc->issued_at) }}
        @if ($isCredit)
            &nbsp;&middot;&nbsp;<span class="muted">{{ __('invoice.refers_to') }}:</span> {{ $invoice->number }}
        @else
            &nbsp;&middot;&nbsp;<span class="muted">{{ __('invoice.payment') }}:</span> {{ __('invoice.methods.' . $invoice->payment_method) }}
            @if ($invoice->payment_reference) ({{ $invoice->payment_reference }})@endif
        @endif
    </td></tr>
</table>

@if ($isCredit)
    <table class="items">
        <thead><tr><th>{{ __('invoice.description') }}</th><th class="r">{{ __('invoice.amount') }}</th></tr></thead>
        <tbody><tr>
            <td>{{ __('invoice.refund_of', ['number' => $invoice->number]) }}<br><span class="muted">{{ $doc->reason }}</span></td>
            <td class="r">-{{ $money($doc->amount_cents) }}</td>
        </tr></tbody>
    </table>
    <table class="totals">
        @if ($doc->tax_cents > 0)<tr><td>{{ __('invoice.tax_included', ['rate' => rtrim(rtrim(number_format($invoice->tax_rate, 2), '0'), '.')]) }}</td><td class="r">-{{ $money($doc->tax_cents) }}</td></tr>@endif
        <tr class="grand"><td>{{ __('invoice.total_credited') }}</td><td class="r">-{{ $money($doc->amount_cents) }}</td></tr>
    </table>
@else
    <table class="items">
        <thead><tr>
            <th>{{ __('invoice.description') }}</th><th class="r">{{ __('invoice.qty') }}</th>
            <th class="r">{{ __('invoice.unit') }}</th><th class="r">{{ __('invoice.amount') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($invoice->lines as $line)
            <tr>
                <td>{{ __('invoice.lines.' . $line['description_key']) }}@if (! empty($line['event_name']))<br><span class="muted">{{ $line['event_name'] }}</span>@endif</td>
                <td class="r">{{ $line['quantity'] }}</td>
                <td class="r">{{ $money($line['unit_cents']) }}</td>
                <td class="r">{{ $money($line['total_cents']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td>{{ __('invoice.subtotal') }}</td><td class="r">{{ $money($invoice->subtotal_cents) }}</td></tr>
        @if ($invoice->discount_cents > 0)<tr><td>{{ __('invoice.discount') }}</td><td class="r">-{{ $money($invoice->discount_cents) }}</td></tr>@endif
        <tr><td>{{ __('invoice.tax_included', ['rate' => rtrim(rtrim(number_format($invoice->tax_rate, 2), '0'), '.') ?: '0']) }}</td><td class="r">{{ $money($invoice->tax_cents) }}</td></tr>
        <tr class="grand"><td>{{ __('invoice.total') }}</td><td class="r">{{ $money($invoice->total_cents) }}</td></tr>
    </table>
@endif

<div class="foot">{{ __('invoice.footer') }} &middot; {{ $invoice->issuer['website'] ?? '' }}</div>
</body>
</html>
