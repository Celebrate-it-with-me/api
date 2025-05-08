@extends('emails.layouts.app')

@section('content')
    <h2 style="margin-top: 0;">Confirm your email address</h2>

    <p>Hi {{ $user->name }},</p>

    <p>
        Thanks for signing up for <strong>Celebrate It</strong>. To complete your registration, please confirm your email address by clicking the button below.
    </p>

    <a href="{{ $confirmUrl }}" class="button">Confirm Email</a>

    <p style="margin-top: 32px; font-size: 13px; color: #6b7280;">
        If you did not create an account, you can safely ignore this message.
    </p>
@endsection
