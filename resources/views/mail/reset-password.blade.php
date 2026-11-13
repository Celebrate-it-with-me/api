<x-mail::message :preheader="__('mail.reset.preheader')">
# {{ __('mail.reset.title', ['name' => \App\Support\Mail\MailLocale::firstName($user->name)]) }}

{{ __('mail.reset.intro') }}

<x-mail::button :url="$resetPasswordLink">
{{ __('mail.reset.action') }}
</x-mail::button>

<x-slot:subcopy>
{{ __('mail.reset.outro') }}
</x-slot:subcopy>
</x-mail::message>
