<x-mail::message :preheader="$preheader">
# {{ __('mail.admin_security.title', ['name' => \App\Support\Mail\MailLocale::firstName($name)]) }}

{{ $intro }}

{{ __('mail.admin_security.if_not_you', ['email' => $supportEmail]) }}

<x-mail::button :url="$accountUrl">
{{ __('mail.admin_security.action') }}
</x-mail::button>

{{ __('mail.admin_security.signature') }}
</x-mail::message>
