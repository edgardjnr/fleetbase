@props(['rotulo'])
<table class="dados" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="dados-rotulo">{{ $rotulo }}</td>
<td class="dados-valor">{{ $slot }}</td>
</tr>
</table>
