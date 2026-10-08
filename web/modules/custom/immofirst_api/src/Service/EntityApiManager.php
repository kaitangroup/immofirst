<?php

declare(strict_types=1);

namespace Drupal\immofirst_api\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityNullStorage;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableEntityBundleInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;
use Drupal\immofirst_api\Exception\ApiException;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Generic CRUD / bulk operations on any content entity type.
 *
 * Everything is discovered from the entity type definitions at runtime —
 * no entity type, bundle or field is hard-coded (the only type-specific
 * code are two safety guards: file URIs and protected user accounts).
 * All reads and writes go through the Entity API (entity queries with
 * access checks, storage load/create/save/delete, typed-data field
 * setters, entity validation) — never raw SQL.
 *
 * Every operation is checked against the acting account (the API service
 * account): entity access (view/create/update/delete), field access
 * (view/edit), and query access.
 */
final class EntityApiManager {

  public const DEFAULT_LIMIT = 50;
  public const MAX_LIMIT = 100;
  public const MAX_BULK_ITEMS = 100;
  public const MAX_IDS_FILTER = 100;
  public const ALL_BATCH_SIZE = 50;
  public const ALL_DEFAULT_MAX = 1000;
  public const ALL_MAX_MAX = 5000;

  /**
   * Wall-clock budget for one *_all request; the client repeats the call
   * while "remaining" > 0.
   */
  private const ALL_TIME_BUDGET = 20;

  private const MAX_VALUE_DEPTH = 8;
  private const MAX_SORT_FIELDS = 3;

  private const FILTER_OPERATORS = [
    '=', '<>', '>', '>=', '<', '<=',
    'IN', 'NOT IN', 'CONTAINS', 'STARTS_WITH', 'ENDS_WITH',
    'IS NULL', 'IS NOT NULL',
  ];

  /**
   * Field types never returned and never usable in filters/sorts.
   */
  private const HIDDEN_FIELD_TYPES = ['password'];

  /**
   * Bookkeeping fields the Entity API manages itself — never writable.
   */
  private const ALWAYS_PROTECTED_FIELDS = [
    'default_langcode',
    'revision_default',
    'revision_translation_affected',
    'content_translation_source',
    'content_translation_outdated',
  ];

  /**
   * Stream wrapper schemes a file entity may point to.
   */
  private const ALLOWED_FILE_SCHEMES = ['public', 'private'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly LockBackendInterface $lock,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly TimeInterface $time,
    private readonly ApiTokenManager $tokenManager,
    private readonly LoggerInterface $logger,
  ) {}

  /* ===================================================================
   * Entity type discovery
   * =================================================================== */

  /**
   * Resolves and validates an entity type id from the URL.
   *
   * Supported: every content (fieldable) entity type with real storage
   * that isn't internal and isn't listed in
   * $settings['immofirst_api_denied_entity_types']. Config entities are
   * deliberately excluded (they belong to config sync, not content CRUD).
   */
  public function getEntityType(string $entityTypeId): ContentEntityTypeInterface {
    if (preg_match('/^[a-z0-9_]{1,32}$/', $entityTypeId) !== 1) {
      throw new ApiException(400, 'Invalid entity type.');
    }
    $definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if (!$definition instanceof ContentEntityTypeInterface || !$this->isSupported($definition)) {
      throw new ApiException(404, 'Unknown or unsupported entity type.');
    }
    return $definition;
  }

  /**
   * All supported entity types with their dynamically discovered keys.
   *
   * @return array<int, array<string, mixed>>
   */
  public function listEntityTypes(): array {
    $types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $definition) {
      if ($definition instanceof ContentEntityTypeInterface && $this->isSupported($definition)) {
        $types[] = $this->describeEntityType($definition, FALSE);
      }
    }
    usort($types, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
    return $types;
  }

  /**
   * Keys, capabilities, bundles and (optionally) fields of an entity type.
   *
   * @return array<string, mixed>
   */
  public function describeEntityType(ContentEntityTypeInterface $definition, bool $withFields = TRUE): array {
    $bundles = array_keys($this->bundleInfo->getBundleInfo($definition->id()));
    $info = [
      'id' => $definition->id(),
      'label' => (string) $definition->getLabel(),
      'class' => $definition->getClass(),
      'storage' => $definition->getStorageClass(),
      'keys' => [
        'id' => $definition->getKey('id') ?: NULL,
        'uuid' => $definition->getKey('uuid') ?: NULL,
        'bundle' => $definition->getKey('bundle') ?: NULL,
        'label' => $definition->getKey('label') ?: NULL,
        'langcode' => $definition->getKey('langcode') ?: NULL,
        'revision' => $definition->getKey('revision') ?: NULL,
      ],
      'bundles' => $bundles,
      'revisionable' => $definition->isRevisionable(),
      'translatable' => $definition->isTranslatable(),
    ];

    if ($withFields) {
      $info['fields'] = [];
      foreach ($bundles as $bundle) {
        foreach ($this->entityFieldManager->getFieldDefinitions($definition->id(), $bundle) as $name => $field) {
          if (isset($info['fields'][$name])) {
            $info['fields'][$name]['bundles'][] = $bundle;
            continue;
          }
          $info['fields'][$name] = [
            'type' => $field->getType(),
            'label' => (string) $field->getLabel(),
            'required' => $field->isRequired(),
            'read_only' => $field->isReadOnly() || $field->isComputed() || $this->isProtectedField($definition, $name, FALSE),
            'computed' => $field->isComputed(),
            'cardinality' => $field->getFieldStorageDefinition()->getCardinality(),
            'translatable' => $field->isTranslatable(),
            'bundles' => [$bundle],
          ];
        }
      }
    }

    return $info;
  }

  private function isSupported(ContentEntityTypeInterface $definition): bool {
    $denied = (array) Settings::get('immofirst_api_denied_entity_types', []);
    if (in_array($definition->id(), $denied, TRUE) || $definition->isInternal()) {
      return FALSE;
    }
    if (!$definition->hasHandlerClass('storage') || !$definition->getKey('id')) {
      return FALSE;
    }
    $storageClass = $definition->getStorageClass();
    return !is_a($storageClass, ContentEntityNullStorage::class, TRUE);
  }

  /* ===================================================================
   * Read
   * =================================================================== */

  public function load(ContentEntityTypeInterface $type, string|int $id, AccountInterface $account, string $operation = 'view', ?string $langcode = NULL): ContentEntityInterface {
    $this->assertValidId($id);
    $entity = $this->entityTypeManager->getStorage($type->id())->load($id);
    if (!$entity instanceof ContentEntityInterface) {
      throw new ApiException(404, 'Entity not found.');
    }

    if ($langcode !== NULL) {
      if (!$entity->isTranslatable() || !$entity->hasTranslation($langcode)) {
        throw new ApiException(404, 'Translation not found.');
      }
      $entity = $entity->getTranslation($langcode);
    }

    if (!$entity->access($operation, $account)) {
      throw new ApiException(403, 'You are not allowed to ' . $operation . ' this entity.');
    }
    return $entity;
  }

  /**
   * Serializes an entity: every non-computed field the account may view,
   * as plain typed-data values. Password fields are never included.
   *
   * @return array<string, mixed>
   */
  public function serialize(ContentEntityInterface $entity, AccountInterface $account): array {
    $fields = [];
    foreach ($entity->getFields(FALSE) as $name => $items) {
      if (in_array($items->getFieldDefinition()->getType(), self::HIDDEN_FIELD_TYPES, TRUE)) {
        continue;
      }
      if (!$items->access('view', $account)) {
        continue;
      }
      $fields[$name] = $this->plainValue($items->getValue());
    }

    $type = $entity->getEntityType();
    return [
      'entity_type' => $entity->getEntityTypeId(),
      'id' => $entity->id(),
      'uuid' => $entity->uuid(),
      'bundle' => $entity->bundle(),
      'label' => $entity->label() !== NULL ? (string) $entity->label() : NULL,
      'langcode' => $entity->language()->getId(),
      'translations' => $entity->isTranslatable() ? array_keys($entity->getTranslationLanguages()) : [],
      'revision_id' => $type->isRevisionable() && $entity instanceof RevisionableInterface ? $entity->getRevisionId() : NULL,
      'fields' => $fields,
    ];
  }

  /**
   * Paged, filtered, sorted collection read.
   *
   * Query parameters: limit, offset, page, ids (comma list or array),
   * bundle, langcode, sort ("field" / "-field", comma separated, max 3),
   * filter[field]=value or filter[field][value]=..&filter[field][operator]=..
   * (field or field.property; operators: see FILTER_OPERATORS).
   *
   * @return array<string, mixed>
   */
  public function collection(ContentEntityTypeInterface $type, array $params, AccountInterface $account): array {
    $limit = $this->intParam($params['limit'] ?? NULL, self::DEFAULT_LIMIT, 1, self::MAX_LIMIT, 'limit');
    $offset = $this->intParam($params['offset'] ?? NULL, 0, 0, PHP_INT_MAX, 'offset');
    if (isset($params['page']) && !isset($params['offset'])) {
      $offset = $this->intParam($params['page'], 0, 0, 1000000, 'page') * $limit;
    }

    $bundle = isset($params['bundle']) ? $this->assertBundle($type, $params['bundle']) : NULL;
    $query = $this->baseQuery($type, $bundle);

    if (isset($params['ids'])) {
      $ids = is_array($params['ids']) ? $params['ids'] : explode(',', (string) $params['ids']);
      $ids = array_values(array_filter(array_map('trim', $ids), static fn ($id) => $id !== ''));
      if ($ids === [] || count($ids) > self::MAX_IDS_FILTER) {
        throw new ApiException(400, 'ids must contain 1 to ' . self::MAX_IDS_FILTER . ' ids.');
      }
      array_walk($ids, fn ($id) => $this->assertValidId($id));
      $query->condition($type->getKey('id'), $ids, 'IN');
    }

    if (isset($params['filter'])) {
      if (!is_array($params['filter'])) {
        throw new ApiException(400, 'filter must be an object: filter[field]=value.');
      }
      foreach ($params['filter'] as $path => $spec) {
        $this->applyFilter($query, $type, $bundle, (string) $path, $spec, $account);
      }
    }

    $sorts = [];
    if (isset($params['sort']) && is_string($params['sort']) && $params['sort'] !== '') {
      $sorts = array_slice(explode(',', $params['sort']), 0, self::MAX_SORT_FIELDS + 1);
      if (count($sorts) > self::MAX_SORT_FIELDS) {
        throw new ApiException(400, 'At most ' . self::MAX_SORT_FIELDS . ' sort fields.');
      }
    }
    foreach ($sorts as $sort) {
      $sort = trim($sort);
      $direction = str_starts_with($sort, '-') ? 'DESC' : 'ASC';
      $path = ltrim($sort, '-');
      $this->assertQueryableField($type, $bundle, $path, $account);
      $query->sort($path, $direction);
    }
    // Stable pagination.
    $query->sort($type->getKey('id'), 'ASC');

    $total = (int) (clone $query)->count()->execute();
    $ids = $query->range($offset, $limit)->execute();

    $langcode = isset($params['langcode']) ? $this->assertLangcode($params['langcode']) : NULL;
    $items = [];
    foreach ($this->entityTypeManager->getStorage($type->id())->loadMultiple($ids) as $entity) {
      if (!$entity instanceof ContentEntityInterface) {
        continue;
      }
      if ($langcode !== NULL && $entity->isTranslatable() && $entity->hasTranslation($langcode)) {
        $entity = $entity->getTranslation($langcode);
      }
      if ($entity->access('view', $account)) {
        $items[] = $this->serialize($entity, $account);
      }
    }

    return [
      'items' => $items,
      'total' => $total,
      'limit' => $limit,
      'offset' => $offset,
    ];
  }

  /* ===================================================================
   * Create / update / delete
   * =================================================================== */

  public function create(ContentEntityTypeInterface $type, array $payload, AccountInterface $account): ContentEntityInterface {
    $values = [];
    $bundleKey = $type->getKey('bundle');
    if ($bundleKey) {
      if (!array_key_exists($bundleKey, $payload)) {
        throw new ApiException(422, 'Missing bundle.', [['field' => $bundleKey, 'message' => 'This entity type requires "' . $bundleKey . '".']]);
      }
      $bundle = $this->assertBundle($type, $this->scalarFromFieldValue($payload[$bundleKey]));
      $values[$bundleKey] = $bundle;
      unset($payload[$bundleKey]);
    }
    else {
      $bundle = $type->id();
    }

    $langcodeKey = $type->getKey('langcode');
    if ($langcodeKey && array_key_exists($langcodeKey, $payload)) {
      $values[$langcodeKey] = $this->assertLangcode($this->scalarFromFieldValue($payload[$langcodeKey]));
      unset($payload[$langcodeKey]);
    }

    $access = $this->entityTypeManager->getAccessControlHandler($type->id())->createAccess($bundle, $account, [], TRUE);
    if (!$access->isAllowed()) {
      throw new ApiException(403, 'You are not allowed to create this entity.');
    }

    $entity = $this->entityTypeManager->getStorage($type->id())->create($values);
    if (!$entity instanceof ContentEntityInterface) {
      throw new ApiException(400, 'Unsupported entity type.');
    }

    $this->applyValues($entity, $payload, $account, TRUE);
    $this->validate($entity, $account);
    $this->persist(static fn () => $entity->save());

    $this->logger->info('API created @type @id (uid @uid).', ['@type' => $type->id(), '@id' => $entity->id(), '@uid' => $account->id()]);
    return $entity;
  }

  public function update(ContentEntityInterface $entity, array $payload, AccountInterface $account): ContentEntityInterface {
    if ($payload === []) {
      throw new ApiException(422, 'No fields to update.');
    }
    if ($entity instanceof UserInterface && $this->isProtectedUser($entity)) {
      throw new ApiException(403, 'This user account cannot be changed via the API.');
    }
    $this->applyValues($entity, $payload, $account, FALSE);
    $this->prepareRevision($entity, $account);
    $this->validate($entity, $account);
    $this->persist(static fn () => $entity->save());

    $this->logger->info('API updated @type @id (uid @uid).', ['@type' => $entity->getEntityTypeId(), '@id' => $entity->id(), '@uid' => $account->id()]);
    return $entity;
  }

  public function delete(ContentEntityInterface $entity, AccountInterface $account): void {
    $this->assertDeletable($entity);
    if (!$entity->access('delete', $account)) {
      throw new ApiException(403, 'You are not allowed to delete this entity.');
    }
    $this->persist(static fn () => $entity->getUntranslated()->delete());
    $this->logger->notice('API deleted @type @id (uid @uid).', ['@type' => $entity->getEntityTypeId(), '@id' => $entity->id(), '@uid' => $account->id()]);
  }

  /* ===================================================================
   * Bulk
   * =================================================================== */

  /**
   * @return array<string, array>
   */
  public function bulkCreate(ContentEntityTypeInterface $type, mixed $items, AccountInterface $account): array {
    $items = $this->assertBulkList($items, 'items');
    $result = $this->emptyBulkResult();
    foreach ($items as $index => $item) {
      try {
        if (!is_array($item)) {
          throw new ApiException(400, 'Each item must be an object of field values.');
        }
        $entity = $this->create($type, $item, $account);
        $result['created'][] = ['index' => $index, 'id' => $entity->id(), 'uuid' => $entity->uuid()];
      }
      catch (ApiException $e) {
        $result['failed'][] = $this->failure($index, NULL, $e);
      }
    }
    return $result;
  }

  /**
   * @return array<string, array>
   */
  public function bulkUpdate(ContentEntityTypeInterface $type, mixed $items, AccountInterface $account): array {
    $items = $this->assertBulkList($items, 'items');
    $result = $this->emptyBulkResult();
    foreach ($items as $index => $item) {
      $id = is_array($item) ? ($item['id'] ?? NULL) : NULL;
      try {
        if (!is_array($item) || !is_scalar($id) || !isset($item['fields']) || !is_array($item['fields'])) {
          throw new ApiException(400, 'Each item must be {"id": ..., "fields": {...}}.');
        }
        $langcode = isset($item['langcode']) ? $this->assertLangcode($item['langcode']) : NULL;
        $entity = $this->load($type, $id, $account, 'update', $langcode);
        $this->update($entity, $item['fields'], $account);
        $result['updated'][] = ['index' => $index, 'id' => $entity->id()];
      }
      catch (ApiException $e) {
        $result['failed'][] = $this->failure($index, is_scalar($id) ? $id : NULL, $e);
      }
    }
    return $result;
  }

  /**
   * @return array<string, array>
   */
  public function bulkDelete(ContentEntityTypeInterface $type, mixed $ids, AccountInterface $account): array {
    $ids = $this->assertBulkList($ids, 'ids');
    $result = $this->emptyBulkResult();
    foreach ($ids as $index => $id) {
      try {
        if (!is_scalar($id)) {
          throw new ApiException(400, 'Each id must be a string or integer.');
        }
        $entity = $this->load($type, $id, $account, 'delete');
        $this->delete($entity, $account);
        $result['deleted'][] = ['index' => $index, 'id' => $id];
      }
      catch (ApiException $e) {
        $result['failed'][] = $this->failure($index, is_scalar($id) ? $id : NULL, $e);
      }
    }
    return $result;
  }

  /**
   * Administrative "whole collection" operation: delete_all / update_all.
   *
   * Walks the collection by ascending id in batches of ALL_BATCH_SIZE
   * (loading only one batch at a time, resetting the storage cache after
   * each), checks access per entity, and stops after $max entities or
   * ALL_TIME_BUDGET seconds — "remaining" tells the client whether to
   * call again. Only one *_all run per entity type at a time (lock → 409).
   *
   * @return array<string, mixed>
   */
  public function processAll(ContentEntityTypeInterface $type, string $operation, array $payload, AccountInterface $account): array {
    if (($payload['confirm'] ?? NULL) !== TRUE) {
      throw new ApiException(422, 'This operation requires "confirm": true.');
    }
    $fields = NULL;
    if ($operation === 'update_all') {
      if (!isset($payload['fields']) || !is_array($payload['fields']) || $payload['fields'] === []) {
        throw new ApiException(422, 'update_all requires a non-empty "fields" object.');
      }
      $fields = $payload['fields'];
    }
    if ($operation === 'delete_all' && $type->id() === 'user' && !Settings::get('immofirst_api_allow_user_delete', FALSE)) {
      throw new ApiException(403, 'Deleting users via the API is disabled.');
    }

    $bundle = isset($payload['bundle']) ? $this->assertBundle($type, $payload['bundle']) : NULL;
    $max = $this->intParam($payload['max'] ?? NULL, self::ALL_DEFAULT_MAX, 1, self::ALL_MAX_MAX, 'max');
    // Continue after the "last_id" of the previous call (so entities that
    // keep failing are not retried forever); omitted = start at the
    // beginning.
    $afterId = NULL;
    if (isset($payload['after_id'])) {
      $this->assertValidId($payload['after_id']);
      $afterId = $payload['after_id'];
    }

    $lockName = 'immofirst_api:all:' . $type->id();
    if (!$this->lock->acquire($lockName, self::ALL_TIME_BUDGET + 40)) {
      throw new ApiException(409, 'Another bulk operation on this entity type is already running.');
    }

    $storage = $this->entityTypeManager->getStorage($type->id());
    $idKey = $type->getKey('id');
    $processed = 0;
    $failures = [];
    $failedCount = 0;
    $lastId = $afterId;
    $exhausted = FALSE;
    $started = $this->time->getCurrentMicroTime();

    try {
      while ($processed + $failedCount < $max && ($this->time->getCurrentMicroTime() - $started) < self::ALL_TIME_BUDGET) {
        $query = $this->baseQuery($type, $bundle)
          ->sort($idKey, 'ASC')
          ->range(0, min(self::ALL_BATCH_SIZE, $max - $processed - $failedCount));
        if ($lastId !== NULL) {
          $query->condition($idKey, $lastId, '>');
        }
        $ids = array_values($query->execute());
        if ($ids === []) {
          $exhausted = TRUE;
          break;
        }

        foreach ($storage->loadMultiple($ids) as $id => $entity) {
          try {
            if (!$entity instanceof ContentEntityInterface) {
              throw new ApiException(400, 'Unsupported entity.');
            }
            if ($operation === 'delete_all') {
              $this->delete($entity, $account);
            }
            else {
              if (!$entity->access('update', $account)) {
                throw new ApiException(403, 'You are not allowed to update this entity.');
              }
              $this->update($entity, $fields, $account);
            }
            $processed++;
          }
          catch (ApiException $e) {
            $failedCount++;
            if (count($failures) < 50) {
              $failures[] = $this->failure(NULL, $id, $e);
            }
          }
          catch (\Throwable $e) {
            $failedCount++;
            $this->logger->error('API @op failed for @type @id: @class', ['@op' => $operation, '@type' => $type->id(), '@id' => $id, '@class' => $e::class]);
            if (count($failures) < 50) {
              $failures[] = ['id' => $id, 'status' => 500, 'message' => 'Internal error while processing this entity.'];
            }
          }
        }
        // Continue after the last id of this batch, whether or not its
        // entities succeeded — a failing entity is never retried in a loop.
        $lastId = end($ids);
        $storage->resetCache($ids);
      }
    }
    finally {
      $this->lock->release($lockName);
    }

    // Entities not yet attempted (after the last id handled in this run).
    $remainingQuery = $this->baseQuery($type, $bundle)->count();
    if ($lastId !== NULL) {
      $remainingQuery->condition($idKey, $lastId, '>');
    }
    $remaining = (int) $remainingQuery->execute();

    $this->logger->notice('API @op on @type: @processed processed, @failed failed, @remaining remaining (uid @uid).', [
      '@op' => $operation,
      '@type' => $type->id(),
      '@processed' => $processed,
      '@failed' => $failedCount,
      '@remaining' => $remaining,
      '@uid' => $account->id(),
    ]);

    return [
      'operation' => $operation,
      'entity_type' => $type->id(),
      'bundle' => $bundle,
      'processed' => $processed,
      'failed' => $failedCount,
      'failures' => $failures,
      'remaining' => $remaining,
      // Pass back as "after_id" to continue where this call stopped.
      'last_id' => $lastId,
      'complete' => $exhausted || $remaining === 0,
    ];
  }

  /* ===================================================================
   * Field handling
   * =================================================================== */

  /**
   * Sets the given field values after checking each field exists, is
   * writable, the account may edit it, and the value is plain JSON data.
   * Unknown/forbidden fields are rejected (never silently ignored).
   */
  private function applyValues(ContentEntityInterface $entity, array $values, AccountInterface $account, bool $isNew): void {
    $type = $entity->getEntityType();
    $invalid = [];
    $forbidden = [];

    foreach ($values as $name => $value) {
      $name = (string) $name;
      if (!$entity->hasField($name)) {
        $invalid[] = ['field' => $name, 'message' => 'Unknown field.'];
        continue;
      }
      $definition = $entity->getFieldDefinition($name);
      if ($definition->isComputed() || $definition->isReadOnly() || $this->isProtectedField($type, $name, $isNew)) {
        $invalid[] = ['field' => $name, 'message' => 'Field is read-only.'];
        continue;
      }
      if (!$this->isPlainValue($value)) {
        $invalid[] = ['field' => $name, 'message' => 'Value must be JSON data (string, number, boolean, null, array or object).'];
        continue;
      }
      if (!$entity->get($name)->access('edit', $account)) {
        $forbidden[] = ['field' => $name, 'message' => 'You are not allowed to edit this field.'];
        continue;
      }
      if ($definition->getType() === 'file_uri') {
        if (!$isNew) {
          $invalid[] = ['field' => $name, 'message' => 'A file URI cannot be changed.'];
          continue;
        }
        if (!$this->isSafeFileUri($this->scalarFromFieldValue($value))) {
          $invalid[] = ['field' => $name, 'message' => 'Only public:// or private:// URIs without ".." are allowed.'];
          continue;
        }
      }

      try {
        $entity->set($name, $value);
      }
      catch (\InvalidArgumentException | \TypeError $e) {
        $invalid[] = ['field' => $name, 'message' => 'Invalid value for this field.'];
      }
    }

    if ($forbidden !== []) {
      throw new ApiException(403, 'Field access denied.', $forbidden);
    }
    if ($invalid !== []) {
      throw new ApiException(422, 'Invalid fields.', $invalid);
    }
  }

  /**
   * Runs an Entity API save/delete; storage failures become API errors
   * (409 for unique/foreign key conflicts, otherwise a generic 500) so one
   * failing item never aborts a bulk run. Only the exception class is
   * logged — storage messages can contain SQL and values.
   */
  private function persist(callable $operation): void {
    try {
      $operation();
    }
    catch (EntityStorageException $e) {
      $conflict = $e->getPrevious() instanceof IntegrityConstraintViolationException;
      $this->logger->error('API storage error: @class', ['@class' => ($e->getPrevious() ?? $e)::class]);
      throw $conflict
        ? new ApiException(409, 'The entity conflicts with existing data.')
        : new ApiException(500, 'The entity could not be saved.');
    }
  }

  private function isProtectedField(ContentEntityTypeInterface $type, string $name, bool $isNew): bool {
    if (in_array($name, self::ALWAYS_PROTECTED_FIELDS, TRUE)) {
      return TRUE;
    }
    $alwaysKeys = ['id', 'uuid', 'revision'];
    $updateKeys = ['bundle', 'langcode'];
    foreach ($isNew ? $alwaysKeys : array_merge($alwaysKeys, $updateKeys) as $key) {
      if ($type->getKey($key) === $name) {
        return TRUE;
      }
    }
    foreach (['revision_user', 'revision_created', 'revision_log_message'] as $metadataKey) {
      if ($type->getRevisionMetadataKey($metadataKey) === $name) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function validate(ContentEntityInterface $entity, AccountInterface $account): void {
    $violations = $entity->validate();
    $violations->filterByFieldAccess($account);
    if ($violations->count() === 0) {
      return;
    }
    $errors = [];
    foreach ($violations as $violation) {
      $errors[] = [
        'field' => $violation->getPropertyPath(),
        'message' => trim(strip_tags((string) $violation->getMessage())),
      ];
    }
    throw new ApiException(422, 'Validation failed.', $errors);
  }

  /**
   * New revision on update when the bundle (or entity type) asks for one.
   */
  private function prepareRevision(ContentEntityInterface $entity, AccountInterface $account): void {
    $type = $entity->getEntityType();
    if (!$type->isRevisionable() || !$entity instanceof RevisionableInterface) {
      return;
    }
    $newRevision = FALSE;
    if ($bundleType = $type->getBundleEntityType()) {
      $bundle = $this->entityTypeManager->getStorage($bundleType)->load($entity->bundle());
      if ($bundle instanceof RevisionableEntityBundleInterface) {
        $newRevision = $bundle->shouldCreateNewRevision();
      }
    }
    if (!$newRevision) {
      return;
    }
    $entity->setNewRevision(TRUE);
    if ($entity instanceof RevisionLogInterface) {
      $entity->setRevisionUserId((int) $account->id());
      $entity->setRevisionCreationTime($this->time->getRequestTime());
      $entity->setRevisionLogMessage('Updated via immofirst_api.');
    }
  }

  /* ===================================================================
   * Filters / sorting
   * =================================================================== */

  private function baseQuery(ContentEntityTypeInterface $type, ?string $bundle): QueryInterface {
    $query = $this->entityTypeManager->getStorage($type->id())->getQuery()->accessCheck(TRUE);
    if ($bundle !== NULL && ($bundleKey = $type->getKey('bundle'))) {
      $query->condition($bundleKey, $bundle);
    }
    return $query;
  }

  private function applyFilter(QueryInterface $query, ContentEntityTypeInterface $type, ?string $bundle, string $path, mixed $spec, AccountInterface $account): void {
    $this->assertQueryableField($type, $bundle, $path, $account);

    $operator = '=';
    $value = $spec;
    if (is_array($spec) && (array_key_exists('value', $spec) || array_key_exists('operator', $spec))) {
      $operator = strtoupper(trim((string) ($spec['operator'] ?? '=')));
      $value = $spec['value'] ?? NULL;
    }
    if (!in_array($operator, self::FILTER_OPERATORS, TRUE)) {
      throw new ApiException(400, 'Unsupported filter operator for "' . $path . '".');
    }

    if ($operator === 'IS NULL') {
      $query->notExists($path);
      return;
    }
    if ($operator === 'IS NOT NULL') {
      $query->exists($path);
      return;
    }
    if (in_array($operator, ['IN', 'NOT IN'], TRUE)) {
      $value = is_array($value) ? array_values($value) : explode(',', (string) $value);
      if ($value === [] || count($value) > self::MAX_IDS_FILTER || array_filter($value, static fn ($v) => !is_scalar($v)) !== []) {
        throw new ApiException(400, 'Filter "' . $path . '" needs 1 to ' . self::MAX_IDS_FILTER . ' scalar values.');
      }
      $query->condition($path, $value, $operator);
      return;
    }
    if (!is_scalar($value)) {
      throw new ApiException(400, 'Filter "' . $path . '" needs a scalar value.');
    }
    $query->condition($path, $value, $operator);
  }

  /**
   * Allows "field" or "field.property" only (no relationship traversal
   * into other entities), on fields the account may view.
   */
  private function assertQueryableField(ContentEntityTypeInterface $type, ?string $bundle, string $path, AccountInterface $account): void {
    if (preg_match('/^([a-z0-9_]{1,64})(?:\.([a-z0-9_]{1,64}))?$/', $path, $matches) !== 1) {
      throw new ApiException(400, 'Invalid field "' . $path . '".');
    }
    $fieldName = $matches[1];
    $property = $matches[2] ?? NULL;

    $definition = $this->findFieldDefinition($type, $bundle, $fieldName);
    if ($definition === NULL || $definition->isComputed() || in_array($definition->getType(), self::HIDDEN_FIELD_TYPES, TRUE)) {
      throw new ApiException(400, 'Unknown or non-queryable field "' . $fieldName . '".');
    }
    if ($property !== NULL && !in_array($property, array_keys($definition->getFieldStorageDefinition()->getPropertyDefinitions()), TRUE)) {
      throw new ApiException(400, 'Unknown property "' . $property . '" on field "' . $fieldName . '".');
    }
    if (!$this->entityTypeManager->getAccessControlHandler($type->id())->fieldAccess('view', $definition, $account)) {
      throw new ApiException(403, 'You are not allowed to query field "' . $fieldName . '".');
    }
  }

  private function findFieldDefinition(ContentEntityTypeInterface $type, ?string $bundle, string $fieldName): ?FieldDefinitionInterface {
    $bundles = $bundle !== NULL ? [$bundle] : array_keys($this->bundleInfo->getBundleInfo($type->id()));
    foreach ($bundles as $candidate) {
      $definitions = $this->entityFieldManager->getFieldDefinitions($type->id(), $candidate);
      if (isset($definitions[$fieldName])) {
        return $definitions[$fieldName];
      }
    }
    return NULL;
  }

  /* ===================================================================
   * Guards and helpers
   * =================================================================== */

  private function assertDeletable(ContentEntityInterface $entity): void {
    if ($entity instanceof UserInterface) {
      if (!Settings::get('immofirst_api_allow_user_delete', FALSE)) {
        throw new ApiException(403, 'Deleting users via the API is disabled.');
      }
      if ($this->isProtectedUser($entity)) {
        throw new ApiException(403, 'This user account cannot be deleted via the API.');
      }
    }
    if ($entity instanceof FileInterface && !$this->isSafeFileUri((string) $entity->getFileUri())) {
      throw new ApiException(403, 'This file is outside the public/private file directories and cannot be deleted via the API.');
    }
  }

  /**
   * Anonymous (0), the site's first admin (1) and the API service account
   * itself are never changed or deleted through the API — no lock-out
   * (e.g. update_all status=0 on users) and no admin account takeover.
   */
  private function isProtectedUser(UserInterface $user): bool {
    return in_array((int) $user->id(), [0, 1, (int) $this->tokenManager->serviceAccountId()], TRUE);
  }

  private function isSafeFileUri(mixed $uri): bool {
    if (!is_string($uri) || $uri === '' || str_contains($uri, "\0") || str_contains($uri, '\\')) {
      return FALSE;
    }
    if (preg_match('#^([a-z]+)://(.+)$#', $uri, $matches) !== 1 || !in_array($matches[1], self::ALLOWED_FILE_SCHEMES, TRUE)) {
      return FALSE;
    }
    foreach (explode('/', $matches[2]) as $segment) {
      if ($segment === '..' || $segment === '.') {
        return FALSE;
      }
    }
    return $this->streamWrapperManager->isValidUri($uri);
  }

  public function assertBundle(ContentEntityTypeInterface $type, mixed $bundle): string {
    if (!is_string($bundle) || $bundle === '') {
      throw new ApiException(422, 'Invalid bundle.');
    }
    if (!array_key_exists($bundle, $this->bundleInfo->getBundleInfo($type->id()))) {
      throw new ApiException(422, 'Unknown bundle "' . $bundle . '" for entity type "' . $type->id() . '".');
    }
    return $bundle;
  }

  public function assertLangcode(mixed $langcode): string {
    if (!is_string($langcode) || preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{1,8}){0,3}$|^(und|zxx)$/', $langcode) !== 1) {
      throw new ApiException(400, 'Invalid langcode.');
    }
    return $langcode;
  }

  public function assertValidId(mixed $id): void {
    if (!is_scalar($id) || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', (string) $id) !== 1) {
      throw new ApiException(400, 'Invalid entity id.');
    }
  }

  /**
   * Reads a scalar from "x", ["x"], [["value" => "x"]], [["target_id" => "x"]]
   * or ["value" => "x"] / ["target_id" => "x"].
   */
  private function scalarFromFieldValue(mixed $value): mixed {
    if (is_array($value)) {
      $first = array_key_exists(0, $value) ? $value[0] : $value;
      if (is_array($first)) {
        return $first['target_id'] ?? $first['value'] ?? NULL;
      }
      return $first;
    }
    return $value;
  }

  private function isPlainValue(mixed $value, int $depth = 0): bool {
    if ($value === NULL || is_scalar($value)) {
      return TRUE;
    }
    if (!is_array($value) || $depth >= self::MAX_VALUE_DEPTH) {
      return FALSE;
    }
    foreach ($value as $item) {
      if (!$this->isPlainValue($item, $depth + 1)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Field values as JSON-safe data (objects some field types keep in their
   * values, e.g. loaded entities, are dropped).
   */
  private function plainValue(mixed $value): mixed {
    if (is_array($value)) {
      $out = [];
      foreach ($value as $key => $item) {
        if (!is_object($item)) {
          $out[$key] = $this->plainValue($item);
        }
      }
      return $out;
    }
    return is_object($value) ? NULL : $value;
  }

  private function intParam(mixed $value, int $default, int $min, int $max, string $name): int {
    if ($value === NULL || $value === '') {
      return $default;
    }
    if (!is_numeric($value) || (int) $value != $value || (int) $value < $min || (int) $value > $max) {
      throw new ApiException(400, '"' . $name . '" must be an integer between ' . $min . ' and ' . $max . '.');
    }
    return (int) $value;
  }

  /**
   * @return array<int|string, mixed>
   */
  private function assertBulkList(mixed $list, string $name): array {
    if (!is_array($list) || !array_is_list($list) || $list === []) {
      throw new ApiException(400, '"' . $name . '" must be a non-empty array.');
    }
    if (count($list) > self::MAX_BULK_ITEMS) {
      throw new ApiException(400, 'At most ' . self::MAX_BULK_ITEMS . ' entries per bulk request; use delete_all/update_all for whole collections.');
    }
    return $list;
  }

  /**
   * @return array<string, array>
   */
  private function emptyBulkResult(): array {
    return ['created' => [], 'updated' => [], 'deleted' => [], 'failed' => []];
  }

  /**
   * @return array<string, mixed>
   */
  private function failure(?int $index, mixed $id, ApiException $e): array {
    return array_filter([
      'index' => $index,
      'id' => $id,
      'status' => $e->getStatus(),
      'message' => $e->getMessage(),
      'errors' => $e->getErrors() ?: NULL,
    ], static fn ($v) => $v !== NULL);
  }

}
