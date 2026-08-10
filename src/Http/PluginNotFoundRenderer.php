<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\IapmServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * P0-2: a mistyped or stale URL under the plugin prefix produced LibreNMS's
 * bare "404 NOT FOUND" with no styling and no way back. Render those inside the
 * plugin layout instead, so the navigation is always reachable.
 *
 * Unmatched paths are handled by the catch-all route at the end of routes/web.php,
 * which keeps the `web` middleware stack in play. This covers the remainder:
 * a route that matched but whose model binding found nothing, or a controller
 * that called abort(404). Both types are registered because renderable callbacks
 * run before the framework's prepareException(), so a failed binding is still a
 * ModelNotFoundException rather than a NotFoundHttpException at this point.
 */
class PluginNotFoundRenderer
{
    public const PREFIX = 'plugin/interface-alert-policy-manager';

    public function __construct(private readonly PluginManagerInterface $plugins) {}

    public static function register(ExceptionHandler $handler): void
    {
        // LibreNMS could in principle swap in a handler that is not the
        // framework's; degrade to default behaviour rather than fataling.
        if (! method_exists($handler, 'renderable')) {
            return;
        }

        $handler->renderable(fn (NotFoundHttpException $e, Request $request) => app(self::class)->render($request, null));
        $handler->renderable(fn (ModelNotFoundException $e, Request $request) => app(self::class)->render($request, $e->getModel()));
    }

    /**
     * @param  class-string|null  $model  the bound model class, when the 404 came from a failed binding
     */
    public function render(Request $request, ?string $model): ?Response
    {
        if (! $this->shouldBrand($request)) {
            return null;
        }

        return response()->view('iapm::errors.404', [
            'requestedPath' => '/'.ltrim($request->path(), '/'),
            'missingResource' => $model ? Str::lower(Str::headline(class_basename($model))) : null,
        ], 404);
    }

    private function shouldBrand(Request $request): bool
    {
        // The machine ingestion endpoint shares the prefix and must keep
        // returning a JSON/plain 404 to LibreNMS rather than an HTML page.
        if (! $request->is(self::PREFIX.'*') || $request->expectsJson()) {
            return false;
        }

        // A disabled plugin answers 404 by design; branding that page would
        // advertise a plugin the operator has switched off.
        if (! $this->plugins->pluginEnabled(IapmServiceProvider::PLUGIN_NAME)) {
            return false;
        }

        // The plugin layout and nav assume a signed-in user with plugin access.
        // Anonymous or unauthorised requests fall through to LibreNMS.
        $user = $request->user();

        return $user !== null && Gate::forUser($user)->allows('view iapm');
    }
}
