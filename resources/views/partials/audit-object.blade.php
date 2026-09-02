{{--
    The "Object" cell of the audit log (P1-3).

    Previously "incident 1" was plain text. Where the object type maps to a page
    inside the plugin the id becomes a link, so an auditor can go straight to the
    thing that changed. Deleted objects keep the plain text -- there is nothing
    to open -- rather than offering a link that 404s.

    Expects: $audit (AuditLog).
--}}
@php($iapmObjectRoute = [
    'incident' => 'iapm.incidents.show',
    'policy' => 'iapm.policies.edit',
    'destination' => 'iapm.destinations.edit',
    'policy_action' => 'iapm.actions.edit',
][$audit->object_type] ?? null)
{{-- Each target form is gated by its own ability (the policy, action and
     destination forms all render receiver values). Viewing the audit log is a
     separate ability, so link only where this auditor can actually open the
     page, and fall through to plain text otherwise rather than offering a 403. --}}
@php($iapmObjectAbility = [
    'policy' => 'manage iapm policies',
    'destination' => 'manage iapm destinations',
    'policy_action' => 'manage iapm policies',
][$audit->object_type] ?? null)
@php($iapmObjectRoute = $iapmObjectAbility && ! auth()->user()?->can($iapmObjectAbility) ? null : $iapmObjectRoute)
@php($iapmObjectLabel = str_replace('_',' ',(string) $audit->object_type))
@if($iapmObjectRoute && $audit->object_id && $audit->action !== 'deleted')
    {{ $iapmObjectLabel }} <a href="{{ route($iapmObjectRoute, $audit->object_id) }}">#{{ $audit->object_id }}</a>
@elseif($audit->object_id)
    {{ $iapmObjectLabel }} #{{ $audit->object_id }}@if($audit->action === 'deleted') <span class="iapm-hint">(deleted)</span>@endif
@else
    {{ $iapmObjectLabel }}
@endif
