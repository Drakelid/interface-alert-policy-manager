<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SmsContentFilter;

class SmsContentFilterController extends Controller
{
    public function edit(SmsContentFilter $filters)
    {
        return view()->file(dirname(__DIR__, 3).'/resources/views/sms-content-filters.blade.php', [
            'phrases' => $filters->asLines($filters->phrases()),
            'symbols' => $filters->asLines($filters->symbols()),
            'defaultPhrases' => $filters->asLines(SmsContentFilter::DEFAULT_PHRASES),
            'defaultSymbols' => $filters->asLines(SmsContentFilter::DEFAULT_SYMBOLS),
            'sample' => "CRITICAL: Interface down\nDevice: core-switch-01\nPort: Gi1/0/24\nDescription: ### Bundle-Ether10 to Oslo distribution switch ###",
        ]);
    }

    public function update(Request $request, SmsContentFilter $filters, SettingStore $settings, AuditService $audit)
    {
        $data = $request->validate([
            'phrases' => ['present', 'nullable', 'string', 'max:12000'],
            'symbols' => ['present', 'nullable', 'string', 'max:12000'],
        ]);

        try {
            $phrases = $filters->parseLines((string) ($data['phrases'] ?? ''));
            $symbols = $filters->parseLines((string) ($data['symbols'] ?? ''));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['filters' => $exception->getMessage()]);
        }

        $before = ['phrases' => $filters->phrases(), 'symbols' => $filters->symbols()];
        $settings->putMany(['sms_filter_phrases' => $phrases, 'sms_filter_symbols' => $symbols]);
        $audit->record($request, 'updated', 'sms_content_filters', null, $before, ['phrases' => $phrases, 'symbols' => $symbols]);

        return back()->with('status', 'SMS content filters saved. New SMS payloads use them within a few seconds.');
    }

    public function preview(Request $request, SmsContentFilter $filters): JsonResponse
    {
        $data = $request->validate([
            'phrases' => ['present', 'nullable', 'string', 'max:12000'],
            'symbols' => ['present', 'nullable', 'string', 'max:12000'],
            'message' => ['required', 'string', 'max:20000'],
        ]);

        try {
            $phrases = $filters->parseLines((string) ($data['phrases'] ?? ''));
            $symbols = $filters->parseLines((string) ($data['symbols'] ?? ''));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['filtered' => $filters->filterWith($data['message'], $phrases, $symbols)]);
    }
}
