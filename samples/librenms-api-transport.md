# LibreNMS API transport for IAPM

- Method: `POST`
- URL: `https://<librenms-base>/plugin/interface-alert-policy-manager/api/v1/alerts`
- Send as form: disabled
- Header: `Authorization=Bearer <generated IAPM token>`
- Header: `Content-Type=application/json`
- Header: `Accept=application/json`
- Body: `{{ $msg }}`

Attach `librenms-alert-template.blade.php` to the broad interface-down rule. Do not configure SMS gateway credentials in this transport.
