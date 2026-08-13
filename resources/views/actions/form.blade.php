@extends('layouts.librenmsv1')
@section('title', $action->exists ? 'Edit IAPM Policy Action' : 'Create IAPM Policy Action')
@section('content')
<div class="container-fluid">
    @include('iapm::partials.nav')
    <h1 class="iapm-page-title">{{ $action->exists ? 'Edit' : 'Create' }} action for {{ $policy->name }}</h1>
    <p class="iapm-hint" style="max-width:70em;">
        An action defines where one notification goes, when it sends, and what it says. Build an escalation chain with several escalation actions at increasing delays.
        <a href="{{ route('iapm.policies.edit',$policy) }}">Back to {{ $policy->name }}</a>.
    </p>

    @php($iapmActionFields = [
      'delay_seconds' => ['label'=>'Delay', 'unit'=>'seconds', 'default'=>0, 'seconds'=>true, 'help'=>'Wait this long after the phase begins before sending. 0 = immediately. Escalation delays start when the incident triggers.'],
      'repeat_seconds' => ['label'=>'Repeat every', 'unit'=>'seconds', 'default'=>null, 'seconds'=>true, 'help'=>'Blank inherits the policy interval; if both are blank, send once. Minimum 60 seconds.'],
      'maximum_sends' => ['label'=>'Maximum sends', 'unit'=>null, 'default'=>null, 'seconds'=>false, 'help'=>'Blank = unlimited, subject to the policy cap. 1 sends only once.'],
      'sort_order' => ['label'=>'Sort order', 'unit'=>null, 'default'=>0, 'seconds'=>false, 'help'=>'Order within this phase. Lower actions run first.'],
    ])

    <form method="post" action="{{ $action->exists ? route('iapm.actions.update',$action) : route('iapm.actions.store',$policy) }}">
        @csrf
        @if($action->exists)@method('PUT')@endif

        <div class="iapm-editor-grid">
            <section class="panel panel-default" aria-labelledby="iapm-delivery-heading">
                <div class="panel-heading" id="iapm-delivery-heading"><i class="fa fa-paper-plane"></i> Delivery and timing</div>
                <div class="panel-body">
                    <div class="form-group">
                        <label for="iapm-action-destination">Destination</label>
                        <select name="destination_id" id="iapm-action-destination" class="form-control" aria-describedby="iapm-action-destination-help">
                            @foreach($destinations as $d)<option value="{{ $d->id }}" @selected(old('destination_id',$action->destination_id)==$d->id)>{{ $d->name }}@unless($d->enabled) (disabled)@endunless</option>@endforeach
                        </select>
                        <p class="iapm-hint" id="iapm-action-destination-help">Where this notification is delivered. Manage these under <a href="{{ route('iapm.destinations.index') }}">Destinations</a>.</p>
                    </div>

                    <div class="form-group">
                        <label for="iapm-action-phase">Phase</label>
                        <select name="phase" id="iapm-action-phase" class="form-control" aria-describedby="iapm-action-phase-help">
                            @foreach(['trigger','escalation','reminder','recovery','acknowledged'] as $v)<option @selected(old('phase',$action->phase?->value)===$v)>{{ $v }}</option>@endforeach
                        </select>
                        <p class="iapm-hint" id="iapm-action-phase-help"><strong>Trigger</strong> opens an incident; <strong>escalation</strong> waits while unacknowledged; <strong>reminder</strong> repeats while open; <strong>recovery</strong> sends when service returns; <strong>acknowledged</strong> sends when someone takes ownership.</p>
                    </div>

                    <div class="iapm-compact-grid">
                        @foreach($iapmActionFields as $key => $f)
                        <div class="form-group iapm-narrow-field">
                            <label for="iapm-action-{{ $key }}">{{ $f['label'] }}@if($f['unit']) <span class="iapm-hint">({{ $f['unit'] }})</span>@endif</label>
                            <input type="number" id="iapm-action-{{ $key }}" name="{{ $key }}" class="form-control{{ $f['seconds']?' iapm-seconds':'' }}" value="{{ old($key, $action->exists ? $action->$key : $f['default']) }}" aria-describedby="iapm-action-{{ $key }}-help">
                            @if($f['seconds'])<span class="help-block iapm-seconds-hint text-info" style="display:inline;margin-left:6px;"></span>@endif
                            <p class="iapm-hint" id="iapm-action-{{ $key }}-help">{{ $f['help'] }}</p>
                        </div>
                        @endforeach
                    </div>

                    <div class="checkbox">
                        <label for="iapm-action-enabled"><input type="hidden" name="enabled" value="0"><input type="checkbox" id="iapm-action-enabled" name="enabled" value="1" @checked(old('enabled',$action->exists?$action->enabled:true))> Enabled and eligible to send</label>
                    </div>
                </div>
            </section>

            <section class="panel panel-default" aria-labelledby="iapm-message-heading">
                <div class="panel-heading" id="iapm-message-heading"><i class="fa fa-comment"></i> Recipients and message</div>
                <div class="panel-body">
                    <div class="form-group">
                        <label for="iapm-action-receivers">Receiver overrides <span class="iapm-hint">(one per line)</span></label>
                        <textarea name="receivers_text" id="iapm-action-receivers" class="form-control" rows="3" aria-describedby="iapm-action-receivers-help">{{ old('receivers_text',implode("\n",$action->receivers_json??[])) }}</textarea>
                        <p class="iapm-hint" id="iapm-action-receivers-help">Highest-precedence receiver for this action. Blank falls back through assignment, policy, destination, then the global receiver. Check it with <a href="{{ route('iapm.policy-test') }}" target="_blank">Policy Test</a>.</p>
                    </div>

                    <div class="form-group @error('message_template') has-error @enderror">
                        <label for="iapm-action-template">Safe message template</label>
                        <textarea name="message_template" id="iapm-action-template" class="form-control" rows="10" style="font-family:monospace;" data-iapm-sms-counter aria-describedby="iapm-action-template-help" placeholder="Leave blank to use the default template for this phase.">{{ old('message_template',$action->message_template) }}</textarea>
                        @error('message_template')<span class="help-block">{{ $message }}</span>@enderror
                        <div class="iapm-hint iapm-wrap-code" id="iapm-action-template-help">
                            Insert values with <code>@{{ placeholder }}</code>. Add safe conditions with an optional else branch:
                            <code class="iapm-code-example">@{{#if ifAlias}}Circuit: @{{ ifAlias }}@{{else}}Interface: @{{ ifName }}@{{/if}}
@{{#if severity == "critical"}}URGENT: @{{/if}}</code>
                            Conditions accept a placeholder alone, <code>==</code> / <code>!=</code>, or <code>contains</code> / <code>not contains</code> with a quoted value, and may be nested. For group membership use <code>@{{#if device_groups contains "Production"}}</code>. No PHP or Blade runs. Invalid syntax and unknown placeholders are rejected on save. <a href="{{ route('iapm.template-preview') }}" target="_blank">Preview this template</a>.
                        </div>
                        @include('iapm::partials.placeholder-chips',['target'=>'#iapm-action-template'])
                    </div>
                </div>
            </section>
        </div>

        <div class="iapm-form-footer">
            <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save action</button>
            <a class="btn btn-default" href="{{ route('iapm.policies.edit',$policy) }}">Cancel</a>
            @if($action->exists)
            <span class="spacer" style="flex:1 1 auto;"></span>
            <button type="submit" form="iapm-delete-action" class="btn btn-danger"><i class="fa fa-trash"></i> Delete action</button>
            @endif
        </div>
    </form>

    @if($action->exists)
    <form id="iapm-delete-action" method="post" action="{{ route('iapm.actions.destroy',$action) }}" data-iapm-confirm="Delete the {{ $action->phase->value }} action sending to {{ $action->destination?->name ?? 'no destination' }}? {{ $policy->name }} will stop notifying through it.">@csrf @method('DELETE')</form>
    @endif
</div>

<script>
(function () {
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
        function update() { hint.textContent = humanize(inp.value); }
        inp.addEventListener('input', update);
        update();
    });
})();
</script>
@endsection
