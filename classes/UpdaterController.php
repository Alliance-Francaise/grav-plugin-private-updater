<?php

namespace Grav\Plugin\PrivateUpdater;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * REST endpoints behind the admin page. Super-admins only: an update runs git
 * on the server and changes the code the whole site executes.
 *
 *   GET  /private-updater/packages               status + available tags
 *   POST /private-updater/packages/{name}/update  body {"tag": "v1.2.3"}
 */
class UpdaterController extends AbstractApiController
{
    private const PERMISSION = 'admin.super';

    public function packages(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        try {
            return ApiResponse::create($this->manager()->status(true));
        } catch (RuntimeException $e) {
            throw new ApiException(500, 'Private updater', $e->getMessage());
        }
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        $name = (string) $this->getRouteParam($request, 'name');
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['tag']);
        $tag = (string) $body['tag'];
        if (!preg_match(DependencyManager::TAG_PATTERN, $tag)) {
            throw new ValidationException("'$tag' is not a release tag.");
        }

        $who = 'admin:' . $this->getUser($request)->username;
        try {
            $row = $this->manager()->update($name, $tag, $who);
        } catch (RuntimeException $e) {
            throw new ApiException(409, 'Update refused', $e->getMessage());
        }

        return ApiResponse::create($row);
    }

    private function manager(): DependencyManager
    {
        return new DependencyManager(GRAV_ROOT, (string) $this->config->get('plugins.private-updater.ssh_key', ''));
    }
}
