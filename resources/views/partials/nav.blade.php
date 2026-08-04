@inject('iapmSettings', 'LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore')
@php($iapmDryRun = (bool) $iapmSettings->get('dry_run', true))
@php($iapmRoute = \Illuminate\Support\Facades\Route::currentRouteName())
@php($iapmActive = fn(...$names) => in_array($iapmRoute, $names, true) ? 'active' : '')

<div class="clearfix" style="margin-bottom:10px;">
    <ul class="nav nav-pills" style="display:inline-block;">
        <li class="{{ $iapmActive('iapm.overview') }}"><a href="{{ route('iapm.overview') }}"><i class="fa fa-dashboard"></i> Overview</a></li>
        <li class="dropdown {{ $iapmActive('iapm.incidents.index','iapm.incidents.show','iapm.matrix','iapm.stats') }}">
            <a class="dropdown-toggle" data-toggle="dropdown" href="#">Monitor <span class="caret"></span></a>
            <ul class="dropdown-menu">
                <li><a href="{{ route('iapm.incidents.index') }}">Active Incidents</a></li>
                <li><a href="{{ route('iapm.matrix') }}">Interface Matrix</a></li>
                <li><a href="{{ route('iapm.stats') }}">Statistics &amp; SLA</a></li>
            </ul>
        </li>
        <li class="dropdown {{ $iapmActive('iapm.policies.index','iapm.policies.create','iapm.policies.edit','iapm.assignments.index','iapm.assignments.create','iapm.assignments.edit','iapm.destinations.index','iapm.destinations.create','iapm.destinations.edit','iapm.schedules.index','iapm.schedules.create','iapm.schedules.edit') }}">
            <a class="dropdown-toggle" data-toggle="dropdown" href="#">Configure <span class="caret"></span></a>
            <ul class="dropdown-menu">
                <li class="dropdown-header">Follow in order</li>
                <li><a href="{{ route('iapm.settings.edit') }}">0. Generate ingestion token</a></li>
                <li><a href="{{ route('iapm.destinations.index') }}">1. Destinations</a></li>
                <li><a href="{{ route('iapm.policies.index') }}">2. Policies</a></li>
                <li><a href="{{ route('iapm.schedules.index') }}">3. Schedules <span class="text-muted">(optional)</span></a></li>
                <li><a href="{{ route('iapm.assignments.index') }}">4. Assignments</a></li>
                <li><a href="{{ route('iapm.setup-helper') }}">5. LibreNMS setup helper</a></li>
            </ul>
        </li>
        <li class="dropdown {{ $iapmActive('iapm.policy-test','iapm.template-preview','iapm.comparison-report','iapm.setup-helper','iapm.simulate','iapm.import.form') }}">
            <a class="dropdown-toggle" data-toggle="dropdown" href="#">Tools <span class="caret"></span></a>
            <ul class="dropdown-menu">
                <li><a href="{{ route('iapm.policy-test') }}">Policy Test</a></li>
                <li><a href="{{ route('iapm.template-preview') }}">Template Preview</a></li>
                <li><a href="{{ route('iapm.simulate') }}">Simulate Alert</a></li>
                <li><a href="{{ route('iapm.comparison-report') }}">Comparison Report</a></li>
                <li><a href="{{ route('iapm.import.form') }}">Import / Export</a></li>
                <li><a href="{{ route('iapm.setup-helper') }}">Setup Helper</a></li>
            </ul>
        </li>
        <li class="dropdown {{ $iapmActive('iapm.delivery-log','iapm.audit-log') }}">
            <a class="dropdown-toggle" data-toggle="dropdown" href="#">Logs <span class="caret"></span></a>
            <ul class="dropdown-menu">
                <li><a href="{{ route('iapm.delivery-log') }}">Delivery Log</a></li>
                <li><a href="{{ route('iapm.audit-log') }}">Audit Log</a></li>
            </ul>
        </li>
        <li class="{{ $iapmActive('iapm.settings.edit') }}"><a href="{{ route('iapm.settings.edit') }}"><i class="fa fa-cog"></i> Settings</a></li>
    </ul>
    <span class="pull-right" style="margin-top:6px;">
        @if($iapmDryRun)
            <a href="{{ route('iapm.settings.edit') }}" class="label label-warning" title="No external delivery. Click to change."><i class="fa fa-flask"></i> DRY-RUN</a>
        @else
            <span class="label label-success" title="Notifications are delivered to the gateway."><i class="fa fa-bolt"></i> LIVE</span>
        @endif
    </span>
</div>

@if(session('status'))<div class="alert alert-success alert-dismissible"><button type="button" class="close" data-dismiss="alert">&times;</button>{{ session('status') }}</div>@endif
@if(session('error'))<div class="alert alert-danger alert-dismissible"><button type="button" class="close" data-dismiss="alert">&times;</button>{{ session('error') }}</div>@endif
@if(session('new_ingestion_token'))<div class="alert alert-warning"><strong>Copy this ingestion token now — it is shown only once:</strong><pre style="margin-top:6px;">{{ session('new_ingestion_token') }}</pre></div>@endif
@if($errors->any())<div class="alert alert-danger"><strong>Please fix the following:</strong><ul style="margin-bottom:0;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
