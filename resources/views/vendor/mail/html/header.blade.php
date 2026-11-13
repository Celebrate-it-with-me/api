@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" class="wordmark" style="display: inline-block;">{!! $slot !!}</a>
<div class="rule">&nbsp;</div>
</td>
</tr>
