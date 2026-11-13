<x-mail::message :preheader="__('mail.test.preheader')">
# {{ __('mail.test.title') }}

{{ __('mail.test.intro') }}

<x-mail::button :url="$dashboardUrl">
{{ __('mail.test.action') }}
</x-mail::button>
</x-mail::message>
