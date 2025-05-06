<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>RSVP Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #999; padding: 6px; text-align: left; vertical-align: top; }
        th { background-color: #f2f2f2; }
        h2 { margin-bottom: 10px; }
        .companion-row td {
            padding-left: 20px;
            background-color: #f9f9f9;
        }
        .type-label {
            font-weight: bold;
            color: #555;
            font-size: 11px;
        }
    </style>
</head>
<body>
<h2>RSVP Report - {{ $event->event_name }}</h2>

<table>
    <thead>
    <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Status</th>
        <th>Type</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($guests as $guest)
        <!-- Main Guest -->
        <tr>
            <td>{{ $guest->name }}</td>
            <td>{{ $guest->email }}</td>
            <td>{{ $guest->phone }}</td>
            <td>{{ ucfirst($guest->rsvp_status) }}</td>
            <td class="type-label">Main Guest</td>
        </tr>

        <!-- Companions -->
        @foreach ($guest->companions as $companion)
            <tr class="companion-row">
                <td>↳ {{ $companion->name ?? 'Unnamed' }}</td>
                <td>{{ $companion->email ?? '' }}</td>
                <td>{{ $companion->phone ?? '' }}</td>
                <td>{{ ucfirst($companion->rsvp_status ?? 'pending') }}</td>
                <td class="type-label">Companion</td>
            </tr>
        @endforeach
    @endforeach
    </tbody>
</table>
</body>
</html>
