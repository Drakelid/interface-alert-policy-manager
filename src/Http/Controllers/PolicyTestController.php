<?php
namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;
use App\Models\Port; use Illuminate\Http\Request; use Illuminate\Routing\Controller; use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService; use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
class PolicyTestController extends Controller { public function __invoke(Request $r,InterfaceContextService $contexts,PolicyResolver $resolver){$r->validate(['port_id'=>['nullable','integer','exists:ports,port_id']]);$port=$r->filled('port_id')?Port::with(['device.location','device.groups','groups'])->find($r->integer('port_id')):null;$resolution=$port?$resolver->resolve($contexts->forPort($port)):null;return view('iapm::policy-test',compact('port','resolution'));} }
