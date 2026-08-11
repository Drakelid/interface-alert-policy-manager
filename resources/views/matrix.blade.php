@extends('layouts.librenmsv1') @section('title','IAPM Interface Matrix') @section('content')<div class="container-fluid">@include('iapm::partials.nav')<h2>Interface Matrix</h2>
{{-- P1-2: device group, device and location were free-text numeric boxes asking
     for internal primary keys. Groups and locations are small enough to
     enumerate, so they are selects; devices are not, so that one is a
     type-ahead that submits the id while showing the hostname.
     P3-2 gives every control a real label; P2-6 groups filtering apart from the
     bulk actions below, which previously ran together into one line. --}}
<form class="panel panel-default iapm-filter-bar" method="get">
    <div class="panel-body">
        <div class="iapm-field-grid">
            <div class="form-group">
                <label for="iapm-f-search">Interface or description</label>
                <input class="form-control" id="iapm-f-search" name="search" value="{{ request('search') }}" placeholder="e.g. xe-0/0/1 or CUST:">
            </div>
            <div class="form-group">
                <label for="iapm-f-devicegroup">Device group</label>
                <select class="form-control" id="iapm-f-devicegroup" name="device_group_id"><option value="">Any device group</option>@foreach($deviceGroups as $group)<option value="{{ $group->id }}" @selected((string) request('device_group_id')===(string) $group->id)>{{ $group->name }}</option>@endforeach</select>
            </div>
            @include('iapm::partials.typeahead',['name'=>'device_id','id'=>'iapm-f-device','label'=>'Device','endpoint'=>route('iapm.lookup.devices'),'placeholder'=>'Type a hostname…','value'=>request('device_id'),'valueLabel'=>$deviceFilterLabel])
            <div class="form-group">
                <label for="iapm-f-location">Location</label>
                <select class="form-control" id="iapm-f-location" name="location_id"><option value="">Any location</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected((string) request('location_id')===(string) $location->id)>{{ $location->location }}</option>@endforeach</select>
            </div>
            <div class="form-group">
                <label for="iapm-f-policy">Policy</label>
                <select class="form-control" id="iapm-f-policy" name="policy_id"><option value="">Any policy</option>@foreach($policies as $policy)<option value="{{ $policy->id }}" @selected(request('policy_id')==$policy->id)>{{ $policy->name }}</option>@endforeach</select>
            </div>
            <div class="form-group">
                <label for="iapm-f-source">Assignment source</label>
                <select class="form-control" id="iapm-f-source" name="assignment_source"><option value="">Any source</option>@foreach(['port','port_group','device','device_group','location','ifalias_regex','ifname_regex','interface_type','default','configured_default'] as $source)<option @selected(request('assignment_source')===$source)>{{ $source }}</option>@endforeach</select>
            </div>
            <div class="form-group">
                <label for="iapm-f-admin">Admin status</label>
                <select class="form-control" id="iapm-f-admin" name="admin"><option value="">Any admin</option>@foreach(['up','down'] as $v)<option @selected(request('admin')===$v)>{{ $v }}</option>@endforeach</select>
            </div>
            <div class="form-group">
                <label for="iapm-f-oper">Operational status</label>
                <select class="form-control" id="iapm-f-oper" name="oper"><option value="">Any oper</option>@foreach(['up','down'] as $v)<option @selected(request('oper')===$v)>{{ $v }}</option>@endforeach</select>
            </div>
            <div class="form-group">
                <label for="iapm-f-incident">Incident state</label>
                <select class="form-control" id="iapm-f-incident" name="incident_state"><option value="">Any incident</option>@foreach(['pending','active','acknowledged','suppressed','recovered'] as $v)<option @selected(request('incident_state')===$v)>{{ $v }}</option>@endforeach</select>
            </div>
        </div>
        <div class="iapm-checkbox-row">
            @foreach(['no_policy'=>'No policy','active_incident'=>'Open incident','muted'=>'Muted'] as $key=>$label)
            <label class="checkbox-inline" for="iapm-f-{{ $key }}"><input type="checkbox" id="iapm-f-{{ $key }}" name="{{ $key }}" value="1" @checked(request()->boolean($key))> {{ $label }}</label>
            @endforeach
            <span class="iapm-filter-actions">
                <button class="btn btn-primary"><i class="fa fa-filter"></i> Filter</button>
                <a class="btn btn-default" href="{{ route('iapm.matrix') }}">Reset</a>
                <a class="btn btn-default" href="{{ route('iapm.matrix.export',request()->query()) }}"><i class="fa fa-download"></i> Export CSV</a>
            </span>
        </div>
    </div>
</form>
{{-- P1-7: this was an info banner reading "Run `php artisan iapm:cache-rebuild`
     after broad policy or assignment changes", which a web-only administrator
     cannot act on. It is now a button, a last-rebuilt time, and a warning that
     appears only when the configuration has actually moved since. --}}
<div class="panel {{ $cache['stale'] ? 'panel-warning' : 'panel-default' }}" id="iapm-cache-panel">
    <div class="panel-body">
        <div class="iapm-toolbar" style="margin-bottom:0;">
            <span>
                <i class="fa fa-database"></i>
                <strong>Policy cache</strong>
                <span class="iapm-hint">&mdash; the policy, assignment-source and no-policy filters read it.</span>
            </span>
            <span class="spacer"></span>
            <span id="iapm-cache-state">
                @if($cache['rebuilt_at'])
                    Last rebuilt <span title="{{ $cache['rebuilt_at'] }}">{{ $cache['rebuilt_at_human'] }}</span>.
                @else
                    <span class="iapm-hint">Never rebuilt.</span>
                @endif
            </span>
            <form method="post" action="{{ route('iapm.matrix.rebuild-cache') }}" data-iapm-busy style="display:inline;">@csrf
                <button class="btn btn-default btn-sm" id="iapm-cache-rebuild" data-busy="Starting…" @disabled($cache['running'])><i class="fa fa-refresh"></i> Rebuild cache</button>
            </form>
        </div>
        @if($cache['stale'])
        <div class="alert alert-warning" style="margin:10px 0 0;">
            <i class="fa fa-exclamation-triangle"></i>
            <span @if($cache['changed_at']) title="Most recent change: {{ $cache['changed_at'] }}" @endif>Policies or assignments changed after the last rebuild</span>, so those three filters may be out of date. Rebuild to bring them current.
        </div>
        @endif
        <div id="iapm-cache-progress" class="alert alert-info" style="margin:10px 0 0;{{ $cache['running'] ? '' : 'display:none;' }}"></div>
        @if($cache['error'])<div class="alert alert-danger" style="margin:10px 0 0;">Last rebuild failed: {{ $cache['error'] }}</div>@endif
    </div>
</div>

@include('iapm::partials.result-count',['paginator'=>$rows,'noun'=>'interface'])

<form method="post" action="{{ route('iapm.matrix.bulk') }}">@csrf
{{-- P2-6: the bulk controls used to sit directly under the filter checkboxes,
     separated only by a full-width banner, so the two groups read as one row.
     They are now a labelled panel of their own, and the date input says what
     it is instead of being an unexplained datetime box. --}}
<div class="panel panel-default iapm-bulk-bar">
    <div class="panel-heading"><i class="fa fa-tasks"></i> Bulk action <span class="iapm-hint">&mdash; applies to the interfaces ticked below</span></div>
    <div class="panel-body">
        <div class="iapm-field-grid">
            <div class="form-group">
                <label for="iapm-bulk-op">Operation</label>
                <select class="form-control" id="iapm-bulk-op" name="operation"><option value="assign">Assign policy</option><option value="remove_assignment">Remove explicit assignment</option><option value="mute">Mute</option><option value="unmute">Unmute</option></select>
            </div>
            <div class="form-group">
                <label for="iapm-bulk-policy">Policy to assign</label>
                <select class="form-control" id="iapm-bulk-policy" name="policy_id"><option value="">Choose policy</option>@foreach($policies as $policy)<option value="{{ $policy->id }}">{{ $policy->name }}</option>@endforeach</select>
            </div>
            <div class="form-group">
                <label for="iapm-bulk-muted">Mute until</label>
                <input type="datetime-local" class="form-control" id="iapm-bulk-muted" name="muted_until" aria-describedby="iapm-bulk-muted-help">
                <p class="iapm-hint" id="iapm-bulk-muted-help">Required for Mute. Notifications resume after this time.</p>
            </div>
            <div class="form-group">
                <label class="iapm-invisible-label" for="iapm-bulk-apply">Apply</label>
                <button class="btn btn-warning form-control" id="iapm-bulk-apply" data-iapm-confirm="Apply this operation to the selected interfaces?">Apply to selected</button>
            </div>
        </div>
    </div>
</div><div class="iapm-table-wrap"><table class="table table-hover table-condensed iapm-sticky"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.iapm-port').forEach(e=>e.checked=this.checked)"></th><th class="iapm-num">port_id</th><th>Device</th><th>Interface</th><th>Description</th><th>Location</th><th>Admin</th><th>Oper</th><th>Policy</th><th>Assignment source</th><th>Incident</th><th>Tools</th></tr></thead><tbody>
{{-- P1-1: port_id was previously only the invisible value of each row's
     checkbox, yet Simulate Alert, Policy Test and Template Preview all demand
     it. It is now shown, copyable, and wired into per-row shortcuts. --}}
@foreach($rows as $row)@php($p=$row['port'])@php($portUrl=\LibreNMS\Plugins\InterfaceAlertPolicyManager\Support\LibreNmsRoutes::port($p->device_id,$p->port_id))<tr>
<td><input class="iapm-port" type="checkbox" name="port_ids[]" value="{{ $p->port_id }}" aria-label="Select {{ $p->device->hostname }} {{ $p->ifName }}"></td>
<td class="iapm-num"><code>{{ $p->port_id }}</code> <button type="button" class="btn btn-link btn-xs" data-iapm-copy-text="{{ $p->port_id }}" title="Copy port_id {{ $p->port_id }}" aria-label="Copy port_id {{ $p->port_id }}"><i class="fa fa-copy"></i></button></td>
<td><a href="{{ \LibreNMS\Plugins\InterfaceAlertPolicyManager\Support\LibreNmsRoutes::device($p->device_id) }}">{{ $p->device->hostname }}</a></td>
<td><a href="{{ $portUrl }}" title="Open {{ $p->ifName }} in LibreNMS">{{ $p->ifName }}</a></td>
<td class="iapm-truncate" title="{{ $p->ifAlias }}">{{ $p->ifAlias }}</td>
<td>{{ $p->device->location?->location }}</td>
<td>{{ $p->ifAdminStatus?->value }}</td>
<td>{{ $p->ifOperStatus?->value }}</td>
<td>@if($row['policy'])<a href="{{ route('iapm.policies.edit',$row['policy']) }}">{{ $row['policy']->name }}</a>@else<span class="text-warning">No policy</span>@endif</td>
<td title="{{ collect($row['candidates'])->map(fn($a)=>$a->policy->name)->implode(', ') }}">@if($row['winner'])<a href="{{ route('iapm.assignments.edit',$row['winner']) }}">{{ $row['winner']->assignment_type->value }}</a>@else<span class="iapm-hint">&mdash;</span>@endif</td>
<td>@if($row['incident'])<a href="{{ route('iapm.incidents.show',$row['incident']) }}">@include('iapm::partials.state-label',['state'=>$row['incident']->state->value])</a>@endif</td>
<td class="iapm-actions" style="white-space:nowrap;">
    <a class="btn btn-default btn-xs" href="{{ route('iapm.policy-test',['port_id'=>$p->port_id]) }}" title="Policy Test for port_id {{ $p->port_id }}" aria-label="Policy Test for {{ $p->ifName }}"><i class="fa fa-flask"></i></a>
    <a class="btn btn-default btn-xs" href="{{ route('iapm.simulate',['port_id'=>$p->port_id]) }}" title="Simulate an alert for port_id {{ $p->port_id }}" aria-label="Simulate an alert for {{ $p->ifName }}"><i class="fa fa-bolt"></i></a>
    <a class="btn btn-default btn-xs" href="{{ $portUrl }}" title="Open in LibreNMS" aria-label="Open {{ $p->ifName }} in LibreNMS"><i class="fa fa-external-link"></i></a>
</td>
</tr>@endforeach</tbody></table></div></form>{{ $rows->links() }}
<script>
(function () {
    // P1-7: poll the rebuild until it finishes so the operator gets real
    // progress and a completion message instead of an unexplained wait.
    var panel = document.getElementById('iapm-cache-panel');
    if (! panel) { return; }
    var progress = document.getElementById('iapm-cache-progress');
    var state = document.getElementById('iapm-cache-state');
    var button = document.getElementById('iapm-cache-rebuild');
    var timer = null;

    function render(data) {
        if (data.status === 'stalled') {
            progress.className = 'alert alert-danger';
            progress.style.display = '';
            progress.textContent = 'The rebuild was queued but nothing has picked it up. Check that the IAPM queue workers are running, or switch Delivery dispatch to Synchronous in Settings.';
            button.disabled = false;
            return false;
        }
        if (data.running) {
            progress.className = 'alert alert-info';
            progress.style.display = '';
            progress.textContent = data.total
                ? 'Rebuilding… ' + data.progress.toLocaleString() + ' of ' + data.total.toLocaleString() + ' interfaces.'
                : 'Rebuilding…';
            button.disabled = true;
            return true;
        }
        button.disabled = false;
        if (data.status === 'failed') {
            progress.className = 'alert alert-danger';
            progress.style.display = '';
            progress.textContent = 'Rebuild failed: ' + (data.error || 'unknown error');
            return false;
        }
        if (data.status === 'complete') {
            progress.className = 'alert alert-success';
            progress.style.display = '';
            progress.textContent = 'Rebuild complete — ' + data.progress.toLocaleString() + ' interfaces resolved. Reload to see the refreshed filters.';
            if (state && data.rebuilt_at_human) { state.textContent = 'Last rebuilt ' + data.rebuilt_at_human + '.'; }
            return false;
        }
        progress.style.display = 'none';
        return false;
    }

    function poll() {
        fetch('{{ route('iapm.matrix.cache-status') }}', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) { if (render(data)) { timer = setTimeout(poll, 2000); } })
            .catch(function () { clearTimeout(timer); });
    }

    @if($cache['running'])poll();@endif
    if (button) { button.form.addEventListener('submit', function () { setTimeout(poll, 1500); }); }
})();
</script>
</div>@endsection
