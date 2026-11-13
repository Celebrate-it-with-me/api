@props(['preheader' => null])
<x-mail::layout :preheader="$preheader">
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.spa_url')">
Quinvite
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ __('mail.footer.tagline') }}
{{ __('mail.footer.help', ['email' => config('services.support.email')]) }}
© {{ date('Y') }} Quinvite
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
