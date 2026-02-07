@extends('emails.layouts.app')

@section('content')
    <h2 style="margin-top: 0;">Welcome, {{ $user->name }}! 🎊</h2>
    <p>
        Thank you for registering on <strong>Celebrate It</strong>. We’re thrilled to have you here!
    </p>
    <p>
        You can now create and manage your events, invite guests, and make your celebration unforgettable.
    </p>

    <a href="https://celebrateitwithme.com/dashboard" class="button">Get Started</a>
@endsection
