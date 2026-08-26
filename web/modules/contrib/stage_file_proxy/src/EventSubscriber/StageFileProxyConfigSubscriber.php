<?php

declare(strict_types=1);

namespace Drupal\stage_file_proxy\EventSubscriber;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stage file proxy subscriber for configuration changes.
 */
class StageFileProxyConfigSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a StageFileProxyConfigSubscriber object.
   */
  public function __construct(
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {
  }

  /**
   * Invalidates 4xx-response cache tag if the origin is added.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   The configuration event.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    $saved_config = $event->getConfig();
    if ($saved_config->getName() === 'stage_file_proxy.settings') {
      if ($event->isChanged('origin') && empty($event->getConfig()->getOriginal('origin'))) {
        // The default behavior is to do nothing if the origin is undefined,
        // so once a new origin is added, any previous 4xx responses in the
        // cache should be invalidated so that stage_file_proxy may intercept
        // it.
        $this->cacheTagsInvalidator->invalidateTags(['4xx-response']);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    $events[ConfigEvents::SAVE][] = ['onConfigSave'];

    return $events;
  }

}
