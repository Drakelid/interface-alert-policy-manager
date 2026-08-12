<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\EntityLookup;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\MessageTemplates;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SafeTemplateRenderer;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\TemplateContextBuilder;

class TemplatePreviewController extends Controller
{
    public function __invoke(Request $request, InterfaceContextService $contexts, SafeTemplateRenderer $renderer, TemplateContextBuilder $placeholders, EntityLookup $lookup)
    {
        $rendered = null;
        $warning = null;
        $port = null;

        if ($request->isMethod('post')) {
            $data = $request->validate(['port_id' => ['required', 'integer', 'exists:ports,port_id'], 'template' => ['required', 'string', 'max:10000']]);
            $port = Port::with(['device.location', 'device.groups', 'groups'])->findOrFail($data['port_id']);
            $values = $placeholders->forPreview($contexts->forPort($port));
            try {
                $full = $renderer->render($data['template'], $values);
                if ((bool) config('iapm.sms.single_segment', true)) {
                    $metrics = $renderer->smsMetrics($full);
                    $warning = $metrics['segments'] > 1
                        ? "The rendered {$metrics['encoding']} message would use {$metrics['segments']} SMS segments, so it was truncated to one {$metrics['single_limit']}-unit segment."
                        : "The rendered message fits in one {$metrics['encoding']} SMS segment.";
                    $rendered = $renderer->limitToSingleSms($full);
                } else {
                    $limit = (int) config('iapm.sms.message_length', 480);
                    $warning = mb_strlen($full) > $limit ? 'Rendered message is '.mb_strlen($full)." characters; it will be deterministically truncated to $limit." : null;
                    $rendered = $renderer->render($data['template'], $values, $limit);
                }
            } catch (\Throwable $e) {
                $warning = $e->getMessage();
            }
        }

        // A GET with ?port_id= (the Interface Matrix link) prefills the picker too.
        $port ??= $request->filled('port_id') ? Port::with('device')->find($request->integer('port_id')) : null;

        return view('iapm::template-preview', ['rendered' => $rendered, 'warning' => $warning, 'defaultTemplate' => MessageTemplates::default('trigger'), 'portLabel' => $port ? $lookup->portLabel($port) : '']);
    }
}
