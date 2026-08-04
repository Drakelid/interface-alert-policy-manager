@php($cls = ['active'=>'label-danger','pending'=>'label-warning','acknowledged'=>'label-info','suppressed'=>'label-default','recovered'=>'label-success'][$state] ?? 'label-default')
<span class="label {{ $cls }}">{{ $state }}</span>
