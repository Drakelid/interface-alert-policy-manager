# IAPM Manual Acceptance Checklist

Perform this on a staging LibreNMS clone with dry-run enabled until step 26. Record the incident IDs, delivery IDs, timestamps, and expected/actual result for every step.

1. Install the Composer package using the documented path repository.
2. Run migrations and enable `interface-alert-policy-manager`.
3. Run `php artisan iapm:install-check`; resolve every failure.
4. Generate an ingestion token and copy it to the LibreNMS API transport.
5. Create an encrypted SMS destination using deployment-specific credentials.
6. Send a labelled destination test to a controlled receiver.
7. Create an enabled default policy with trigger and recovery actions.
8. Create a multi-device-group assignment using `any`, then preview a member interface.
9. Repeat policy preview with `all` and `exclude` assignments and confirm explanation ordering.
10. Enable dry-run mode and retain the existing direct LibreNMS SMS operation.
11. Submit `samples/active-alert.json`; confirm a pending/active per-port incident.
12. Resubmit the identical payload; confirm it is counted as ignored and does not increment observations.
13. Submit an active payload with two different `port_id` faults; confirm two incidents.
14. Remove one fault from the next payload with the same alert ID/UID; confirm only that interface recovers.
15. Submit `samples/recovery-alert.json`; confirm every remaining correlated incident recovers.
16. Make the device down and reconcile; confirm `device_down` suppression.
17. Make the interface administratively down and reconcile; confirm `admin_down` suppression.
18. Place the device in active LibreNMS scheduled maintenance; confirm maintenance suppression.
19. Configure a recovery hold-down, restore the interface, and confirm recovery waits for the hold-down.
20. Configure a repeating reminder and maximum sends; confirm the delivery log stops at the limit.
21. Configure a delayed escalation; confirm it sends only after its delay.
22. Acknowledge and unacknowledge an incident; confirm timeline/audit entries.
23. Mute until a future time, process actions, then unmute; confirm no send while muted.
24. Simulate a missed webhook by changing port state and run reconciliation; confirm activation/recovery.
25. Review the Comparison report for would-send, suppression, missing-policy, missing-receiver, and failure counts.
26. Preview trigger and recovery templates against real ports and resolve length warnings.
27. Disable dry-run mode with an approved change window.
28. Generate one controlled interface failure and verify exactly one real trigger SMS.
29. Restore the interface and verify exactly one real recovery SMS.
30. Disable the former direct LibreNMS SMS operation and confirm IAPM is the only live sender.

Abort the live phase and restore dry-run/direct SMS if duplicates, missing recoveries, credential leakage, unexplained suppression, or gateway saturation occurs.
