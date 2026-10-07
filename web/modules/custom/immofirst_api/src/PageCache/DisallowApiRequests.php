<?php

declare(strict_types=1);

namespace Drupal\immofirst_api\PageCache;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Page Cache must never serve or store /api/v1/* responses.
 *
 * Page Cache runs before authentication, so without this a cached
 * response for an API URL could be served regardless of the bearer token.
 */
final class DisallowApiRequests implements RequestPolicyInterface {

  public function check(Request $request) {
    return str_starts_with($request->getPathInfo(), '/api/v1/') ? self::DENY : NULL;
  }

}
