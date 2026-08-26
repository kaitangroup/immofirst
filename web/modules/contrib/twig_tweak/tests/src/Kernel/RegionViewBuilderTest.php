<?php

declare(strict_types=1);

namespace Drupal\Tests\twig_tweak\Kernel;

use Drupal\block\Entity\Block;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * A test for RegionViewBuilder.
 *
 * @group twig_tweak
 */
final class RegionViewBuilderTest extends AbstractTestCase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'twig_tweak',
    'twig_tweak_test',
    'user',
    'system',
    'block',
    'views',
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('block');
    $this->container->get('theme_installer')->install(['stark']);

    $values = [
      'id' => 'public_block',
      'plugin' => 'system_powered_by_block',
      'theme' => 'stark',
      'region' => 'sidebar_first',
    ];
    Block::create($values)->save();

    $values = [
      'id' => 'private_block',
      'plugin' => 'system_powered_by_block',
      'theme' => 'stark',
      'region' => 'sidebar_first',
    ];
    Block::create($values)->save();
  }

  /**
   * Test callback.
   */
  public function testRegionViewBuilder(): void {
    $view_builder = $this->container->get('twig_tweak.region_view_builder');
    $renderer = $this->container->get('renderer');

    // The build should be empty because 'stark' is not a default theme.
    $expected_build = [
      '#cache' => [
        'contexts' => [],
        'tags' => ['config:block_list'],
        'max-age' => Cache::PERMANENT,
      ],
    ];
    $actual_build = $view_builder->build('sidebar_first');
    self::assertSame($expected_build, $actual_build);

    // Specify the theme name explicitly.
    $expected_build = [
      // Only public_block should be rendered.
      // @see twig_tweak_test_block_access()
      'public_block' => [
        '#cache' =>
          [
            'contexts' => [],
            'tags' => [
              'block_view',
              'config:block.block.public_block',
            ],
            'max-age' => Cache::PERMANENT,
            'keys' => [
              'entity_view',
              'block',
              'public_block',
            ],
          ],
        '#weight' => 0,
        '#lazy_builder' => [
          'Drupal\\block\\BlockViewBuilder::lazyBuilder',
          [
            'public_block',
            'full',
            NULL,
          ],
        ],
      ],
      '#region' => 'sidebar_first',
      '#theme_wrappers' => ['region'],
      // Even if the block is not accessible its cache metadata from access
      // callback should be here.
      '#cache' => [
        'contexts' => ['user'],
        'tags' => [
          'config:block.block.public_block',
          'config:block_list',
          'tag_for_private_block',
          'tag_for_public_block',
        ],
        'max-age' => 123,
      ],
    ];
    $actual_build = $view_builder->build('sidebar_first', 'stark');
    self::assertRenderArray($expected_build, $actual_build);

    $expected_html = <<< 'HTML'
      <div>
        <div id="block-public-block">
          <span>Powered by <a href="https://www.drupal.org">Drupal</a></span>
        </div>
      </div>
      HTML;
    $actual_html = (string) $renderer->renderInIsolation($actual_build);
    self::assertSame(self::normalizeHtml($expected_html), self::normalizeHtml($actual_html));

    // Set 'stark' as default site theme and check if the view builder without
    // 'theme' argument returns the same result.
    $this->container->get('config.factory')
      ->getEditable('system.theme')
      ->set('default', 'stark')
      ->save();

    $actual_build = $view_builder->build('sidebar_first');
    self::assertRenderArray($expected_build, $actual_build);

    Html::resetSeenIds();
    $actual_html = (string) $renderer->renderInIsolation($actual_build);
    self::assertSame(self::normalizeHtml($expected_html), self::normalizeHtml($actual_html));
  }

  /**
   * Normalizes the provided HTML.
   */
  private static function normalizeHtml(string $html): string {
    return \rtrim(\preg_replace(['#\s{2,}#', '#\n#'], '', $html));
  }

}
