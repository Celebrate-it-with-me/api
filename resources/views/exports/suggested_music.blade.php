<!DOCTYPE html>
<html>
<head>
    <title>Suggested Music - {{ $event->title }}</title>
    <style>
        body { font-family: sans-serif; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 12px; }
        th { background-color: #f2f2f2; }
        .header { text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Suggested Music Playlist</h2>
        <h3>Event: {{ $event->title }}</h3>
        <p>Date: {{ date('Y-m-d H:i') }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>Artist</th>
                <th>Suggested By</th>
                <th>Score</th>
                <th>Up</th>
                <th>Down</th>
                <th>Created At</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $item)
                <tr>
                    <td>{{ $item->title }}</td>
                    <td>{{ $item->artist }}</td>
                    <td>{{ $item->suggested_by_name }}</td>
                    <td>{{ $item->score }}</td>
                    <td>{{ $item->upvotes }}</td>
                    <td>{{ $item->downvotes }}</td>
                    <td>{{ $item->created_at->format('Y-m-d') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
