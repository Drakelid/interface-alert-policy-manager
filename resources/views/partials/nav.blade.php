@inject('iapmSettings', 'LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore')
@inject('iapmVersion', 'LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PluginVersion')
@php($iapmDryRun = (bool) $iapmSettings->get('dry_run', true))
@php($iapmRoute = \Illuminate\Support\Facades\Route::currentRouteName())
@php($iapmActive = fn(...$names) => in_array($iapmRoute, $names, true) ? 'active' : '')

@include('iapm::partials.assets')

<div class="iapm-nav clearfix" style="margin-bottom:10px;">
    <ul class="nav nav-pills" style="display:inline-block;">
        <li class="{{ $iapmActive('iapm.overview') }}"><a href="{{ route('iapm.overview') }}"><i class="fa fa-dashboard fa-fw" aria-hidden="true"></i> Overview</a></li>
        <li class="dropdown {{ $iapmActive('iapm.incidents.index','iapm.incidents.show','iapm.matrix','iapm.stats') }}">
            <a class="dropdown-toggle" data-toggle="dropdown" href="#"><i class="fa fa-heartbeat fa-fw" aria-hidden="true"></i> Monitor <span class="caret"></span></a>
            <ul class="dropdown-menu">
                {{-- P2-7: this said "Active Incidents" but opens the open-incident
                     working set, which includes pending, acknowledged and
                     suppressed. The page heading now says the same thing. --}}
                <li><a href="{{ route('iapm.incidents.index') }}"><i class="fa fa-exclamation-circle fa-fw" aria-hidden="true"></i> Incidents</a></li>
                <li><a href="{{ route('iapm.matrix') }}"><i class="fa fa-table fa-fw" aria-hidden="true"></i> Interface Matrix</a></li>
                <li><a href="{{ route('iapm.stats') }}"><i class="fa fa-line-chart fa-fw" aria-hidden="true"></i> Statistics &amp; SLA</a></li>
            </ul>
        </li>
        <li class="dropdown {{ $iapmActive('iapm.policies.index','iapm.policies.create','iapm.policies.edit','iapm.destinations.index','iapm.destinations.create','iapm.destinations.edit') }}">
            <a class="dropdown-toggle" data-toggle="dropdown" href="#"><i class="fa fa-sliders fa-fw" aria-hidden="true"></i> Configure <span class="caret"></span></a>
            <ul class="dropdown-menu">
                <li class="dropdown-header">Follow in order</li>
                {{-- P2-8: this used to link to /settings with no fragment, dropping
                     the operator at the top of a long single-column page. --}}
                <li><a href="{{ route('iapm.settings.edit') }}#ingestion-token"><i class="fa fa-key fa-fw" aria-hidden="true"></i> 0. Generate ingestion token</a></li>
                <li><a href="{{ route('iapm.destinations.index') }}"><i class="fa fa-paper-plane fa-fw" aria-hidden="true"></i> 1. Destinations</a></li>
                <li><a href="{{ route('iapm.policies.index') }}"><i class="fa fa-list-alt fa-fw" aria-hidden="true"></i> 2. Policies</a></li>
                <li><a href="{{ route('iapm.setup-helper') }}"><i class="fa fa-plug fa-fw" aria-hidden="true"></i> 3. LibreNMS setup helper</a></li>
            </ul>
        </li>
        <li class="dropdown {{ $iapmActive('iapm.policy-test','iapm.template-preview','iapm.message-templates','iapm.sms-content-filters.edit','iapm.comparison-report','iapm.setup-helper','iapm.simulate','iapm.real-simulations.index','iapm.import.form') }}">
            <a class="dropdown-toggle" data-toggle="dropdown" href="#"><i class="fa fa-wrench fa-fw" aria-hidden="true"></i> Tools <span class="caret"></span></a>
            <ul class="dropdown-menu">
                <li class="dropdown-header">Templates</li>
                <li><a href="{{ route('iapm.message-templates') }}"><i class="fa fa-comment fa-fw" aria-hidden="true"></i> Message Templates</a></li>
                @can('manage iapm settings')<li><a href="{{ route('iapm.sms-content-filters.edit') }}"><i class="fa fa-filter fa-fw" aria-hidden="true"></i> SMS Content Filters</a></li>@endcan
                <li><a href="{{ route('iapm.template-preview') }}"><i class="fa fa-eye fa-fw" aria-hidden="true"></i> Template Preview</a></li>
                <li role="separator" class="divider"></li>
                <li class="dropdown-header">Test &amp; validate</li>
                <li><a href="{{ route('iapm.policy-test') }}"><i class="fa fa-check-square-o fa-fw" aria-hidden="true"></i> Policy Test</a></li>
                <li><a href="{{ route('iapm.simulate') }}"><i class="fa fa-flask fa-fw" aria-hidden="true"></i> Synthetic Simulation</a></li>
                <li><a href="{{ route('iapm.real-simulations.index') }}"><i class="fa fa-bolt fa-fw" aria-hidden="true"></i> <strong>Real Simulation</strong></a></li>
                <li><a href="{{ route('iapm.comparison-report') }}"><i class="fa fa-balance-scale fa-fw" aria-hidden="true"></i> Comparison Report</a></li>
                <li role="separator" class="divider"></li>
                <li><a href="{{ route('iapm.import.form') }}"><i class="fa fa-exchange fa-fw" aria-hidden="true"></i> Import / Export</a></li>
                <li><a href="{{ route('iapm.setup-helper') }}"><i class="fa fa-life-ring fa-fw" aria-hidden="true"></i> Setup Helper</a></li>
            </ul>
        </li>
        <li class="dropdown {{ $iapmActive('iapm.delivery-log','iapm.audit-log') }}">
            <a class="dropdown-toggle" data-toggle="dropdown" href="#"><i class="fa fa-file-text-o fa-fw" aria-hidden="true"></i> Logs <span class="caret"></span></a>
            <ul class="dropdown-menu">
                <li><a href="{{ route('iapm.delivery-log') }}"><i class="fa fa-paper-plane-o fa-fw" aria-hidden="true"></i> Delivery Log</a></li>
                <li><a href="{{ route('iapm.audit-log') }}"><i class="fa fa-history fa-fw" aria-hidden="true"></i> Audit Log</a></li>
            </ul>
        </li>
        <li class="{{ $iapmActive('iapm.settings.edit') }}"><a href="{{ route('iapm.settings.edit') }}"><i class="fa fa-cog fa-fw" aria-hidden="true"></i> Settings</a></li>
    </ul>
    <span class="pull-right" style="margin-top:4px;">
        {{-- P1-2: the quick-find box deep-linked into the matrix on Enter but
             offered no suggestions. Picking a suggestion now jumps straight to
             that one interface; pressing Enter still runs the name search. --}}
        <form class="form-inline iapm-hide-sm" method="get" action="{{ route('iapm.matrix') }}" style="display:inline-block;margin-right:8px;position:relative;"
              data-iapm-quickfind="{{ route('iapm.lookup.ports') }}" data-iapm-quickfind-target="{{ route('iapm.matrix') }}">
            <label class="sr-only" for="iapm-quickfind">Find an interface</label>
            <input class="form-control input-sm iapm-quickfind" id="iapm-quickfind" name="search" autocomplete="off"
                   role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="iapm-quickfind-results"
                   placeholder="Find interface…" value="{{ $iapmRoute==='iapm.matrix' ? request('search') : '' }}">
            <div id="iapm-quickfind-results" class="list-group" role="listbox" data-iapm-quickfind-results
                 style="display:none;position:absolute;z-index:1050;right:0;min-width:380px;max-height:300px;overflow:auto;box-shadow:0 2px 8px rgba(0,0,0,.2);"></div>
        </form>
        <span class="label label-default" style="margin-right:4px;" title="Installed Interface Alert Policy Manager package version">IAPM {{ $iapmVersion->display() }}</span>
        @if($iapmDryRun)
            <a href="{{ route('iapm.settings.edit') }}" class="label label-warning" title="No external delivery. Click to change."><i class="fa fa-flask"></i> DRY-RUN</a>
        @else
            <span class="label label-success" title="Notifications are delivered to the gateway."><i class="fa fa-bolt"></i> LIVE</span>
        @endif
    </span>
</div>

@if(session('status'))<div class="alert alert-success alert-dismissible iapm-toast"><button type="button" class="close" data-dismiss="alert">&times;</button>{{ session('status') }}</div>@endif
@if(session('error'))<div class="alert alert-danger alert-dismissible iapm-toast"><button type="button" class="close" data-dismiss="alert">&times;</button>{{ session('error') }}</div>@endif
@if(session('new_ingestion_token'))<div class="alert alert-warning"><strong>Copy this ingestion token now — it is shown only once:</strong><pre style="margin-top:6px;">{{ session('new_ingestion_token') }}</pre></div>@endif
@if($errors->any())<div class="alert alert-danger"><strong>Please fix the following:</strong><ul style="margin-bottom:0;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
