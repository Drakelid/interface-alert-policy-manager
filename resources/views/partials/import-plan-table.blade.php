{{--
    Per-item import plan, shared by the dry-run preview and the post-import
    report so the two can never describe the same run differently (P1-8).

    Expects: $items (from ConfigurationImportPlanner), $badge (action => label
             class), $past (true for the report, false for the preview).
--}}
<div class="iapm-table-wrap">
    <table class="table table-condensed" data-iapm-import-plan>
        <thead><tr><th>Item</th><th>Type</th><th>{{ $past ? 'Result' : 'Planned' }}</th><th>Why</th></tr></thead>
        <tbody>
        @forelse($items as $item)
        <tr data-iapm-plan-action="{{ $item['action'] }}">
            <td>
                @if($item['parent'])<span class="iapm-hint">{{ $item['parent'] }} &rsaquo;</span> @endif
                {{ $item['name'] }}
            </td>
            <td><span class="iapm-hint">{{ str_replace('_',' ',$item['type']) }}</span></td>
            <td><span class="label label-{{ $badge[$item['action']] }}">{{ $past ? [ 'create'=>'created','update'=>'updated','skip'=>'skipped' ][$item['action']] : $item['action'] }}</span></td>
            <td class="iapm-hint">{{ $item['reason'] }}</td>
        </tr>
        @empty
        <tr><td colspan="4" class="iapm-hint">The document contains no policies.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
