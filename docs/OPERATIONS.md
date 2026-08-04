# IAPM Operations

## Gateway unavailable or failed deliveries

Keep incidents active, enable dry-run if failures are prolonged, inspect redacted delivery status, verify DNS/routing/TLS from the LibreNMS host, and use `iapm:test-destination` with a controlled receiver. Do not paste credentials into logs or tickets.

## Missed recovery or stuck pending

Run `iapm:reconcile --dry-run`, inspect the port in LibreNMS, then run reconciliation normally. For one incident use `--incident=ID`; for an outage scope use `--device=ID`. Confirm polling is current before forcing state.

## Duplicate alerts

Compare `source_alert_id`, `source_alert_uid`, stable incident key, and timeline. Do not delete an open incident. Check whether multiple LibreNMS transports target IAPM and whether scheduler overlap protection is functioning.

## Large outage storm

Enable dry-run if gateway capacity is at risk, confirm device-down/parent suppression, monitor database locks and scheduler duration, temporarily reduce destination throughput, and reconcile in device-scoped batches after device reachability returns.

Use bulk acknowledgement or mute in batches of no more than 1,000 incidents. Do not repeatedly force-resend actions during a storm. Export the Interface Matrix for an offline scope record and preserve delivery/audit logs until the post-incident review is complete.

## Invalid token and rotation

Generate a new random token, move the current token to `previous_ingestion_token`, install the new token in LibreNMS, verify ingestion, then remove the previous token. A 401 never identifies which token failed.

## Credential rotation

Enter a replacement password without exposing the old value, test a controlled receiver, inspect the redacted response, then invalidate the old gateway credential.

## Temporary rollback to direct SMS

Set IAPM dry-run true before re-enabling the prior LibreNMS SMS operation. Verify direct trigger/recovery, keep IAPM ingestion enabled for comparison, and avoid two live senders. Reverse the cutover only after the IAPM fault is understood.
