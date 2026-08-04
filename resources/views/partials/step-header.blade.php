<div class="panel panel-info">
    <div class="panel-body">
        <span class="label label-info">Step {{ $step }} of {{ $total }}</span>
        <strong style="margin-left:6px;">{{ $title }}</strong>
        @isset($nextRoute)<a class="btn btn-primary btn-xs pull-right" href="{{ $nextRoute }}">Next: {{ $nextLabel }} <i class="fa fa-arrow-right"></i></a>@endisset
        @isset($prevRoute)<a class="btn btn-default btn-xs pull-right" style="margin-right:6px;" href="{{ $prevRoute }}"><i class="fa fa-arrow-left"></i> {{ $prevLabel }}</a>@endisset
        <p class="text-muted" style="margin:8px 0 0;">{!! $desc !!}</p>
    </div>
</div>
