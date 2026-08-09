<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Models;

use Illuminate\Database\Eloquent\Model;

class AssignmentDeviceGroup extends Model
{
    public $timestamps = false;

    public $incrementing = false; // composite primary key: (assignment_id, device_group_id)

    protected $table = 'iapm_assignment_device_groups';

    protected $guarded = [];
}
