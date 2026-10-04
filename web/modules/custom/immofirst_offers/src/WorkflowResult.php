<?php

declare(strict_types=1);

namespace Drupal\immofirst_offers;

/**
 * Outcome of an OfferWorkflow transition, for the admin UI messages.
 */
final class WorkflowResult {

  private function __construct(
    public readonly bool $success,
    public readonly \Stringable|string $message,
    public readonly bool $warning = FALSE,
  ) {}

  public static function success(\Stringable|string $message, bool $warning = FALSE): self {
    return new self(TRUE, $message, $warning);
  }

  public static function failure(\Stringable|string $message): self {
    return new self(FALSE, $message);
  }

}
