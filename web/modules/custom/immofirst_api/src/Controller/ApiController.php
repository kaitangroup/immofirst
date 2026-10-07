<?php

declare(strict_types=1);

namespace Drupal\immofirst_api\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\immofirst_api\ApiResponse;
use Drupal\immofirst_api\Exception\ApiException;
use Drupal\immofirst_api\Service\EntityApiManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Thin HTTP layer for /api/v1/: parses the request, dispatches to
 * EntityApiManager, wraps the result in the {success, data, errors}
 * envelope. Unexpected errors are logged (class only) and answered with a
 * generic 500 — never with exception details.
 */
final class ApiController implements ContainerInjectionInterface {

  private const MAX_BODY_BYTES = 2 * 1024 * 1024;
  private const MAX_JSON_DEPTH = 32;

  public function __construct(
    private readonly EntityApiManager $manager,
    private readonly AccountInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('immofirst_api.entity_manager'),
      $container->get('current_user'),
      $container->get('logger.channel.immofirst_api'),
    );
  }

  /**
   * GET /api/v1/entity-types
   */
  public function entityTypes(): JsonResponse {
    return $this->handle(fn () => ApiResponse::success($this->manager->listEntityTypes()));
  }

  /**
   * GET /api/v1/entity-types/{entity_type}
   */
  public function entityType(string $entity_type): JsonResponse {
    return $this->handle(fn () => ApiResponse::success(
      $this->manager->describeEntityType($this->manager->getEntityType($entity_type))
    ));
  }

  /**
   * GET (collection) / POST (create) /api/v1/entity/{entity_type}
   */
  public function collection(Request $request, string $entity_type): JsonResponse {
    return $this->handle(function () use ($request, $entity_type) {
      $type = $this->manager->getEntityType($entity_type);
      if ($request->isMethod('GET')) {
        return ApiResponse::success($this->manager->collection($type, $request->query->all(), $this->currentUser));
      }
      $entity = $this->manager->create($type, $this->jsonBody($request), $this->currentUser);
      return ApiResponse::success($this->manager->serialize($entity, $this->currentUser), 201);
    });
  }

  /**
   * GET / PATCH / DELETE /api/v1/entity/{entity_type}/{entity_id}
   *
   * Optional ?langcode=xx targets an existing translation (GET / PATCH).
   */
  public function item(Request $request, string $entity_type, string $entity_id): JsonResponse {
    return $this->handle(function () use ($request, $entity_type, $entity_id) {
      $type = $this->manager->getEntityType($entity_type);
      $langcode = $request->query->has('langcode') ? $this->manager->assertLangcode($request->query->get('langcode')) : NULL;

      switch ($request->getMethod()) {
        case 'GET':
          $entity = $this->manager->load($type, $entity_id, $this->currentUser, 'view', $langcode);
          return ApiResponse::success($this->manager->serialize($entity, $this->currentUser));

        case 'PATCH':
          $entity = $this->manager->load($type, $entity_id, $this->currentUser, 'update', $langcode);
          $this->manager->update($entity, $this->jsonBody($request), $this->currentUser);
          return ApiResponse::success($this->manager->serialize($entity, $this->currentUser));

        case 'DELETE':
          $entity = $this->manager->load($type, $entity_id, $this->currentUser, 'delete');
          $this->manager->delete($entity, $this->currentUser);
          return ApiResponse::success(['entity_type' => $type->id(), 'id' => $entity_id, 'deleted' => TRUE]);
      }
      throw new ApiException(405, 'Method not allowed.');
    });
  }

  /**
   * POST / PATCH / DELETE /api/v1/entity/{entity_type}/bulk
   *
   * "operation" must match the method:
   *   POST   create | delete_all | update_all
   *   PATCH  update
   *   DELETE delete
   */
  public function bulk(Request $request, string $entity_type): JsonResponse {
    return $this->handle(function () use ($request, $entity_type) {
      $type = $this->manager->getEntityType($entity_type);
      $body = $this->jsonBody($request);
      $operation = is_string($body['operation'] ?? NULL) ? $body['operation'] : '';

      $allowed = [
        'POST' => ['create', 'delete_all', 'update_all'],
        'PATCH' => ['update'],
        'DELETE' => ['delete'],
      ];
      if (!in_array($operation, $allowed[$request->getMethod()] ?? [], TRUE)) {
        throw new ApiException(400, 'Unsupported "operation" for ' . $request->getMethod() . '. Allowed: ' . implode(', ', $allowed[$request->getMethod()] ?? []) . '.');
      }

      if (in_array($operation, ['delete_all', 'update_all'], TRUE)) {
        $result = $this->manager->processAll($type, $operation, $body, $this->currentUser);
        $errors = $result['failed'] > 0 ? [['status' => 422, 'message' => $result['failed'] . ' entities could not be processed; see data.failures.']] : [];
        return ApiResponse::success($result, 200, $result['failed'] === 0, $errors);
      }

      $result = match ($operation) {
        'create' => $this->manager->bulkCreate($type, $body['items'] ?? NULL, $this->currentUser),
        'update' => $this->manager->bulkUpdate($type, $body['items'] ?? NULL, $this->currentUser),
        'delete' => $this->manager->bulkDelete($type, $body['ids'] ?? NULL, $this->currentUser),
      };
      $failed = count($result['failed']);
      $succeeded = count($result['created']) + count($result['updated']) + count($result['deleted']);
      $errors = $failed > 0 ? [['status' => 422, 'message' => $failed . ' item(s) failed; see data.failed.']] : [];
      // Nothing succeeded → 422; otherwise 200 (partial results are
      // reported per item, success=false).
      $status = $succeeded === 0 ? 422 : ($operation === 'create' && $failed === 0 ? 201 : 200);
      return ApiResponse::success($result, $status, $failed === 0, $errors);
    });
  }

  /**
   * Decodes a JSON object request body.
   *
   * @return array<string, mixed>
   */
  private function jsonBody(Request $request): array {
    $contentType = (string) $request->headers->get('Content-Type', '');
    if (!str_starts_with(strtolower(trim($contentType)), 'application/json')) {
      throw new ApiException(415, 'Content-Type must be application/json.');
    }
    $content = (string) $request->getContent();
    if (strlen($content) > self::MAX_BODY_BYTES) {
      throw new ApiException(413, 'Request body too large.');
    }
    try {
      $data = json_decode($content, TRUE, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }
    catch (\JsonException) {
      throw new ApiException(400, 'Malformed JSON.');
    }
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
      throw new ApiException(400, 'The request body must be a JSON object.');
    }
    return $data;
  }

  /**
   * Runs an action and turns errors into the JSON envelope.
   */
  private function handle(callable $action): JsonResponse {
    try {
      return $action();
    }
    catch (ApiException $e) {
      return ApiResponse::error($e->getStatus(), $e->getMessage(), $e->getErrors());
    }
    catch (\Throwable $e) {
      // Class name only: messages of storage/DB exceptions can contain
      // SQL and values.
      $this->logger->error('Unhandled API error: @class', ['@class' => $e::class]);
      return ApiResponse::error(500);
    }
  }

}
