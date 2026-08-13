@extends('layouts.librenmsv1') @section('title','IAPM SMS Content Filters') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h1 class="iapm-page-title">SMS content filters</h1>
<p class="iapm-hint" style="max-width:75em;">Remove unnecessary words, phrases, and symbols from SMS messages after template rendering. Filters apply to <strong>SMS gateway destinations only</strong>; they never change LibreNMS inventory, policy matching, stored templates, or generic webhook messages. Existing queued payloads keep the content captured when they were queued.</p>

<form method="post" action="{{ route('iapm.sms-content-filters.update') }}" id="iapm-sms-filter-form"
      data-preview-url="{{ route('iapm.sms-content-filters.preview') }}"
      data-default-phrases="{{ base64_encode($defaultPhrases) }}"
      data-default-symbols="{{ base64_encode($defaultSymbols) }}">@csrf @method('PUT')
    <div class="row">
        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Words and phrases</strong></div>
                <div class="panel-body">
                    <label for="iapm-sms-filter-phrases">One filter per line</label>
                    <textarea class="form-control" rows="12" name="phrases" id="iapm-sms-filter-phrases" style="font-family:monospace;">{{ old('phrases', $phrases) }}</textarea>
                    <p class="iapm-hint">Matching is case-insensitive and respects word boundaries. Add a trailing <code>*</code> to remove a prefix plus the remainder of that token; for example, <code>Bundle-Ether*</code> removes <code>Bundle-Ether10</code>. An immediately following <code>to</code> is removed too.</p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Symbols and exact text</strong></div>
                <div class="panel-body">
                    <label for="iapm-sms-filter-symbols">One filter per line</label>
                    <textarea class="form-control" rows="12" name="symbols" id="iapm-sms-filter-symbols" style="font-family:monospace;">{{ old('symbols', $symbols) }}</textarea>
                    <p class="iapm-hint">These entries are removed exactly wherever they occur. Use this list for decorative characters such as <code>#</code>. Do not add punctuation that carries useful meaning in device names, URLs, or timestamps.</p>
                </div>
            </div>
        </div>
    </div>

    @error('filters')<div class="alert alert-danger">{{ $message }}</div>@enderror

    <div class="panel panel-info">
        <div class="panel-heading"><strong>Live preview</strong> &mdash; nothing is saved or sent</div>
        <div class="panel-body">
            <div class="row">
                <div class="col-md-6">
                    <label for="iapm-sms-filter-sample">Example rendered SMS</label>
                    <textarea class="form-control" rows="8" id="iapm-sms-filter-sample">{{ $sample }}</textarea>
                </div>
                <div class="col-md-6">
                    <p><strong>Filtered result</strong></p>
                    <pre id="iapm-sms-filter-result" aria-live="polite" style="min-height:156px;white-space:pre-wrap;word-break:break-word;"></pre>
                    <p class="help-block text-danger" id="iapm-sms-filter-error" role="alert" style="display:none;"></p>
                </div>
            </div>
        </div>
    </div>

    <div class="iapm-form-footer">
        <button class="btn btn-primary"><i class="fa fa-save"></i> Save SMS filters</button>
        <button class="btn btn-default" type="button" id="iapm-sms-filter-defaults"><i class="fa fa-undo"></i> Restore recommended defaults</button>
        <span class="iapm-hint">Clear both lists and save to disable filtering.</span>
    </div>
</form>
</div>

<script>
(function () {
    var form = document.getElementById('iapm-sms-filter-form');
    var phrases = document.getElementById('iapm-sms-filter-phrases');
    var symbols = document.getElementById('iapm-sms-filter-symbols');
    var sample = document.getElementById('iapm-sms-filter-sample');
    var result = document.getElementById('iapm-sms-filter-result');
    var error = document.getElementById('iapm-sms-filter-error');
    var timer = null;
    var request = null;
    if (! form || ! window.fetch) { return; }

    function preview() {
        if (request) { request.abort(); }
        request = new AbortController();
        var data = new FormData();
        data.append('_token', form.querySelector('[name="_token"]').value);
        data.append('phrases', phrases.value);
        data.append('symbols', symbols.value);
        data.append('message', sample.value);
        fetch(form.dataset.previewUrl, { method: 'POST', body: data, headers: { 'Accept': 'application/json' }, signal: request.signal })
            .then(function (response) { return response.json().then(function (body) { return { ok: response.ok, body: body }; }); })
            .then(function (response) {
                if (! response.ok) { throw new Error(response.body.message || 'Preview failed.'); }
                result.textContent = response.body.filtered;
                error.style.display = 'none';
            })
            .catch(function (failure) {
                if (failure.name === 'AbortError') { return; }
                error.textContent = failure.message;
                error.style.display = '';
            });
    }
    function schedule() { clearTimeout(timer); timer = setTimeout(preview, 180); }
    [phrases, symbols, sample].forEach(function (field) { field.addEventListener('input', schedule); });
    document.getElementById('iapm-sms-filter-defaults').addEventListener('click', function () {
        phrases.value = atob(form.dataset.defaultPhrases);
        symbols.value = atob(form.dataset.defaultSymbols);
        preview();
    });
    preview();
})();
</script>
@endsection
