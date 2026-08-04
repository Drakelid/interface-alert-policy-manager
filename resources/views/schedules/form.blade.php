@extends('layouts.librenmsv1') @section('title','IAPM Schedule') @section('content')<div class="container-fluid">@include('iapm::partials.nav')<h2>{{ $schedule->exists?'Edit':'Create' }} Schedule</h2><form method="post" action="{{ $schedule->exists?route('iapm.schedules.update',$schedule):route('iapm.schedules.store') }}">@csrf @if($schedule->exists)@method('PUT')@endif<div class="form-group"><label>Name</label><input class="form-control" required name="name" value="{{ old('name',$schedule->name) }}"></div><div class="form-group"><label>Timezone</label><input class="form-control" required name="timezone" value="{{ old('timezone',$schedule->timezone?:config('app.timezone')) }}"></div><div class="form-group"><label>Schedule JSON</label><textarea class="form-control" rows="14" name="schedule_json" style="font-family:monospace;">{{ old('schedule_json',json_encode($definition,JSON_PRETTY_PRINT)) }}</textarea><p class="help-block">Modes: <code>always</code>, <code>business_hours</code>, <code>after_hours</code>, <code>custom</code>. Days use <code>mon</code>..<code>sun</code> with <code>HH:MM</code> start/end periods. Example — business hours Mon–Fri:</p><pre style="max-width:520px;">{
  "mode": "business_hours",
  "days": {
    "mon": [{"start":"08:00","end":"16:00"}],
    "tue": [{"start":"08:00","end":"16:00"}],
    "wed": [{"start":"08:00","end":"16:00"}],
    "thu": [{"start":"08:00","end":"16:00"}],
    "fri": [{"start":"08:00","end":"16:00"}]
  }
}</pre></div><input type="hidden" name="enabled" value="0"><label><input type="checkbox" name="enabled" value="1" @checked(old('enabled',$schedule->exists?$schedule->enabled:true))> Enabled</label><br><button class="btn btn-primary">Save</button></form>@if($schedule->exists)<form method="post" action="{{ route('iapm.schedules.destroy',$schedule) }}" onsubmit="return confirm('Delete schedule?')">@csrf @method('DELETE')<button class="btn btn-danger">Delete</button></form>@endif</div>@endsection
