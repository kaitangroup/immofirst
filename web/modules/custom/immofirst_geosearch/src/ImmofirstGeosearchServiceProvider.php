<?php

declare(strict_types=1);

namespace Drupal\immofirst_geosearch;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives Geocoder's HTTP adapter a client with short timeouts.
 *
 * @see immofirst_geosearch.services.yml (immofirst_geosearch.geocoder_http_client)
 */
final class ImmofirstGeosearchServiceProvider extends ServiceProviderBase {

  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('geocoder.http_adapter')) {
      $container->getDefinition('geocoder.http_adapter')
        ->setArgument(0, new Reference('immofirst_geosearch.geocoder_http_client'));
    }
  }

}
