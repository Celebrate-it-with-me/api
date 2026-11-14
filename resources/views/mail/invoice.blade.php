<x-mail::message :preheader="__('mail.invoice.preheader')">
# {{ __('mail.invoice.title', ['name' => $name]) }}

{{ __('mail.invoice.intro', ['number' => $number, 'total' => $total, 'event' => $event]) }}

<x-mail::button :url="$purchasesUrl">
{{ __('mail.invoice.action') }}
</x-mail::button>
</x-mail::message>
