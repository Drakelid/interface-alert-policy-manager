<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    /**
     * Every object_type AuditService::record() is called with, so the Audit Log
     * filter offers a select rather than asking the operator to guess a string
     * (P1-2). AuditLogVocabularyTest scans the source and fails if the two drift.
     *
     * @var list<string>
     */
    public const OBJECT_TYPES = ['assignment', 'configuration', 'destination', 'incident', 'interface_matrix', 'message_templates', 'policy', 'policy_action', 'schedule', 'settings', 'simulation', 'sms_content_filters'];

    public $timestamps = false;

    protected $table = 'iapm_audit_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['before_data' => 'array', 'after_data' => 'array', 'created_at' => 'datetime'];
    }
}
