<?php

declare(strict_types=1);

namespace Drupal\immofirst_api\Exception;

/**
 * A client-facing API error: HTTP status, safe message, optional details.
 *
 * Only ever carries messages written for the API client — never raw
 * exception messages, SQL, paths or tokens.
 */
final class ApiException extends \RuntimeException {

  /**
   * @param int $status
   *   HTTP status code (400, 403, 404, 409, 422, ...).
   * @param string $message
   *   Safe, human-readable message.
   * @param array<int, array<string, mixed>> $errors
   *   Optional details, e.g. [['field' => 'title', 'message' => '...']].
   */
  public function __construct(
    private readonly int $status,
    string $message,
    private readonly array $errors = [],
  ) {
    parent::__construct($message);
  }

  public function getStatus(): int {
    return $this->status;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function getErrors(): array {
    return $this->errors;
  }

}
