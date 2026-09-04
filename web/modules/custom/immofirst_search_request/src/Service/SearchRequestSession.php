<?php

declare(strict_types=1);

namespace Drupal\immofirst_search_request\Service;

use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Reads/writes wizard progress + step data to PrivateTempStore.
 *
 * Keeping this behind a dedicated service (rather than calling
 * PrivateTempStoreFactory directly from the form) means the storage key,
 * the "collection" name, and the shape of the stored data are defined
 * in exactly one place.
 */
final class SearchRequestSession {

  /**
   * The tempstore collection name for this wizard.
   */
  private const COLLECTION = 'immofirst_search_request';

  /**
   * Key used for the wizard's data array within the collection.
   */
  private const DATA_KEY = 'wizard_data';

  /**
   * Key used for the current step number within the collection.
   */
  private const STEP_KEY = 'current_step';

  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * Gets the current step (1-based). Defaults to 1 if none stored yet.
   */
  public function getCurrentStep(): int {
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $step = $store->get(self::STEP_KEY);

    return is_int($step) && $step > 0 ? $step : 1;
  }

  /**
   * Sets the current step.
   */
  public function setCurrentStep(int $step): void {
    $this->tempStoreFactory->get(self::COLLECTION)->set(self::STEP_KEY, $step);
  }

  /**
   * Gets all wizard data collected so far.
   *
   * @return array<string, mixed>
   */
  public function getData(): array {
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $data = $store->get(self::DATA_KEY);

    return is_array($data) ? $data : $this->defaultData();
  }

  /**
   * Gets a single top-level key from the wizard data (e.g. 'step1').
   */
  public function getStepData(string $key): array {
    $data = $this->getData();

    return is_array($data[$key] ?? NULL) ? $data[$key] : [];
  }

  /**
   * Merges $values into the wizard data under $key (e.g. 'step1').
   *
   * @param array<string, mixed> $values
   */
  public function setStepData(string $key, array $values): void {
    $data = $this->getData();
    $data[$key] = $values;
    $this->tempStoreFactory->get(self::COLLECTION)->set(self::DATA_KEY, $data);
  }

  /**
   * Clears all wizard data and resets the step to 1.
   *
   * Called after a successful final submission so a fresh visit to
   * /suchauftrag-erstellen starts a brand-new wizard.
   */
  public function clear(): void {
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $store->delete(self::DATA_KEY);
    $store->delete(self::STEP_KEY);
  }

  /**
   * Default empty data shape, keyed by step.
   *
   * @return array<string, mixed>
   */
  private function defaultData(): array {
    return [
      'step1' => [],
      'step2' => [],
      'step3' => [],
      'step4' => [],
      'step5' => [],
    ];
  }

}
