<x-mail::message>
{{ __('sales.share.mail_intro') }}

{{ __('sales.share.mail_body', ['type' => mb_strtolower($type), 'number' => $number, 'total' => number_format($total, 0, ',', ' ').' XOF', 'company' => $company]) }}

@if ($note)
<x-mail::panel>
{{ $note }}
</x-mail::panel>
@endif

<x-mail::button :url="$url">
{{ __('sales.share.mail_button') }}
</x-mail::button>

{{ __('sales.share.mail_link_note') }}

{{ __('sales.share.mail_signature') }}<br>
{{ $company }}
</x-mail::message>
