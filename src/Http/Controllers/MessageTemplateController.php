<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\MessageTemplates;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SafeTemplateRenderer;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\TemplateContextBuilder;

/**
 * Central editor for the default message sent for each notification phase.
 * These are used whenever a policy action does not define its own template.
 */
class MessageTemplateController extends Controller
{
    public function edit(MessageTemplates $templates)
    {
        $rows = [];
        foreach (MessageTemplates::PHASES as $phase) {
            $rows[$phase] = [
                'label' => MessageTemplates::label($phase),
                'custom' => $templates->custom($phase),
                'default' => MessageTemplates::default($phase),
            ];
        }

        return view('iapm::message-templates', ['rows' => $rows]);
    }

    public function update(Request $request, SettingStore $settings, SafeTemplateRenderer $renderer, TemplateContextBuilder $placeholders, AuditService $audit)
    {
        abort_unless($request->user()->can('manage iapm settings'), 403);

        $input = $request->validate([
            'templates' => ['array'],
            'templates.*' => ['nullable', 'string', 'max:10000'],
        ])['templates'] ?? [];

        $sample = $placeholders->sample();
        $saved = [];
        foreach (MessageTemplates::PHASES as $phase) {
            $template = trim((string) ($input[$phase] ?? ''));

            if ($template !== '') {
                // Reject unknown placeholders / unsafe content before storing.
                try {
                    $renderer->render($template, $sample);
                } catch (\Throwable $e) {
                    return back()->withErrors([$phase => MessageTemplates::label($phase).': '.$e->getMessage()])->withInput();
                }
            }

            $settings->put('template_'.$phase, $template);
            $saved[$phase] = $template === '' ? '(default)' : 'custom';
        }

        $audit->record($request, 'updated', 'message_templates', null, null, $saved);

        return back()->with('status', 'Message templates saved.');
    }
}
