@extends('emails.layouts.app')

@section('content')
    <h2 style="margin-top: 0;">Reset your Password</h2>

    <p>Hi {{ $user->name }},</p>

    <p>
        We received a request to reset your password for your <strong>Celebrate It</strong> account.
        To proceed, please click the button below to set a new password.
    </p>

    <a href="{{ $resetPasswordLink }}" class="button">Reset Password</a>

    <p style="margin-top: 32px; font-size: 13px; color: #6b7280;">
        If you did not request a password reset, you can safely ignore this message.
    </p>
@endsection
