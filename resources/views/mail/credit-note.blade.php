<x-mail::message :preheader="__('mail.credit_note.preheader')">
# {{ __('mail.credit_note.title', ['name' => $name]) }}

{{ __('mail.credit_note.intro', ['number' => $number, 'amount' => $amount, 'invoice' => $invoiceNumber, 'event' => $event]) }}

<x-mail::button :url="$purchasesUrl">
{{ __('mail.credit_note.action') }}
</x-mail::button>
</x-mail::message>
