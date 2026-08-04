<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\ProcessActionsCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SafeTemplateRenderer;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\TemplateContextBuilder;

class TemplatePreviewController extends Controller
{
    public function __invoke(Request $request, InterfaceContextService $contexts, SafeTemplateRenderer $renderer, TemplateContextBuilder $placeholders)
    {
        $rendered = null;
        $warning = null;

        if ($request->isMethod('post')) {
            $data = $request->validate(['port_id' => ['required', 'integer', 'exists:ports,port_id'], 'template' => ['required', 'string', 'max:10000']]);
            $port = Port::with(['device.location', 'device.groups', 'groups'])->findOrFail($data['port_id']);
            $values = $placeholders->forPreview($contexts->forPort($port));
            $limit = (int) config('iapm.sms.message_length', 480);

            try {
                $full = $renderer->render($data['template'], $values);
                $warning = mb_strlen($full) > $limit ? 'Rendered message is '.mb_strlen($full)." characters; it will be deterministically truncated to $limit." : null;
                $rendered = $renderer->render($data['template'], $values, $limit);
            } catch (\Throwable $e) {
                $warning = $e->getMessage();
            }
        }

        return view('iapm::template-preview', ['rendered' => $rendered, 'warning' => $warning, 'defaultTemplate' => ProcessActionsCommand::defaultTemplate('trigger')]);
    }
}
