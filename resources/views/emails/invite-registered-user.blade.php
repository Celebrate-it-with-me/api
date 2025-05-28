@extends('emails.layouts.app')

@section('content')
    <h2 style="margin-top: 0;">You've Been Invited!</h2>

    <p>Hi there,</p>

    <p>
        You've been invited to collaborate on the event
        <strong>{{ $invite->event->event_name }}</strong> as a <strong>{{ ucfirst($invite->role) }}</strong>.
    </p>

    <p>
        Click the button below to open the event dashboard and start collaborating.
    </p>

    <a href="{{ $eventUrl }}" class="button">Open Event</a>

    <p style="margin-top: 32px; font-size: 13px; color: #6b7280;">
        If you were not expecting this invitation, feel free to ignore it.
    </p>
@endsection
