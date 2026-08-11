{{--
    Click-to-insert placeholder chips (P2-1 / P4-4).

    The names come from TemplateContextBuilder, so a chip can never insert a
    placeholder the renderer would reject on save.

    Expects: $target (CSS selector of the textarea to insert into).
    Optional: $placeholders (defaults to the interface set).
--}}
@php($iapmChips = $placeholders ?? \LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\TemplateContextBuilder::INTERFACE_PLACEHOLDERS)
<div class="iapm-chips" data-iapm-chip-target="{{ $target }}">
    <span class="iapm-hint">Click to insert:</span>
    @foreach($iapmChips as $placeholder)
    <button type="button" class="btn btn-default btn-xs iapm-chip" data-iapm-chip="{{ $placeholder }}">{{ $placeholder }}</button>
    @endforeach
</div>
