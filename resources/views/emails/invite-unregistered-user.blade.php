@extends('emails.layouts.app')

@section('content')
    <h2 style="margin-top: 0;">You're Invited to Join an Event</h2>

    <p>Hello!</p>

    <p>
        You've been invited to collaborate on the event
        <strong>{{ $invite->event->event_name }}</strong> as a <strong>{{ ucfirst($invite->role) }}</strong>.
    </p>

    <p>
        To accept the invitation, please create an account or log in by clicking below:
    </p>

    <a href="{{ $acceptUrl }}" class="button">Accept Invitation</a>

    <p style="margin-top: 32px; font-size: 13px; color: #6b7280;">
        This link will expire in 7 days. If you didn’t expect this invitation, you can ignore it.
    </p>
@endsection
