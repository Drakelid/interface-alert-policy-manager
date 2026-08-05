@extends('layouts.librenmsv1') @section('title','IAPM Audit Log') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h2>Audit Log</h2>
<form class="form-inline" style="margin-bottom:10px;">
    <input class="form-control input-sm" name="action" value="{{ request('action') }}" placeholder="Action contains">
    <input class="form-control input-sm" name="object_type" value="{{ request('object_type') }}" placeholder="Object type">
    <input class="form-control input-sm" name="user_id" value="{{ request('user_id') }}" placeholder="User ID">
    <button class="btn btn-default btn-sm">Filter</button>
</form>
<div class="iapm-table-wrap"><table class="table table-condensed table-hover">
<thead><tr><th>Time</th><th>User</th><th>Action</th><th>Object</th><th>Source IP</th></tr></thead>
<tbody>@forelse($audits as $a)<tr>
<td>@include('iapm::partials.time',['at'=>$a->created_at])</td>
<td>{{ $a->user_id }}</td>
<td><span class="label label-default">{{ $a->action }}</span></td>
<td>{{ $a->object_type }} {{ $a->object_id }}</td>
<td>{{ $a->source_ip }}</td>
</tr>@empty<tr><td colspan="5" class="text-muted">No audit entries match.</td></tr>@endforelse
</tbody></table></div>{{ $audits->links() }}</div>@endsection
