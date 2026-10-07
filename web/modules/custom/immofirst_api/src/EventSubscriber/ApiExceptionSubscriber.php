<?php

declare(strict_types=1);

namespace Drupal\immofirst_api\EventSubscriber;

use Drupal\immofirst_api\ApiResponse;
use Drupal\immofirst_api\Service\ApiTokenManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns every exception on /api/v1/* into the API's JSON error envelope.
 *
 * - Generic messages only: no exception text, stack traces, SQL or paths.
 * - Access denied for a request that was NOT authenticated by the token
 *   (missing/invalid token, session cookie instead of token) becomes 401
 *   with a Bearer challenge; with a valid token it stays 403.
 *
 * Runs after core's authentication subscribers (75/80) and the exception
 * logger (50), before core's HTML/JSON error renderers.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::EXCEPTION => ['onException', 40]];
  }

  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();
    if (!str_starts_with($request->getPathInfo(), '/api/v1/')) {
      return;
    }

    $throwable = $event->getThrowable();
    $headers = [];
    if ($throwable instanceof HttpExceptionInterface) {
      $status = $throwable->getStatusCode();
      // Keep protocol headers (Allow, Retry-After) only.
      foreach (['Allow', 'Retry-After'] as $name) {
        if (isset($throwable->getHeaders()[$name])) {
          $headers[$name] = $throwable->getHeaders()[$name];
        }
      }
    }
    else {
      $status = 500;
      $this->logger->error('Unhandled API exception: @class', ['@class' => $throwable::class]);
    }

    $authenticated = $request->attributes->get(ApiTokenManager::AUTHENTICATED_ATTRIBUTE) === TRUE;
    if (in_array($status, [401, 403], TRUE) && !$authenticated) {
      $status = 401;
      $headers['WWW-Authenticate'] = 'Bearer realm="immofirst_api"';
    }

    if (!isset(ApiResponse::STATUS_MESSAGES[$status])) {
      $status = $status >= 500 ? 500 : 400;
    }

    $event->setResponse(ApiResponse::error($status, NULL, [], $headers));
  }

}
