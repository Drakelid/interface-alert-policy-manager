# IAPM Development

IAPM is a Laravel package discovered by Composer. The provider registers LibreNMS hooks, routes, migrations, commands, views, and scheduler entries. Controllers validate and delegate; policy/context/suppression/template/transport services contain business logic. Models reference plugin tables while LibreNMS core models remain dependencies.

## Registration and enablement

Migrations, views, commands, routes, and scheduler entries are registered unconditionally, because the `plugins` table does not exist during the first `php artisan migrate` and the scheduler is resolved during application boot. Enablement is enforced where it is observable instead:

- Browser and ingestion routes carry the `EnsurePluginEnabled` middleware and return `404` while the plugin is disabled.
- `iapm:reconcile`, `iapm:process-actions`, and `iapm:cleanup` use the `SkipsWhenPluginDisabled` trait and exit successfully without acting.

Never make route or migration registration depend on database state read at boot.

## Extension points

Add a transport by implementing `NotificationTransport`, registering it in `TransportManager`, providing encrypted configuration validation, SSRF restrictions, HTTP fakes, and redaction tests. Add an assignment type by extending `AssignmentType`, its specificity, resolver matching, request validation, preview query, and precedence tests. Never reorder existing migration history; add a new reversible migration.

Message placeholders come from `TemplateContextBuilder`. `SafeTemplateRenderer` throws on unknown placeholders, so a new placeholder must be added to the builder (both `forIncident()` and `forPreview()` go through the same map) and to the README list. `TemplateRenderingTest` asserts that every documented placeholder resolves.

## Tests

Two suites, one PHPUnit configuration:

| Suite | Location | Needs LibreNMS |
| --- | --- | --- |
| `unit` | `tests/Unit` | no |
| `integration` | `tests/Feature`, `tests/Command` | yes |

The `unit` suite covers pure services (policy precedence helpers, receiver resolution, schedule evaluation, suppression, safe template rendering, redaction, URL guarding) and runs against the package's own dependencies:

```sh
composer install
vendor/bin/phpunit --testsuite unit
```

The `integration` suite boots the LibreNMS application, migrates an in-memory database with `RefreshDatabase`, and exercises ingestion, policy resolution, delivery, authorization, and every Artisan command. It requires the package to be installed into a LibreNMS checkout. `tests/bootstrap.php` locates that checkout via `LIBRENMS_ROOT`, then a sibling `../librenms` directory, then the vendor install path:

```sh
LIBRENMS_ROOT=/opt/librenms vendor/bin/phpunit --testsuite integration
```

`IntegrationTestCase` calls `Http::preventStrayRequests()` and every transport test uses `Http::fake()`. Tests must never resolve or contact the production gateway; SMS destinations in tests point at `127.0.0.1` with `allow_private_networks` enabled so `UrlGuard` performs no DNS lookup.

## Checks

Expected checks with PHP 8.2+ and package development dependencies available:

```sh
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/phpunit --testsuite unit
LIBRENMS_ROOT=/opt/librenms vendor/bin/phpunit --testsuite integration
php artisan iapm:install-check
```

## Upgrade and migration rules

Make every migration reversible, never edit or reorder a migration that has been deployed, and never drop user data in an upgrade. Schema changes are added incrementally with guards (`Schema::hasColumn`, `Schema::hasTable`) so partially applied installations converge.
