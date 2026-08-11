@extends('layouts.librenmsv1') @section('title','IAPM Assignment') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h1 class="iapm-page-title">{{ $assignment->exists?'Edit':'Create' }} Assignment</h1>
<p class="iapm-hint">Map interfaces to a policy. Choose a type below and only the relevant fields appear. Use <strong>Preview match count</strong> to see how many interfaces it currently affects before saving.</p>

@php($selectedType = old('assignment_type',$assignment->assignment_type?->value ?? 'default'))
@php($selectedGroups = old('device_group_ids', $assignment->deviceGroups->pluck('device_group_id')->map(fn($id)=>(string)$id)->all()))
<form method="post" action="{{ $assignment->exists?route('iapm.assignments.update',$assignment):route('iapm.assignments.store') }}" id="iapm-assignment-form">@csrf @if($assignment->exists)@method('PUT')@endif

<div class="row"><div class="col-md-7">
<div class="form-group"><label for="iapm-as-policy">Policy</label>
    <select name="policy_id" id="iapm-as-policy" class="form-control" required>@foreach($policies as $p)<option value="{{ $p->id }}" @selected(old('policy_id',$assignment->policy_id)==$p->id)>{{ $p->name }}@unless($p->enabled) (disabled)@endunless</option>@endforeach</select>
    <p class="iapm-hint">The policy applied to interfaces this assignment matches.</p>
</div>

<div class="form-group"><label for="iapm-type">Match by</label>
    <select name="assignment_type" id="iapm-type" class="form-control">
        @foreach(['default'=>'Default (everything not matched more specifically)','port'=>'Specific port','port_group'=>'Port group','device'=>'Device','device_group'=>'Device group(s)','location'=>'Location','ifalias_regex'=>'Interface description (ifAlias) regex','ifname_regex'=>'Interface name (ifName) regex','interface_type'=>'Interface type'] as $v=>$label)
        <option value="{{ $v }}" @selected($selectedType===$v)>{{ $label }}</option>@endforeach
    </select>
</div>

{{-- Default catch-all warning --}}
<div class="alert alert-info iapm-field" data-types="default"><i class="fa fa-info-circle"></i> A <strong>default</strong> assignment applies this policy to <strong>every interface</strong> not matched by a more specific assignment. Usually you want only one.</div>

{{-- Device type-ahead (search endpoint; scales to very large device counts) --}}
<div class="form-group iapm-field" data-types="device" style="position:relative;">
    <label for="iapm-dev-search">Device</label>
    <input type="text" class="form-control" id="iapm-dev-search" aria-describedby="iapm-dev-help" autocomplete="off" placeholder="Type a hostname to search…" value="{{ $deviceLabel }}" disabled>
    <input type="hidden" name="assignment_reference" id="iapm-dev-id" value="{{ $selectedType==='device' ? old('assignment_reference',$assignment->assignment_reference) : '' }}" disabled>
    <div id="iapm-dev-results" class="list-group" style="display:none;position:absolute;z-index:1000;width:100%;max-height:240px;overflow:auto;margin-top:-6px;box-shadow:0 2px 6px rgba(0,0,0,.15);"></div>
    <p class="iapm-hint" id="iapm-dev-help">Start typing and pick a device from the list. All interfaces on the selected device.</p>
</div>

{{-- Port group dropdown --}}
<div class="form-group iapm-field" data-types="port_group">
    <label for="iapm-as-portgroup">Port group</label>
    <select name="assignment_reference" id="iapm-as-portgroup" class="form-control iapm-pgsel" disabled>
        <option value="">— select a port group —</option>
        @foreach($portGroups as $g)<option value="{{ $g->id }}" @selected(old('assignment_reference',$assignment->assignment_reference)==$g->id)>{{ $g->name }}</option>@endforeach
    </select>
    <p class="iapm-hint">All interfaces in this LibreNMS port group.</p>
</div>

{{-- Specific port. There are far too many to enumerate, so this is the shared
     interface search; it writes the port_id into assignment_reference, and the
     raw box stays for operators who already have the id (P1-2 / P0-6). --}}
<div class="form-group iapm-field" data-types="port">
    @include('iapm::partials.port-picker',[
        'id' => 'iapm-assign-port',
        'name' => 'assignment_reference',
        'idLabel' => 'Port ID',
        'value' => $selectedType==='port' ? old('assignment_reference',$assignment->assignment_reference) : '',
        'valueLabel' => $portLabel,
    ])
</div>

{{-- Location dropdown --}}
<div class="form-group iapm-field" data-types="location">
    <label for="iapm-as-location">Location</label>
    <select name="assignment_reference" id="iapm-as-location" class="form-control iapm-locsel" disabled>
        <option value="">— select a location —</option>
        @foreach($locations as $loc)<option value="{{ $loc->id }}" @selected(old('assignment_reference',$assignment->assignment_reference)==$loc->id)>{{ $loc->location }}</option>@endforeach
    </select>
    <p class="iapm-hint">All interfaces on devices at this location.</p>
</div>

{{-- Interface type --}}
<div class="form-group iapm-field" data-types="interface_type">
    <label for="iapm-as-iftype">Interface type (ifType)</label>
    <input name="assignment_reference" id="iapm-as-iftype" class="form-control iapm-typeval" disabled value="{{ old('assignment_reference',$assignment->assignment_reference) }}" placeholder="e.g. ethernetCsmacd, sonet, gpon">
    <p class="iapm-hint">Matches the SNMP <code>ifType</code> string exactly.</p>
</div>

{{-- Regex --}}
<div class="form-group iapm-field" data-types="ifalias_regex,ifname_regex">
    <label for="iapm-regex">Regular expression</label>
    <input name="match_expression" id="iapm-regex" class="form-control iapm-regexval" disabled value="{{ old('match_expression',$assignment->match_expression) }}" placeholder="/^CUST:/">
    <p class="iapm-hint">PCRE with delimiters, e.g. <code>/^xe-/</code>. Validated when you save.</p>
</div>

{{-- Device groups multi-select + mode --}}
<div class="form-group iapm-field" data-types="device_group">
    <label for="iapm-as-groups">Device groups</label>
    <select name="device_group_ids[]" id="iapm-as-groups" class="form-control iapm-groupsel" multiple size="6" disabled>
        @foreach($deviceGroups as $g)<option value="{{ $g->id }}" @selected(in_array((string)$g->id,$selectedGroups,true))>{{ $g->name }}</option>@endforeach
    </select>
    <p class="iapm-hint">Hold Ctrl/Cmd to select several. Mode below controls how multiple groups combine.</p>
</div>

<div class="form-group iapm-field" data-types="device_group">
    <label for="iapm-as-mode">Group match mode</label>
    <select name="match_mode" id="iapm-as-mode" class="form-control">
        <option value="any" @selected(old('match_mode',$assignment->match_mode ?? 'any')==='any')>Match ANY selected group</option>
        <option value="all" @selected(old('match_mode',$assignment->match_mode)==='all')>Match ALL selected groups</option>
        <option value="exclude" @selected(old('match_mode',$assignment->match_mode)==='exclude')>EXCLUDE selected groups</option>
    </select>
</div>
{{-- match_mode still needs a value for non-group types --}}
<input type="hidden" name="match_mode" id="iapm-mode-fallback" value="any" disabled>

<div class="form-group iapm-field" data-types="port,port_group,device,device_group,location,ifalias_regex,ifname_regex,interface_type">
    <label for="iapm-as-receivers">Receivers <span class="iapm-hint">(optional, one per line)</span></label>
    <textarea name="receivers_text" id="iapm-as-receivers" class="form-control" rows="2">{{ old('receivers_text',implode("\n",$assignment->metadata_json['receivers']??[])) }}</textarea>
    <p class="iapm-hint">Overrides the policy/destination receiver for interfaces matched here.</p>
</div>

<div class="form-group"><label for="iapm-as-priority">Priority</label>
    <input type="number" name="priority" id="iapm-as-priority" class="form-control" value="{{ old('priority',$assignment->priority??0) }}">
    <p class="iapm-hint">Higher wins among assignments of the same type.</p>
</div>

<input type="hidden" name="enabled" value="0">
<div class="checkbox"><label for="iapm-as-enabled"><input type="checkbox" id="iapm-as-enabled" name="enabled" value="1" @checked(old('enabled',$assignment->exists?$assignment->enabled:true))> Enabled</label></div>

<button class="btn btn-primary">Save</button>
<button type="button" class="btn btn-default" id="iapm-preview-btn"><i class="fa fa-search"></i> Preview match count</button>
<span id="iapm-preview-result" class="iapm-hint" style="margin-left:8px;"></span>
</div></div>
</form>

@if($assignment->exists)<form method="post" action="{{ route('iapm.assignments.destroy',$assignment) }}" data-iapm-confirm="Delete this assignment?" style="margin-top:10px;">@csrf @method('DELETE')<button class="btn btn-danger">Delete assignment</button></form>@endif
</div>

<script>
(function () {
    var typeSelect = document.getElementById('iapm-type');
    var form = document.getElementById('iapm-assignment-form');

    function sync() {
        var type = typeSelect.value;
        // Show only fields whose data-types includes the current type.
        form.querySelectorAll('.iapm-field').forEach(function (el) {
            var show = el.dataset.types.split(',').indexOf(type) !== -1;
            el.style.display = show ? '' : 'none';
            // Disable hidden inputs so their (stale) values are not submitted.
            el.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = ! show; });
        });
        // match_mode: the group select owns it for device_group; otherwise submit a hidden default.
        document.getElementById('iapm-mode-fallback').disabled = (type === 'device_group');
    }

    typeSelect.addEventListener('change', sync);
    sync();

    // Device type-ahead: query the search endpoint and store the chosen device_id in
    // a hidden field, so we never render every device into the page.
    var devSearch = document.getElementById('iapm-dev-search');
    var devId = document.getElementById('iapm-dev-id');
    var devResults = document.getElementById('iapm-dev-results');
    if (devSearch) {
        var debounce;
        devSearch.addEventListener('input', function () {
            devId.value = ''; // cleared until the user picks a result
            var q = devSearch.value.trim();
            clearTimeout(debounce);
            if (q.length < 1) { devResults.style.display = 'none'; devResults.innerHTML = ''; return; }
            debounce = setTimeout(function () {
                fetch('{{ route('iapm.devices.search') }}?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
                    .then(function (r) { return r.json(); })
                    .then(function (list) {
                        devResults.innerHTML = '';
                        if (!list.length) { devResults.style.display = 'none'; return; }
                        list.forEach(function (item) {
                            var a = document.createElement('a');
                            a.href = '#'; a.className = 'list-group-item'; a.textContent = item.label;
                            a.addEventListener('click', function (e) { e.preventDefault(); devSearch.value = item.label; devId.value = item.id; devResults.style.display = 'none'; });
                            devResults.appendChild(a);
                        });
                        devResults.style.display = 'block';
                    }).catch(function () { devResults.style.display = 'none'; });
            }, 200);
        });
        document.addEventListener('click', function (e) { if (! devResults.contains(e.target) && e.target !== devSearch) devResults.style.display = 'none'; });
    }

    document.getElementById('iapm-preview-btn').addEventListener('click', function () {
        var result = document.getElementById('iapm-preview-result');
        result.textContent = 'Evaluating…';
        var body = new URLSearchParams(new FormData(form));
        // On the edit form the hidden _method=PUT would spoof this POST into a PUT
        // and hit the resource update route instead of the preview route — drop it.
        body.delete('_method');
        fetch('{{ route('iapm.assignments.preview') }}', {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        }).then(function (r) { return r.json().then(function (d) { return {ok: r.ok, d: d}; }); }).then(function (res) {
            if (!res.ok) { result.textContent = (res.d && res.d.message) ? res.d.message : 'Preview failed (check the fields).'; return; }
            if (res.d.error) { result.textContent = res.d.error; return; }
            var devices = (res.d.devices || 0) + ' device(s)';
            result.textContent = 'Matches ' + res.d.count + ' interface(s) across ' + devices + (res.d.capped ? ' (sampled; real total is higher)' : '') + '.';
        }).catch(function () { result.textContent = 'Preview failed.'; });
    });
})();
</script>
@endsection
