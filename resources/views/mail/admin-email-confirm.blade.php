<x-mail::message :preheader="__('mail.admin_email_confirm.preheader')">
# {{ __('mail.admin_email_confirm.title', ['name' => \App\Support\Mail\MailLocale::firstName($name)]) }}

{{ __('mail.admin_email_confirm.intro', ['minutes' => $minutes]) }}

<x-mail::button :url="$link">
{{ __('mail.admin_email_confirm.action') }}
</x-mail::button>

{{ __('mail.admin_email_confirm.outro') }}

{{ __('mail.admin_email_confirm.signature') }}
</x-mail::message>
