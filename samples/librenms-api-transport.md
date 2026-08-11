# LibreNMS API transport for IAPM

- Method: `POST`
- URL: `https://<librenms-base>/plugin/interface-alert-policy-manager/api/v1/alerts`
- Send as form: disabled
- Header: `Authorization=Bearer <generated IAPM token>`
- Header: `Content-Type=application/json`
- Header: `Accept=application/json`
- Body: `{{ $msg }}`

Leave "API Options" empty — those become query-string parameters, not headers. The `Authorization`, `Content-Type`, and `Accept` values belong in "API Headers".

Attach `librenms-alert-template.blade.php` to the broad interface-down rule. Do not configure SMS gateway credentials in this transport.

On LibreNMS 26.x, also attach the rule to an **alert operation** and map this transport to that operation's *problem* segment. Without an operation the rule is muted before any transport runs and IAPM never receives the alert — see `docs/OPERATIONS.md`, "LibreNMS alert operations (26.x)".
