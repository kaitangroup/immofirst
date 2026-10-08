<?php

declare(strict_types=1);

namespace Drupal\immofirst_api\Service;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Bearer token handling and request throttling for the API.
 *
 * Configuration lives only in settings.php (fed from the environment),
 * never in exported config:
 *   $settings['immofirst_api_token']  the shared secret (>= 32 chars)
 *   $settings['immofirst_api_uid']    the Drupal service account the
 *                                     token authenticates as
 *
 * The token is never logged, stored, or echoed back.
 */
final class ApiTokenManager {

  /**
   * Tokens shorter than this are treated as "not configured".
   */
  public const MIN_TOKEN_LENGTH = 32;

  /**
   * Request attribute set once a request was authenticated by the token.
   */
  public const AUTHENTICATED_ATTRIBUTE = '_immofirst_api_authenticated';

  private const FLOOD_FAILED_AUTH = 'immofirst_api.failed_auth';
  private const FAILED_AUTH_LIMIT = 10;
  private const FAILED_AUTH_WINDOW = 900;

  private const FLOOD_REQUEST = 'immofirst_api.request';
  private const REQUEST_LIMIT = 600;
  private const REQUEST_WINDOW = 60;

  public function __construct(
    private readonly FloodInterface $flood,
  ) {}

  public function isConfigured(): bool {
    return $this->configuredToken() !== NULL && $this->serviceAccountId() !== NULL;
  }

  public function serviceAccountId(): ?int {
    $uid = Settings::get('immofirst_api_uid');
    return is_numeric($uid) && (int) $uid > 0 ? (int) $uid : NULL;
  }

  /**
   * The Bearer token from the Authorization header, or NULL if absent or
   * malformed.
   */
  public function extractBearerToken(Request $request): ?string {
    $header = (string) $request->headers->get('Authorization', '');
    if (preg_match('/^Bearer\s+([A-Za-z0-9._~+\/=-]{1,512})$/', trim($header), $matches) !== 1) {
      return NULL;
    }
    return $matches[1];
  }

  /**
   * Constant-time comparison (hashed first, so length isn't leaked either).
   */
  public function isValidToken(string $token): bool {
    $configured = $this->configuredToken();
    if ($configured === NULL) {
      return FALSE;
    }
    return hash_equals(hash('sha256', $configured), hash('sha256', $token));
  }

  public function isBlockedByFailedAttempts(string $ip): bool {
    return !$this->flood->isAllowed(self::FLOOD_FAILED_AUTH, self::FAILED_AUTH_LIMIT, self::FAILED_AUTH_WINDOW, $ip);
  }

  public function registerFailedAttempt(string $ip): void {
    $this->flood->register(self::FLOOD_FAILED_AUTH, self::FAILED_AUTH_WINDOW, $ip);
  }

  /**
   * Registers one authenticated request; FALSE once the rate limit is hit.
   */
  public function registerRequest(string $ip): bool {
    if (!$this->flood->isAllowed(self::FLOOD_REQUEST, self::REQUEST_LIMIT, self::REQUEST_WINDOW, $ip)) {
      return FALSE;
    }
    $this->flood->register(self::FLOOD_REQUEST, self::REQUEST_WINDOW, $ip);
    return TRUE;
  }

  private function configuredToken(): ?string {
    $token = Settings::get('immofirst_api_token');
    return is_string($token) && strlen($token) >= self::MIN_TOKEN_LENGTH ? $token : NULL;
  }

}
