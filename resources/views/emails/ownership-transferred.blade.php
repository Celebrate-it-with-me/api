@extends('emails.layouts.app')

@section('content')
    <h2 style="margin-top: 0;">{{ $event->event_name }}</h2>

    <p>Hola {{ $user->name }} / Hi {{ $user->name }},</p>

    @if ($side === 'new')
        <p>Nuestro equipo te ha asignado como dueña de este evento. Ya puedes administrarlo por completo.</p>
        <p>Our team has made you the owner of this event. You can now manage it fully.</p>
    @else
        <p>Nuestro equipo ha movido la titularidad de este evento a otra cuenta. Tú sigues con acceso como editora.</p>
        <p>Our team has moved ownership of this event to another account. You keep access as an editor.</p>
    @endif

    <a href="{{ $eventUrl }}" class="button">Abrir el evento / Open the event</a>
@endsection
