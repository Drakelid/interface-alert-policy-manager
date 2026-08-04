<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\AuditLog;

class AuditService
{
    public function __construct(private readonly Redactor $redactor) {}

    public function record(Request $request, string $action, string $type, Model|int|string|null $object, ?array $before = null, ?array $after = null): void
    {
        AuditLog::create(['user_id' => $request->user()?->getAuthIdentifier(), 'action' => $action, 'object_type' => $type, 'object_id' => $object instanceof Model ? $object->getKey() : (is_numeric($object) ? (int) $object : null), 'before_data' => $before ? $this->redactor->array($before) : null, 'after_data' => $after ? $this->redactor->array($after) : null, 'source_ip' => $request->ip(), 'created_at' => now()]);
    }
}
