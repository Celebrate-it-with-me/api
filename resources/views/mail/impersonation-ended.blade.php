<x-mail::message :preheader="__('mail.impersonation_ended.preheader')">
# {{ __('mail.impersonation_ended.title', ['name' => \App\Support\Mail\MailLocale::firstName($name)]) }}

{{ __('mail.impersonation_ended.intro', ['date' => $date, 'minutes' => $minutes, 'unit' => $unit, 'why' => $why]) }}

{{ __('mail.impersonation_ended.details', ['email' => $supportEmail]) }}

<x-mail::button :url="$supportUrl">
{{ __('mail.impersonation_ended.action') }}
</x-mail::button>

{{ __('mail.impersonation_ended.signature') }}
</x-mail::message>
