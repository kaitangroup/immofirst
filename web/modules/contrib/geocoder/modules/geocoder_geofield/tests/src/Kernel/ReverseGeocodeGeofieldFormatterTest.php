<?php

namespace Drupal\Tests\geocoder_geofield\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\geocoder\Entity\GeocoderProvider;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the reverse geocode geofield formatter.
 *
 * Covers ReverseGeocodeGeofieldFormatter::viewElements(), which reads the
 * geofield item value (WKT), reverse geocodes its centroid and dumps the
 * result.
 *
 * @group geocoder
 */
class ReverseGeocodeGeofieldFormatterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'geofield',
    'geocoder',
    'geocoder_field',
    'geocoder_geofield',
    'geocoder_test_provider',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['geocoder']);

    GeocoderProvider::create([
      'id' => 'test_provider',
      'plugin' => 'geocoder_test_provider',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_geo',
      'entity_type' => 'entity_test',
      'type' => 'geofield',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_geo',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();
  }

  /**
   * Tests Reverse Geocode Formatter.
   */
  public function testReverseGeocodeFormatterRendersValue(): void {
    $entity = EntityTest::create([
      'field_geo' => 'POINT (2.3888 48.8631)',
    ]);
    $entity->save();

    $items = $entity->get('field_geo');
    $formatter = \Drupal::service('plugin.manager.field.formatter')
      ->createInstance('geocoder_geofield_reverse_geocode', [
        'field_definition' => $items->getFieldDefinition(),
        'view_mode' => 'default',
        'label' => 'hidden',
        'third_party_settings' => [],
        'settings' => [
          'providers' => [
            'test_provider' => ['checked' => TRUE, 'weight' => 0],
          ],
          'dumper' => 'wkt',
        ],
      ]);
    $elements = $formatter->viewElements($items, 'en');
    $output = isset($elements[0]['#markup']) ? (string) $elements[0]['#markup'] : '';

    // The mock provider reverse geocodes to a point in Paris.
    $this->assertStringContainsString('POINT', $output);
  }

}
