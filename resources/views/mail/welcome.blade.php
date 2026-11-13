<x-mail::message :preheader="__('mail.welcome.preheader')">
# {{ __('mail.welcome.title', ['name' => \App\Support\Mail\MailLocale::firstName($user->name)]) }}

{{ __('mail.welcome.intro') }}

{{ __('mail.welcome.step') }}

<x-mail::button :url="$dashboardUrl">
{{ __('mail.welcome.action') }}
</x-mail::button>
</x-mail::message>
