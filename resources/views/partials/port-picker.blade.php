{{--
    Interface picker for the tools that take a raw port_id (P1-2 / P0-6).

    Policy Test, Simulate Alert and Template Preview all demanded a numeric
    LibreNMS port_id and pointed at the Interface Matrix to find one. The search
    box below writes the id into the same `port_id` field those tools already
    submit, so the raw number stays available for anyone who has it while nobody
    is forced to go looking for it.

    Expects: $value (current port_id), $valueLabel (its human label).
    Optional: $id (field id prefix), $required, $name (submitted field, default
              port_id -- the assignment form submits the same value as
              assignment_reference), $idLabel (label for the raw-id box).
--}}
@php($iapmPickerId = $id ?? 'iapm-port')
@php($iapmPickerName = $name ?? 'port_id')
<div class="form-group iapm-typeahead" style="position:relative;max-width:520px;"
     data-iapm-typeahead="{{ route('iapm.lookup.ports') }}">
    <label for="{{ $iapmPickerId }}-search">Interface</label>
    <input type="text" class="form-control" id="{{ $iapmPickerId }}-search" autocomplete="off"
           role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="{{ $iapmPickerId }}-search-results"
           placeholder="Search by hostname, interface name or description…" value="{{ $valueLabel ?? '' }}"
           data-iapm-typeahead-input aria-describedby="{{ $iapmPickerId }}-help">
    <input type="hidden" data-iapm-typeahead-value data-iapm-mirror="#{{ $iapmPickerId }}-id" value="{{ $value ?? '' }}">
    <div id="{{ $iapmPickerId }}-search-results" class="list-group iapm-typeahead-results" role="listbox" data-iapm-typeahead-results></div>
    <p class="iapm-hint" id="{{ $iapmPickerId }}-help">Pick an interface and its <code>port_id</code> fills in below. The Interface Matrix shows and copies the same id.</p>
</div>
<div class="form-group" style="max-width:220px;">
    <label for="{{ $iapmPickerId }}-id">{{ $idLabel ?? 'port_id' }}</label>
    <input type="number" class="form-control" id="{{ $iapmPickerId }}-id" name="{{ $iapmPickerName }}"
           value="{{ $value ?? '' }}" @if($required ?? false) required @endif aria-describedby="{{ $iapmPickerId }}-id-help">
    <p class="iapm-hint" id="{{ $iapmPickerId }}-id-help">Or paste a LibreNMS <code>port_id</code> directly.</p>
</div>
