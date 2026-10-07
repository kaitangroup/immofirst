<?php

declare(strict_types=1);

namespace Drupal\immofirst_api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Builds the API's uniform JSON envelope: {success, data, errors}.
 */
final class ApiResponse {

  /**
   * Generic, non-revealing messages per status (used for errors that don't
   * come from an ApiException, e.g. routing or kernel exceptions).
   */
  public const STATUS_MESSAGES = [
    400 => 'Bad request.',
    401 => 'Authentication required.',
    403 => 'Access denied.',
    404 => 'Not found.',
    405 => 'Method not allowed.',
    409 => 'Conflict.',
    413 => 'Request body too large.',
    415 => 'Unsupported media type.',
    422 => 'Unprocessable entity.',
    429 => 'Too many requests.',
    500 => 'Internal server error.',
  ];

  public static function success(mixed $data, int $status = 200, bool $success = TRUE, array $errors = []): JsonResponse {
    return self::build(['success' => $success, 'data' => $data, 'errors' => $errors], $status);
  }

  /**
   * @param array<int, array<string, mixed>> $details
   */
  public static function error(int $status, ?string $message = NULL, array $details = [], array $headers = []): JsonResponse {
    $message ??= self::STATUS_MESSAGES[$status] ?? 'Error.';
    $errors = [['status' => $status, 'message' => $message]];
    foreach ($details as $detail) {
      $errors[] = $detail;
    }
    return self::build(['success' => FALSE, 'data' => NULL, 'errors' => $errors], $status, $headers);
  }

  private static function build(array $body, int $status, array $headers = []): JsonResponse {
    $response = new JsonResponse($body, $status, $headers);
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE);
    // Never cache API responses anywhere (browser, proxy, CDN).
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

}
