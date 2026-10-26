<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Plugin\PrivateUpdater\UpdaterController;
use RocketTheme\Toolbox\Event\Event;

/**
 * Private Updater: an Update button for the git packages in `user/.dependencies`.
 *
 * GPM, and so the admin's own Update button, only knows the public packages of
 * getgrav.org. A private theme or plugin installed from git (`bin/grav install`)
 * is cloned once and then never updated. This plugin lists those packages with
 * the version installed, the version pinned and the latest release tag, and
 * checks out a new tag in place on request. The work lives in
 * classes/DependencyManager.php; it is reachable three ways:
 *
 *   - Admin2 page "Private packages" (super-admins only), backed by
 *     GET/POST /api/v1/private-updater/... (classes/UpdaterController.php).
 *   - CLI: `bin/plugin private-updater status | sync | update <name> <tag>`
 *     (cli/). `sync` is the deploy step: after `git pull` of the site repo it
 *     installs what is missing and checks out the pinned tags.
 *
 * Git runs with the account's own SSH setup, or with `ssh_key` from the plugin
 * config, so private repos work as long as that key can read them.
 */
class PrivateUpdaterPlugin extends Plugin
{
    public const SLUG = 'private-updater';

    /**
     * PSR-4 for classes/, without a vendor folder. The API caches its routes,
     * so the controller is often called on requests where onApiRegisterRoutes
     * never ran: it has to be autoloadable, not required from that handler.
     */
    public function autoload(): ClassLoader
    {
        $loader = new ClassLoader();
        $loader->addPsr4('Grav\\Plugin\\PrivateUpdater\\', __DIR__ . '/classes');

        return $loader;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
        ];
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = UpdaterController::class;
        $routes->get('/private-updater/packages', [$controller, 'packages']);
        $routes->post('/private-updater/packages/{name}/update', [$controller, 'update']);
    }

    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'] ?? [];
        $items[] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'label' => 'Private packages',
            'icon' => 'fa-code-branch',
            'route' => '/plugin/' . self::SLUG,
            'priority' => 0,
            'authorize' => 'admin.super',
        ];
        $event['items'] = $items;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if ($event['plugin'] !== self::SLUG) {
            return;
        }
        $event['definition'] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'title' => 'Private packages',
            'icon' => 'fa-code-branch',
            'page_type' => 'component',
            'actions' => [
                ['id' => 'refresh', 'label' => 'Check for updates', 'icon' => 'fa-rotate'],
            ],
        ];
    }
}
