{{--
    Debounced name-for-id picker (P1-2).

    Submits the id in a hidden field while the visible box shows the name, so no
    screen asks an operator for an internal primary key. Follows the pattern the
    assignment form already established for its device search.

    Required: $name (submitted field), $endpoint (JSON [{id,label}]), $label.
    Optional: $value (current id), $valueLabel (current name), $placeholder,
              $help, $id (defaults to a slug of $name), $inline (compact layout),
              $dependsOn (id of another field whose value is sent as device_id).
--}}
@php($iapmFieldId = $id ?? 'iapm-ta-'.\Illuminate\Support\Str::slug($name))
<div class="{{ ($inline ?? false) ? 'form-group' : 'form-group' }} iapm-typeahead" style="position:relative;"
     data-iapm-typeahead="{{ $endpoint }}"@isset($dependsOn) data-iapm-typeahead-depends="{{ $dependsOn }}"@endisset>
    <label for="{{ $iapmFieldId }}" class="{{ ($inline ?? false) ? 'sr-only' : '' }}">{{ $label }}</label>
    <input type="text" class="form-control{{ ($inline ?? false) ? ' input-sm' : '' }}" id="{{ $iapmFieldId }}"
           autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list"
           aria-controls="{{ $iapmFieldId }}-results"
           placeholder="{{ $placeholder ?? $label }}" value="{{ $valueLabel ?? '' }}"
           data-iapm-typeahead-input
           @isset($help) aria-describedby="{{ $iapmFieldId }}-help" @endisset>
    <input type="hidden" name="{{ $name }}" value="{{ $value ?? '' }}" data-iapm-typeahead-value>
    <div id="{{ $iapmFieldId }}-results" class="list-group" role="listbox" data-iapm-typeahead-results
         style="display:none;position:absolute;z-index:1000;width:100%;max-height:240px;overflow:auto;margin-top:-6px;box-shadow:0 2px 6px rgba(0,0,0,.15);"></div>
    @isset($help)<p class="iapm-hint" id="{{ $iapmFieldId }}-help">{{ $help }}</p>@endisset
</div>
