<?php

namespace Drupal\Tests\geocoder\Kernel;

use Drupal\geocoder\Entity\GeocoderProvider;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the geocoder provider list builder.
 *
 * Covers GeocoderProviderListBuilder, which is injected with the entity type
 * manager and derives its storage from it.
 *
 * @group geocoder
 */
class GeocoderProviderListBuilderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'geocoder',
    'geocoder_test_provider',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['geocoder']);
  }

  /**
   * Tests that the list builder is instantiated and renders the providers.
   */
  public function testListBuilderRendersProviders(): void {
    GeocoderProvider::create([
      'id' => 'test_provider',
      'plugin' => 'geocoder_test_provider',
    ])->save();

    $list_builder = $this->container->get('entity_type.manager')
      ->getListBuilder('geocoder_provider');
    $build = $list_builder->render();
    $output = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('test_provider', $output);
  }

}
