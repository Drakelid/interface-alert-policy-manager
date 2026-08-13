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
                $rendered = $renderer->render($data['template'], $values);
                $warning = 'Rendered length: '.mb_strlen($rendered).' characters. IAPM sends this complete message unchanged; the SMS gateway controls final length and segmentation.';
            } catch (\Throwable $e) {
                $warning = $e->getMessage();
            }
        }

        // A GET with ?port_id= (the Interface Matrix link) prefills the picker too.
        $port ??= $request->filled('port_id') ? Port::with('device')->find($request->integer('port_id')) : null;

        return view('iapm::template-preview', ['rendered' => $rendered, 'warning' => $warning, 'defaultTemplate' => MessageTemplates::default('trigger'), 'portLabel' => $port ? $lookup->portLabel($port) : '']);
    }
}
