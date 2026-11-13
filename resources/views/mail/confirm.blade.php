<x-mail::message :preheader="__('mail.confirm.preheader')">
# {{ __('mail.confirm.title', ['name' => \App\Support\Mail\MailLocale::firstName($user->name)]) }}

{{ __('mail.confirm.intro') }}

<x-mail::button :url="$confirmUrl">
{{ __('mail.confirm.action') }}
</x-mail::button>

<x-slot:subcopy>
{{ __('mail.confirm.outro') }}
</x-slot:subcopy>
</x-mail::message>
