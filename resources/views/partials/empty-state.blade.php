<div class="panel panel-default">
    <div class="panel-body text-center" style="padding:35px 20px;">
        <p style="font-size:16px;margin-bottom:6px;"><i class="fa fa-info-circle text-info"></i> {{ $title }}</p>
        <p class="iapm-hint" style="max-width:520px;margin:0 auto 15px;">{{ $body }}</p>
        {{-- Optional so a caller can omit the call to action for a user who lacks
             the ability behind it, rather than offering a button that 403s. --}}
        @isset($route)<a class="btn btn-primary" href="{{ $route }}"><i class="fa fa-plus"></i> {{ $action }}</a>@endisset
        @isset($secondaryRoute)<a class="btn btn-default" href="{{ $secondaryRoute }}">{{ $secondaryAction }}</a>@endisset
    </div>
</div>
