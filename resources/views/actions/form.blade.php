@extends('layouts.librenmsv1')
@section('title', $action->exists ? 'Edit IAPM Policy Action' : 'Create IAPM Policy Action')
@section('content')
<div class="container-fluid">
    @include('iapm::partials.nav')
    <h1 class="iapm-page-title">{{ $action->exists ? 'Edit' : 'Create' }} action for {{ $policy->name }}</h1>

    {{-- P2-1: this form had no help text on any field, number inputs stretched
         the full page width, and there was no way back to the policy. It now
         follows the policy form's pattern, including the live seconds->human
         conversion and the placeholder help the message-templates page has. --}}
    <p class="iapm-hint" style="max-width:70em;">
        An action is one notification this policy sends: <strong>which destination</strong>, <strong>at which point</strong> in the incident's life, and <strong>how often</strong>. Build an escalation chain by adding several <em>escalation</em> actions with increasing delays and different destinations.
        <a href="{{ route('iapm.policies.edit',$policy) }}">Back to {{ $policy->name }}</a>.
    </p>

    <form method="post" action="{{ $action->exists ? route('iapm.actions.update',$action) : route('iapm.actions.store',$policy) }}">
        @csrf
        @if($action->exists)@method('PUT')@endif

        <div class="form-group iapm-narrow-field">
            <label for="iapm-action-destination">Destination</label>
            <select name="destination_id" id="iapm-action-destination" class="form-control" aria-describedby="iapm-action-destination-help">
                @foreach($destinations as $d)<option value="{{ $d->id }}" @selected(old('destination_id',$action->destination_id)==$d->id)>{{ $d->name }}@unless($d->enabled) (disabled)@endunless</option>@endforeach
            </select>
            <p class="iapm-hint" id="iapm-action-destination-help">Where this notification is delivered. Manage these under <a href="{{ route('iapm.destinations.index') }}">Destinations</a>.</p>
        </div>

        <div class="form-group iapm-narrow-field">
            <label for="iapm-action-phase">Phase</label>
            <select name="phase" id="iapm-action-phase" class="form-control" aria-describedby="iapm-action-phase-help">
                @foreach(['trigger','escalation','reminder','recovery','acknowledged'] as $v)<option @selected(old('phase',$action->phase?->value)===$v)>{{ $v }}</option>@endforeach
            </select>
            <p class="iapm-hint" id="iapm-action-phase-help">
                <strong>trigger</strong> when the incident first becomes active &middot;
                <strong>escalation</strong> after a delay if still unacknowledged &middot;
                <strong>reminder</strong> repeated while it stays open &middot;
                <strong>recovery</strong> when the interface comes back &middot;
                <strong>acknowledged</strong> when someone takes ownership.
            </p>
        </div>

        {{-- P2-2: these all defaulted to 0. For repeat_seconds (min 60) and
             maximum_sends (min 1) that value is not even valid, so a freshly
             opened Create form could not be submitted without editing both
             fields. Blank now means "unlimited / inherit", matching the policy
             form's convention. --}}
        @php($iapmActionFields = [
          'delay_seconds' => ['label'=>'Delay', 'unit'=>'seconds', 'default'=>0, 'seconds'=>true, 'help'=>'Wait this long after the phase begins before sending. 0 = send immediately. Escalation delays are measured from when the incident triggered.'],
          'repeat_seconds' => ['label'=>'Repeat every', 'unit'=>'seconds', 'default'=>null, 'seconds'=>true, 'help'=>'Re-send this action at this interval. Blank = inherit the policy\'s repeat interval; if that is blank too, send once and never repeat. Minimum 60 seconds.'],
          'maximum_sends' => ['label'=>'Maximum sends', 'unit'=>null, 'default'=>null, 'seconds'=>false, 'help'=>'Cap on how many times this action sends in total. Blank = unlimited (or the policy\'s "maximum repeats" cap when it sets one). 1 = send once and never repeat.'],
          'sort_order' => ['label'=>'Sort order', 'unit'=>null, 'default'=>0, 'seconds'=>false, 'help'=>'Order actions run in within the same phase. Lower first.'],
        ])
        @foreach($iapmActionFields as $key => $f)
        <div class="form-group iapm-narrow-field">
            <label for="iapm-action-{{ $key }}">{{ $f['label'] }}@if($f['unit']) <span class="iapm-hint">({{ $f['unit'] }})</span>@endif</label>
            <input type="number" id="iapm-action-{{ $key }}" name="{{ $key }}" class="form-control{{ $f['seconds']?' iapm-seconds':'' }}" value="{{ old($key, $action->exists ? $action->$key : $f['default']) }}" aria-describedby="iapm-action-{{ $key }}-help">
            @if($f['seconds'])<span class="help-block iapm-seconds-hint text-info" style="display:inline;margin-left:6px;"></span>@endif
            <p class="iapm-hint" id="iapm-action-{{ $key }}-help">{{ $f['help'] }}</p>
        </div>
        @endforeach

        <div class="form-group" style="max-width:640px;">
            <label for="iapm-action-receivers">Receiver overrides <span class="iapm-hint">(one per line)</span></label>
            <textarea name="receivers_text" id="iapm-action-receivers" class="form-control" rows="3" aria-describedby="iapm-action-receivers-help">{{ old('receivers_text',implode("\n",$action->receivers_json??[])) }}</textarea>
            <p class="iapm-hint" id="iapm-action-receivers-help">Highest-precedence receiver for this action. Leave blank to fall back to the assignment, then the policy default, then the destination, then the global receiver. Check the result with <a href="{{ route('iapm.policy-test') }}" target="_blank">Policy Test</a>.</p>
        </div>

        <div class="form-group" style="max-width:640px;">
            <label for="iapm-action-template">Safe message template</label>
            <textarea name="message_template" id="iapm-action-template" class="form-control" rows="8" style="font-family:monospace;" data-iapm-sms-counter aria-describedby="iapm-action-template-help" placeholder="Leave blank to use the default for this phase (edit those under Tools → Message Templates).">{{ old('message_template',$action->message_template) }}</textarea>
            <p class="iapm-hint" id="iapm-action-template-help">
                Only <code>@{{ placeholder }}</code> substitutions run &mdash; no PHP or Blade &mdash; and an unknown placeholder fails validation rather than sending a broken message.
                Try it on the <a href="{{ route('iapm.template-preview') }}" target="_blank">Template Preview</a> page.
            </p>
            @include('iapm::partials.placeholder-chips',['target'=>'#iapm-action-template'])
        </div>

        <div class="checkbox">
            <label for="iapm-action-enabled"><input type="hidden" name="enabled" value="0"><input type="checkbox" id="iapm-action-enabled" name="enabled" value="1" @checked(old('enabled',$action->exists?$action->enabled:true))> Enabled</label>
        </div>

        <div class="iapm-form-footer">
            <button class="btn btn-primary"><i class="fa fa-save"></i> Save action</button>
            <a class="btn btn-default" href="{{ route('iapm.policies.edit',$policy) }}">Cancel</a>
            @if($action->exists)
            <span class="spacer" style="flex:1 1 auto;"></span>
            @endif
        </div>
    </form>

    @if($action->exists)
    <form method="post" action="{{ route('iapm.actions.destroy',$action) }}" style="margin-top:10px;" data-iapm-confirm="Delete the {{ $action->phase->value }} action sending to {{ $action->destination?->name ?? 'no destination' }}? {{ $policy->name }} will stop notifying through it.">@csrf @method('DELETE')
        <button class="btn btn-danger"><i class="fa fa-trash"></i> Delete action</button>
    </form>
    @endif
</div>

<script>
(function () {
    // Same live seconds->human hint the policy form uses (P2-1).
    function humanize(v) {
        var s = parseInt(v, 10);
        if (!s || s <= 0) return '';
        if (s < 60) return '= ' + s + 's';
        if (s < 3600) return '≈ ' + (Math.round(s / 6) / 10) + ' min';
        return '≈ ' + (Math.round(s / 360) / 10) + ' h';
    }
    document.querySelectorAll('.iapm-seconds').forEach(function (inp) {
        var hint = inp.parentNode.querySelector('.iapm-seconds-hint');
        if (!hint) return;
        function upd() { hint.textContent = humanize(inp.value); }
        inp.addEventListener('input', upd); upd();
    });
})();
</script>
@endsection
