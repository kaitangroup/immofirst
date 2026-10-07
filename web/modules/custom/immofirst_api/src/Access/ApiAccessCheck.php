<?php

declare(strict_types=1);

namespace Drupal\immofirst_api\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\immofirst_api\Service\ApiTokenManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * Route access for every /api/v1/ route (requirement _immofirst_api_access).
 *
 * Requires BOTH a request authenticated by the bearer token provider (not
 * a browser session — and not "anonymous with a mis-granted permission")
 * AND the "use immofirst api" permission on the service account.
 */
final class ApiAccessCheck implements AccessInterface {

  public function access(Request $request, AccountInterface $account): AccessResultInterface {
    // TEMPORARY DIAGNOSTIC — remove after debugging.
    \Drupal::logger('immofirst_api')->notice('DIAG access: attribute=@attr uid=@uid permission=@perm route_auth=@auth', [
      '@attr' => var_export($request->attributes->get(ApiTokenManager::AUTHENTICATED_ATTRIBUTE), TRUE),
      '@uid' => $account->id(),
      '@perm' => $account->hasPermission('use immofirst api') ? 'yes' : 'no',
      '@auth' => implode(',', (array) $request->attributes->get('_route_object')?->getOption('_auth')),
    ]);
    return AccessResult::allowedIf(
      $request->attributes->get(ApiTokenManager::AUTHENTICATED_ATTRIBUTE) === TRUE
      && $account->isAuthenticated()
      && $account->hasPermission('use immofirst api')
    )->setCacheMaxAge(0);
  }

}
