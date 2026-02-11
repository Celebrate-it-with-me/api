@extends('emails.layouts.app')

@section('content')
    <h2 style="margin-top: 0;">Payment reminders</h2>

    <p>Hi {{ $user->name }},</p>

    <p>
        This is a reminder about the following budget items for your event <strong>{{ $event->event_name }}</strong>:
    </p>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
        <thead>
            <tr>
                <th style="text-align: left; border-bottom: 1px solid #e5e7eb; padding: 8px;">Item</th>
                <th style="text-align: left; border-bottom: 1px solid #e5e7eb; padding: 8px;">Due date</th>
                <th style="text-align: right; border-bottom: 1px solid #e5e7eb; padding: 8px;">Estimated cost</th>
            </tr>
        </thead>
        <tbody>
            @foreach($budgetItems as $data)
                @php $item = $data['item']; $threshold = $data['threshold']; @endphp
                <tr>
                    <td style="border-bottom: 1px solid #e5e7eb; padding: 8px;">
                        {{ $item->title }}<br>
                        <small style="color: #6b7280;">{{ $threshold === 0 ? 'Due today' : "Due in $threshold days" }}</small>
                    </td>
                    <td style="border-bottom: 1px solid #e5e7eb; padding: 8px;">{{ $item->due_date->format('d/m/Y') }}</td>
                    <td style="border-bottom: 1px solid #e5e7eb; padding: 8px; text-align: right;">{{ number_format($item->estimated_cost, 2) }} {{ config('app.currency', '$') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <a href="{{ $url }}" class="button">View budget</a>

    <p style="margin-top: 32px; font-size: 13px; color: #6b7280;">
        If you have already made these payments, please mark them as paid in the application.
    </p>
@endsection
