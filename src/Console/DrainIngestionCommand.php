<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\Concerns\SkipsWhenPluginDisabled;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\IngestionController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Requests\IngestAlertRequest;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\IngestionInbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\Redactor;

class DrainIngestionCommand extends Command
{
    use SkipsWhenPluginDisabled;

    protected $signature = 'iapm:drain-ingestion {--limit=1} {--worker=1}';

    protected $description = 'Replay durably accepted large ingestion requests';

    public function handle(Redactor $redactor): int
    {
        if ($this->pluginDisabled()) {
            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;
        $limit = max(1, min(100, (int) $this->option('limit')));
        for ($i = 0; $i < $limit; $i++) {
            $row = $this->claim();
            if (! $row) {
                break;
            }
            try {
                $request = IngestAlertRequest::create('/internal/iapm/inbox', 'POST', (array) $row->payload_encrypted);
                $request->setContainer(app());
                $request->setRedirector(app('redirect'));
                $request->attributes->set('_iapm_durable_replay', true);
                $request->validateResolved();
                $response = app()->call([app(IngestionController::class), '__invoke'], ['request' => $request]);
                if ($response->getStatusCode() >= 300) {
                    throw new \RuntimeException('Replay returned HTTP '.$response->getStatusCode().'.');
                }
                IngestionInbox::whereKey($row->id)->where('status', 'processing')->update(['status' => 'processed', 'processed_at' => now(), 'claimed_at' => null, 'last_error_redacted' => null]);
                $processed++;
            } catch (\Throwable $exception) {
                $delay = min(3600, 15 * (2 ** min(8, max(0, (int) $row->attempt_count - 1))));
                IngestionInbox::whereKey($row->id)->where('status', 'processing')->update(['status' => 'failed', 'available_at' => now()->addSeconds($delay), 'claimed_at' => null, 'last_error_redacted' => mb_substr($redactor->text($exception->getMessage()), 0, 512)]);
                $failed++;
            }
        }

        $this->info("Worker {$this->option('worker')}: processed {$processed}, failed {$failed} inbox row(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function claim(): ?IngestionInbox
    {
        return DB::transaction(function (): ?IngestionInbox {
            $stale = now()->subSeconds(max(60, (int) config('iapm.ingestion.inbox_claim_timeout_seconds', 900)));
            $row = IngestionInbox::query()
                ->where(function ($query) use ($stale): void {
                    $query->where(fn ($due) => $due->whereIn('status', ['pending', 'failed'])->where(fn ($available) => $available->whereNull('available_at')->orWhere('available_at', '<=', now())))
                        ->orWhere(fn ($claimed) => $claimed->where('status', 'processing')->where('claimed_at', '<', $stale));
                })
                ->orderBy('id')->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            $row->update(['status' => 'processing', 'claimed_at' => now(), 'attempt_count' => DB::raw('attempt_count + 1')]);

            return $row->fresh();
        });
    }
}
