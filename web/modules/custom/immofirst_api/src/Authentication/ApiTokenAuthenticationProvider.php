<?php

declare(strict_types=1);

namespace Drupal\immofirst_api\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\immofirst_api\Service\ApiTokenManager;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Authenticates /api/v1/* requests carrying "Authorization: Bearer <token>".
 *
 * A valid token authenticates the request as the configured service
 * account ($settings['immofirst_api_uid']), so every API operation runs
 * through Drupal's normal entity/field/query access checks for that
 * account. Missing or invalid tokens leave the request anonymous; the
 * route access check then denies it and ApiExceptionSubscriber answers
 * with a JSON 401.
 */
final class ApiTokenAuthenticationProvider implements AuthenticationProviderInterface {

  public function __construct(
    private readonly ApiTokenManager $tokenManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request) {
    return str_starts_with($request->getPathInfo(), '/api/v1/')
      && $this->tokenManager->extractBearerToken($request) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request) {
    $ip = (string) $request->getClientIp();

    // TEMPORARY DIAGNOSTIC — remove after debugging. Logs types/booleans only,
    // never the token or its length.
    $this->logger->notice('DIAG authenticate: sapi=@sapi header_token=@hdr token_setting_type=@tok uid_setting_type=@uid configured=@cfg', [
      '@sapi' => PHP_SAPI,
      '@hdr' => $this->tokenManager->extractBearerToken($request) !== NULL ? 'yes' : 'no',
      '@tok' => get_debug_type(\Drupal\Core\Site\Settings::get('immofirst_api_token')),
      '@uid' => get_debug_type(\Drupal\Core\Site\Settings::get('immofirst_api_uid')),
      '@cfg' => $this->tokenManager->isConfigured() ? 'yes' : 'no',
    ]);

    if ($this->tokenManager->isBlockedByFailedAttempts($ip)) {
      throw new TooManyRequestsHttpException(900);
    }

    $token = $this->tokenManager->extractBearerToken($request);
    if ($token === NULL || !$this->tokenManager->isConfigured() || !$this->tokenManager->isValidToken($token)) {
      $this->tokenManager->registerFailedAttempt($ip);
      // Deliberately no token (or token fragment) in the log.
      $this->logger->notice('Rejected API request with an invalid bearer token from @ip.', ['@ip' => $ip]);
      return NULL;
    }

    if (!$this->tokenManager->registerRequest($ip)) {
      throw new TooManyRequestsHttpException(60);
    }

    $account = $this->entityTypeManager->getStorage('user')->load($this->tokenManager->serviceAccountId());
    if (!$account instanceof UserInterface || $account->isBlocked() || $account->isAnonymous()) {
      $this->logger->error('The configured API service account ($settings[\'immofirst_api_uid\']) does not exist or is blocked.');
      return NULL;
    }

    $request->attributes->set(ApiTokenManager::AUTHENTICATED_ATTRIBUTE, TRUE);
    return $account;
  }

}
