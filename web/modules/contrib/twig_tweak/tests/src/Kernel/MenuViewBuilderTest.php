<?php

declare(strict_types=1);

namespace Drupal\Tests\twig_tweak\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A test for MenuViewBuilder.
 *
 * @group twig_tweak
 */
final class MenuViewBuilderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'twig_tweak',
    'user',
    'system',
    'link',
    'menu_link_content',
    'views',
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('menu_link_content');

    $entity_type_manager = $this->container->get('entity_type.manager');

    $entity_type_manager
      ->getStorage('menu')
      ->create([
        'id' => 'test-menu',
        'label' => 'Test menu',
        'description' => 'Description text.',
      ])
      ->save();

    $link_content_storage = $entity_type_manager->getStorage('menu_link_content');

    $link_1 = $link_content_storage->create([
      'expanded' => TRUE,
      'title' => 'Link 1',
      'link' => ['uri' => 'internal:/foo/1'],
      'menu_name' => 'test-menu',
    ]);
    $link_content_storage->save($link_1);

    $link_1_1 = $link_content_storage->create([
      'title' => 'Link 1.1',
      'link' => ['uri' => 'internal:/foo/1/1'],
      'menu_name' => 'test-menu',
      'parent' => $link_1->getPluginId(),
    ]);
    $link_content_storage->save($link_1_1);

    $link_1_2 = $link_content_storage->create([
      'title' => 'Link 2',
      'link' => ['uri' => 'internal:/foo/2'],
      'menu_name' => 'test-menu',
    ]);
    $link_content_storage->save($link_1_2);
  }

  /**
   * {@selfdoc}
   *
   * @todo Figure out how to test 'expanded' option.
   */
  #[DataProvider('dataProvider')]
  public function testMenuViewBuilder(?int $level, ?int $depth, string $expected_output): void {
    $view_builder = $this->container->get('twig_tweak.menu_view_builder');
    $build = $level === NULL && $depth === NULL ?
      $view_builder->build('test-menu') : $view_builder->build('test-menu', $level, $depth);
    $this->assertMarkup($expected_output, $build);
  }

  /**
   * {@selfdoc}
   */
  public static function dataProvider(): \Generator {
    $build_data = static fn (?int $level, ?int $depth, string $expected_output): array
      => \func_get_args();

    yield 'Default arguments' => $build_data(
      level: NULL,
      depth: NULL,
      expected_output: <<< 'HTML'
        <ul>
          <li>
            <a href="/foo/1">Link 1</a>
            <ul>
              <li>
                <a href="/foo/1/1">Link 1.1</a>
              </li>
             </ul>
          </li>
          <li>
            <a href="/foo/2">Link 2</a>
          </li>
        </ul>
        HTML,
    );

    yield 'Level = 1; Depth = 0;' => $build_data(
      level: 1,
      depth: 0,
      expected_output: <<< 'HTML'
        <ul>
          <li>
            <a href="/foo/1">Link 1</a>
            <ul>
              <li>
                <a href="/foo/1/1">Link 1.1</a>
              </li>
             </ul>
          </li>
          <li>
            <a href="/foo/2">Link 2</a>
          </li>
        </ul>
        HTML,
    );

    yield 'Level = 2; Depth = 0;' => $build_data(
      level: 2,
      depth: 0,
      expected_output: <<< 'HTML'
        <ul>
          <li>
            <a href="/foo/1/1">Link 1.1</a>
          </li>
         </ul>
        HTML,
    );

    yield 'Level = 1; Depth = 1;' => $build_data(
      level: 1,
      depth: 1,
      expected_output: <<< 'HTML'
        <ul>
          <li>
            <a href="/foo/1">Link 1</a>
          </li>
          <li>
            <a href="/foo/2">Link 2</a>
          </li>
        </ul>
        HTML,
    );
  }

  /**
   * Asserts menu markup.
   */
  private function assertMarkup(string $expected_markup, array $build): void {
    $expected_markup = \trim(\preg_replace('#>\s+<#', '><', $expected_markup));
    $renderer = $this->container->get('renderer');
    $actual_markup = \trim(\preg_replace('#>\s+<#', '><', (string) $renderer->renderInIsolation($build)));
    self::assertSame($expected_markup, $actual_markup);
  }

}
