<?php

namespace Drupal\Tests\geocoder\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\geocoder\Entity\GeocoderProvider;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that the Geocode field formatter renders a geocoded field value.
 *
 * This exercises GeocodeFormatterBase::viewElements(): it reads the field item
 * value, geocodes it and dumps the result. It provides the runtime coverage
 * behind reading the field item "value" property in the geocode formatters.
 *
 * @group geocoder
 */
class GeocodeFormatterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'geocoder',
    'geocoder_field',
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
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();
  }

  /**
   * Tests the formatter geocodes the field value and renders the dumped result.
   */
  public function testGeocodeFormatterRendersValue(): void {
    $entity = EntityTest::create([
      'field_address' => '10 Avenue Gambetta, Paris',
    ]);
    $entity->save();

    $items = $entity->get('field_address');
    $formatter = \Drupal::service('plugin.manager.field.formatter')
      ->createInstance('geocoder_geocode_formatter', [
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

    // The mock provider returns a point in Paris; the WKT dumper renders it.
    $this->assertStringContainsString('POINT', $output);
    $this->assertStringContainsString('2.388', $output);
    $this->assertStringContainsString('48.863', $output);
  }

}
