<?php

declare(strict_types=1);

namespace Drupal\Tests\twig_tweak\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * A test for EntityViewBuilder.
 *
 * @group twig_tweak
 */
final class EntityViewBuilderTest extends AbstractTestCase {

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
    'field',
    'text',
    'views',
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'node']);
    NodeType::create(['type' => 'article'])->save();
    $this->setUpCurrentUser([], ['access content']);
  }

  /**
   * Test callback.
   *
   * @see \twig_tweak_test_node_access()
   */
  public function testEntityViewBuilder(): void {
    $view_builder = $this->container->get('twig_tweak.entity_view_builder');
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
    $build = $view_builder->build($public_node);
    self::assertArrayHasKey('#node', $build);
    $expected_cache = [
      'tags' => [
        'node:1',
        'node_view',
        'tag_from_twig_tweak_test_node_access',
      ],
      'contexts' => [
        'user',
        'user.permissions',
      ],
      'max-age' => 50,
      'keys' => [
        'entity_view',
        'node',
        '1',
        'full',
      ],
      'bin' => 'render',
    ];
    self::assertCache($expected_cache, $build['#cache']);

    $expected_html = <<< 'HTML'
      <article>
        <div></div>
      </article>
      HTML;
    $actual_html = $this->renderPlain($build);
    self::assertMarkup($expected_html, $actual_html);

    // -- Teaser mode.
    $build = $view_builder->build($public_node, 'teaser');
    self::assertArrayHasKey('#node', $build);
    $expected_cache = [
      'tags' => [
        'node:1',
        'node_view',
        'tag_from_twig_tweak_test_node_access',
      ],
      'contexts' => [
        'user',
        'user.permissions',
      ],
      'max-age' => 50,
      'keys' => [
        'entity_view',
        'node',
        '1',
        'teaser',
      ],
      'bin' => 'render',
    ];
    self::assertCache($expected_cache, $build['#cache']);

    $expected_html = <<< 'HTML'
      <article>
        <h2><a href="/node/1" rel="bookmark"><span>Public node</span></a></h2>
        <div>
          <ul class="links inline">
            <li>
              <a href="/node/1" rel="tag" title="Public node" hreflang="en">
                Read more<span class="visually-hidden"> about Public node</span>
              </a>
            </li>
          </ul>
        </div>
      </article>
      HTML;
    $actual_html = $this->renderPlain($build);
    self::assertMarkup($expected_html, $actual_html);

    // -- Private node with access check.
    $build = $view_builder->build($private_node);
    self::assertArrayNotHasKey('#node', $build);
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

    self::assertSame('', $this->renderPlain($build));

    // -- Private node without access check.
    $build = $view_builder->build($private_node, 'full', NULL, FALSE);
    self::assertArrayHasKey('#node', $build);
    $expected_cache = [
      'tags' => [
        'node:2',
        'node_view',
      ],
      'contexts' => [],
      'max-age' => Cache::PERMANENT,
      'keys' => [
        'entity_view',
        'node',
        '2',
        'full',
      ],
      'bin' => 'render',
    ];
    self::assertCache($expected_cache, $build['#cache']);

    $expected_html = <<< 'HTML'
      <article>
        <div></div>
      </article>
      HTML;
    $actual_html = $this->renderPlain($build);
    self::assertMarkup($expected_html, $actual_html);
  }

  /**
   * Renders a render array.
   */
  private function renderPlain(array $build): string {
    /** @var \Drupal\Core\Render\RendererInterface $renderer */
    $renderer = $this->container->get('renderer');
    return \preg_replace(
      pattern: '#<footer>.+</footer>#s',
      replacement: '',
      subject: (string) $renderer->renderInIsolation($build),
    );
  }

  /**
   * {@selfdoc}
   */
  private static function assertMarkup(string $expected_markup, string $actual_markup): void {
    $normalize_html = static fn (string $html): string =>
      \rtrim(\preg_replace(['#\s{2,}#', '#\n#'], '', $html));
    self::assertSame($normalize_html($expected_markup), $normalize_html($actual_markup));
  }

}
