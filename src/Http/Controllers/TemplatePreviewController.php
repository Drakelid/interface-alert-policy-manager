<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Rules\PortHasDevice;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\EntityLookup;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\MessageTemplates;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SafeTemplateRenderer;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SmsContentFilter;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\TemplateContextBuilder;

class TemplatePreviewController extends Controller
{
    public function __invoke(Request $request, InterfaceContextService $contexts, SafeTemplateRenderer $renderer, TemplateContextBuilder $placeholders, EntityLookup $lookup, SmsContentFilter $smsFilters)
    {
        $rendered = null;
        $warning = null;
        $port = null;

        if ($request->isMethod('post')) {
            $data = $request->validate(['port_id' => ['required', 'integer', new PortHasDevice], 'template' => ['required', 'string', 'max:10000']]);
            $port = Port::with(['device.location', 'device.groups', 'groups'])->findOrFail($data['port_id']);
            $values = $placeholders->forPreview($contexts->forPort($port));
            try {
                $rendered = $smsFilters->filter($renderer->render($data['template'], $values));
                $warning = 'Filtered SMS length: '.mb_strlen($rendered).' characters. IAPM sends this complete result without truncation; the SMS gateway controls final length and segmentation.';
            } catch (\Throwable $e) {
                $warning = $e->getMessage();
            }
        }

        // A GET with ?port_id= (the Interface Matrix link) prefills the picker too.
        $port ??= $request->filled('port_id') ? Port::with('device')->find($request->integer('port_id')) : null;

        return view('iapm::template-preview', ['rendered' => $rendered, 'warning' => $warning, 'defaultTemplate' => MessageTemplates::default('trigger'), 'portLabel' => $port ? $lookup->portLabel($port) : '']);
    }
}
