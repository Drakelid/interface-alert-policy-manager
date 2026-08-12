@extends('layouts.librenmsv1') @section('title','IAPM Message Templates') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h1 class="iapm-page-title">Message templates</h1>
{{-- P4-4: the intro's {{ placeholder }} code span wrapped and overflowed the
     container's right edge; iapm-wrap-code keeps it inside. The placeholder wall
     of text is replaced by click-to-insert chips beside each field. --}}
<p class="iapm-hint iapm-wrap-code" style="max-width:70em;">The default message sent for each event type. A policy action with its own template overrides these. Leave a box blank to use the built-in default, shown as the placeholder text. Insert values with <code>@{{ placeholder }}</code>. Conditional text uses <code>@{{#if ifAlias}}...@{{else}}...@{{/if}}</code>, or compares a value with <code>@{{#if severity == "critical"}}...@{{/if}}</code>; <code>!=</code> and nested conditions are also supported. No PHP or Blade runs, and invalid syntax or unknown placeholders are rejected on save. Try wording on the <a href="{{ route('iapm.template-preview') }}" target="_blank">Template Preview</a> page.</p>

<form method="post" action="{{ route('iapm.message-templates.update') }}">@csrf @method('PUT')
<div class="row">
@foreach($rows as $phase => $row)
<div class="col-md-6">
    <div class="form-group">
        <label for="iapm-tpl-{{ $phase }}">{{ $row['label'] }}</label>
        {{-- P4-4: the trigger box's default content was clipped at 7 rows and
             needed internal scrolling. 10 rows fits the shipped defaults. --}}
        <textarea name="templates[{{ $phase }}]" id="iapm-tpl-{{ $phase }}" rows="10" data-iapm-sms-counter class="form-control" style="font-family:monospace;" placeholder="{{ $row['default'] }}">{{ old('templates.'.$phase, $row['custom']) }}</textarea>
        @error($phase)<span class="help-block text-danger">{{ $message }}</span>@enderror
        @include('iapm::partials.placeholder-chips',['target'=>'#iapm-tpl-'.$phase])
    </div>
</div>
@endforeach
</div>
<hr>
<h2>Device digest</h2>
<p class="iapm-hint iapm-wrap-code" style="max-width:70em;">Sent once when many interfaces on the same device go down together (enable it under <a href="{{ route('iapm.settings.edit') }}#storm-control">Settings &rarr; Storm control</a>). It uses a <strong>device-level</strong> placeholder set, not the per-interface one above.</p>
<div class="form-group" style="max-width:640px;">
    <label class="sr-only" for="iapm-tpl-digest">Device digest template</label>
    <textarea name="digest" id="iapm-tpl-digest" rows="8" data-iapm-sms-counter class="form-control" style="font-family:monospace;" placeholder="{{ $digest['default'] }}">{{ old('digest', $digest['custom']) }}</textarea>
    @error('digest')<span class="help-block text-danger">{{ $message }}</span>@enderror
    @include('iapm::partials.placeholder-chips',['target'=>'#iapm-tpl-digest','placeholders'=>\LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\TemplateContextBuilder::DIGEST_PLACEHOLDERS])
</div>

<div class="iapm-form-footer">
    <button class="btn btn-primary"><i class="fa fa-save"></i> Save templates</button>
    <span class="iapm-hint">SMS delivery is capped at one carrier segment; long rendered messages are truncated but keep the incident id.</span>
</div>
</form>
</div>@endsection
