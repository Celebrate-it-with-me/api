<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>RSVP Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #999; padding: 6px; text-align: left; }
        th { background-color: #f2f2f2; }
        h2 { margin-bottom: 10px; }
    </style>
</head>
<body>
<h2>RSVP Report - {{ $event->event_name }}</h2>

<table>
    <thead>
    <tr>
        <th>Main Guest</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Status</th>
        <th>Companions</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($guests as $guest)
        <tr>
            <td>{{ $guest->name }}</td>
            <td>{{ $guest->email }}</td>
            <td>{{ $guest->phone }}</td>
            <td>{{ ucfirst($guest->rsvp_status) }}</td>
            <td>
                @foreach ($guest->companions as $companion)
                    • {{ $companion->name ?? 'Unnamed' }}<br>
                @endforeach
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
