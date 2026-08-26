<?php

declare(strict_types=1);

namespace Drupal\Tests\twig_tweak\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * A test for FieldViewBuilder.
 *
 * @group twig_tweak
 */
final class FieldViewBuilderTest extends AbstractTestCase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'twig_tweak',
    'twig_tweak_test',
    'user',
    'system',
    'node',
    'views',
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->setUpCurrentUser(['name' => 'User 1'], ['access content']);
  }

  /**
   * Test callback.
   */
  public function testFieldViewBuilder(): void {
    $view_builder = $this->container->get('twig_tweak.field_view_builder');
    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');

    $values = [
      'type' => 'article',
      'title' => 'Public node',
    ];
    $public_node = $node_storage->create($values);
    $node_storage->save($public_node);

    $values = [
      'type' => 'article',
      'title' => 'Private node',
    ];
    $private_node = $node_storage->create($values);
    $node_storage->save($private_node);

    // -- Full mode.
    $build = $view_builder->build($public_node, 'title');
    self::assertArrayHasKey(0, $build);
    $expected_cache = [
      'contexts' => [
        'user',
        'user.permissions',
      ],
      'tags' => [
        'node:1',
        'tag_from_twig_tweak_test_node_access',
      ],
      'max-age' => 50,
    ];
    self::assertCache($expected_cache, $build['#cache']);
    self::assertSame('<span>Public node</span>', $this->renderInIsolation($build));

    // -- Custom mode.
    $build = $view_builder->build($public_node, 'title', ['settings' => ['link_to_entity' => TRUE]]);
    self::assertArrayHasKey(0, $build);
    $expected_cache = [
      'contexts' => [
        'user',
        'user.permissions',
      ],
      'tags' => [
        'node:1',
        'tag_from_twig_tweak_test_node_access',
      ],
      'max-age' => 50,
    ];
    self::assertCache($expected_cache, $build['#cache']);
    $expected_html = '<span><a href="/node/1" hreflang="en">Public node</a></span>';
    self::assertSame($expected_html, $this->renderInIsolation($build));

    // -- Private node with access check.
    $build = $view_builder->build($private_node, 'title');
    self::assertArrayNotHasKey(0, $build);
    $expected_cache = [
      'contexts' => [
        'user',
        'user.permissions',
      ],
      'tags' => [
        'node:2',
        'tag_from_twig_tweak_test_node_access',
      ],
      'max-age' => 50,
    ];
    self::assertCache($expected_cache, $build['#cache']);
    self::assertSame('', $this->renderInIsolation($build));

    // -- Private node without access check.
    $build = $view_builder->build($private_node, 'title', 'full', NULL, FALSE);
    self::assertArrayHasKey(0, $build);
    $expected_cache = [
      'contexts' => [],
      'tags' => ['node:2'],
      'max-age' => Cache::PERMANENT,
    ];
    self::assertSame($expected_cache, $build['#cache']);
    self::assertSame('<span>Private node</span>', $this->renderInIsolation($build));

    // -- Not existing field.
    self::expectExceptionObject(new \InvalidArgumentException('Field "example" does not exist in "node" entity type.'));
    $view_builder->build($private_node, 'example', 'full', NULL, FALSE);
  }

  /**
   * Renders a render array.
   */
  private function renderInIsolation(array $build): string {
    $renderer = $this->container->get('renderer');
    $actual_html = (string) $renderer->renderInIsolation($build);
    $actual_html = \preg_replace('#<footer>.+</footer>#s', '', $actual_html);
    $actual_html = \preg_replace(['#\s{2,}#', '#\n#'], '', $actual_html);
    return $actual_html;
  }

}
