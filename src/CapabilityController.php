<?php

namespace GlpiPlugin\Glpimobile;

use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Route;
use Glpi\Api\HL\RouteVersion;
use Glpi\Http\Request;
use Glpi\Http\Response;
use Plugin;
use Throwable;
use Toolbox;

/**
 * Feature discovery for the mobile app.
 *
 * Other plugins advertise mobile-facing features through the
 * `glpimobile_capabilities` hook (see the README for the contract); the app
 * asks this endpoint once per session and shows/hides UI accordingly. The map
 * is computed with the *current* session's rights, so two users can get
 * different answers for the same server.
 */
#[Route(path: '/GlpiMobile', tags: ['GlpiMobile'])]
final class CapabilityController extends AbstractController
{
    protected static function getRawKnownSchemas(string $api_version = ''): array
    {
        return [];
    }

    /**
     * The per-user capability map, keyed by contributing plugin directory:
     * `{"glpisignal": {"version": "0.1.0", "features": {"alerts": true, ...}}}`.
     * Empty object when no active plugin contributes. Always 200 — auth
     * failures are rejected earlier by the HL API layer itself.
     */
    #[Route(path: '/capabilities', methods: ['GET'])]
    #[RouteVersion(introduced: '2.0')]
    public function capabilities(Request $request): Response
    {
        global $PLUGIN_HOOKS;

        $out = [];
        $hooks = $PLUGIN_HOOKS['glpimobile_capabilities'] ?? [];

        foreach (is_array($hooks) ? $hooks : [] as $plugin => $callback) {
            $plugin = (string) $plugin;

            // Only active plugins get a voice: an inactive plugin's hook entry
            // can linger in a stale hook array, and its features certainly
            // don't work.
            if (!Plugin::isPluginActive($plugin) || !is_callable($callback)) {
                continue;
            }

            try {
                $caps = $callback();
            } catch (Throwable $e) {
                // One broken contributor must not cost the app the whole map.
                Toolbox::logInFile(
                    'glpimobile',
                    sprintf("capabilities hook for '%s' threw: %s\n", $plugin, $e->getMessage())
                );
                continue;
            }

            $clean = self::validate($caps);
            if ($clean === null) {
                Toolbox::logInFile(
                    'glpimobile',
                    sprintf("capabilities hook for '%s' returned a malformed shape; skipped\n", $plugin)
                );
                continue;
            }

            $out[$plugin] = $clean;
        }

        // JSONResponse only encodes arrays, and an empty PHP array encodes as
        // `[]` — the app expects a map, so force object semantics.
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode((object) $out, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Enforce the hook's return contract:
     * `['version' => string, 'features' => [string => bool, ...]]`.
     *
     * @return array{version: string, features: object}|null null when malformed
     */
    private static function validate(mixed $caps): ?array
    {
        if (
            !is_array($caps)
            || !isset($caps['version'], $caps['features'])
            || !is_string($caps['version'])
            || !is_array($caps['features'])
        ) {
            return null;
        }

        $features = [];
        foreach ($caps['features'] as $name => $enabled) {
            if (!is_string($name) || $name === '' || !is_bool($enabled)) {
                return null;
            }
            $features[$name] = $enabled;
        }

        // Cast so an empty feature set still serialises as `{}`.
        return ['version' => $caps['version'], 'features' => (object) $features];
    }
}
