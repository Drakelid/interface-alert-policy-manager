<?php
namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;
class SetupHelperController { public function __invoke(\LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore $settings){return view('iapm::setup-helper',['rule'=>"macros.device_up = 1 AND ports.ifAdminStatus = 'up' AND ports.ifOperStatus != 'up' AND ports.ignore = 0 AND ports.disabled = 0 AND ports.deleted = 0",'template'=>$this->template(),'endpoint'=>url('/plugin/interface-alert-policy-manager/api/v1/alerts'),'hasToken'=>filled($settings->get('ingestion_token')),'lastAlertAt'=>$settings->get('last_ingestion_at')]);} private function template():string{return <<<'BLADE'
@php
$faults = [];
if ((int) $alert->state !== 0) {
    foreach ((array) $alert->faults as $fault) {
        $faults[] = [
            'port_id' => isset($fault['port_id']) ? (int) $fault['port_id'] : null,
            'ifName' => $fault['ifName'] ?? null,
            'ifDescr' => $fault['ifDescr'] ?? null,
            'ifAlias' => $fault['ifAlias'] ?? null,
            'ifAdminStatus' => $fault['ifAdminStatus'] ?? null,
            'ifOperStatus' => $fault['ifOperStatus'] ?? null,
        ];
    }
}
$payload = [
    'alert_uid' => (string) $alert->uid,
    'alert_id' => is_numeric($alert->id) ? (int) $alert->id : (string) $alert->id,
    'rule_id' => is_numeric($alert->rule_id) ? (int) $alert->rule_id : (string) $alert->rule_id,
    'device_id' => (int) $alert->device_id,
    'hostname' => (string) $alert->hostname,
    'state' => (int) $alert->state,
    'severity' => (string) $alert->severity,
    'timestamp' => (string) $alert->timestamp,
    'title' => (string) $alert->title,
    'faults' => $faults,
];
@endphp
@json($payload)
BLADE; } }
