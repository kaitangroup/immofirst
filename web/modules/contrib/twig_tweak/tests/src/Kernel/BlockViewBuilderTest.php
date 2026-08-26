<?php

declare(strict_types=1);

namespace Drupal\Tests\twig_tweak\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\twig_tweak_test\Plugin\Block\FooBlock;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A test for BlockViewBuilder.
 *
 * @group twig_tweak
 */
final class BlockViewBuilderTest extends KernelTestBase {

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
   * {@selfdoc}
   *
   * @see \Drupal\twig_tweak_test\Plugin\Block\FooBlock
   */
  #[DataProvider('dataProvider')]
  public function test(?string $account_name, array $configuration, bool $wrapper, array $expected_build, string $expected_output): void {
    $view_builder = $this->container->get('twig_tweak.block_view_builder');

    if ($account_name) {
      $this->setUpCurrentUser(['name' => $account_name]);
    }
    $actual_build = $view_builder->build(FooBlock::ID, $configuration, $wrapper);
    self::assertSame($expected_build, $actual_build);
    self::assertSame($expected_output, $this->renderInIsolation($actual_build));
  }

  /**
   * {@selfdoc}
   */
  public static function dataProvider(): \Generator {
    yield 'Default configuration' => [
      'User 1',
      [],
      TRUE,
      [
        'content' => [
          '#markup' => 'Foo',
          '#cache' => [
            'contexts' => ['url'],
            'tags' => ['tag_from_build'],
          ],
        ],
        '#theme' => 'block',
        '#id' => NULL,
        '#attributes' => [
          'id' => 'foo',
        ],
        '#contextual_links' => [],
        '#configuration' => [
          'id' => 'twig_tweak_test_foo',
          'label' => '',
          'label_display' => 'visible',
          'provider' => 'twig_tweak_test',
          'content' => 'Foo',
        ],
        '#plugin_id' => 'twig_tweak_test_foo',
        '#base_plugin_id' => 'twig_tweak_test_foo',
        '#derivative_plugin_id' => NULL,
        '#cache' => [
          'contexts' => ['user'],
          'tags' => ['tag_from_blockAccess'],
          'max-age' => 35,
          'keys' => [
            'twig_tweak_block',
            'twig_tweak_test_foo',
            '[configuration]=04c46ea912d2866a3a36c67326da1ef38f1c93cc822d6c45e1639f3decdebbdc',
            '[wrapper]=1',
          ],
        ],
      ],
      '<div id="foo">Foo</div>',
    ];

    yield 'Non-default configuration' => [
      'User 1',
      [
        'content' => 'Bar',
        'label' => 'Example',
        'id' => 'example',
      ],
      TRUE,
      [
        'content' => [
          '#markup' => 'Bar',
          '#cache' => [
            'contexts' => ['url'],
            'tags' => ['tag_from_build'],
          ],
        ],
        '#theme' => 'block',
        '#id' => 'example',
        '#attributes' => [
          'id' => 'foo',
        ],
        '#contextual_links' => [],
        '#configuration' => [
          'id' => 'example',
          'label' => 'Example',
          'label_display' => 'visible',
          'provider' => 'twig_tweak_test',
          'content' => 'Bar',
        ],
        '#plugin_id' => 'twig_tweak_test_foo',
        '#base_plugin_id' => 'twig_tweak_test_foo',
        '#derivative_plugin_id' => NULL,
        '#cache' => [
          'contexts' => ['user'],
          'tags' => ['tag_from_blockAccess'],
          'max-age' => 35,
          'keys' => [
            'twig_tweak_block',
            'twig_tweak_test_foo',
            '[configuration]=8e53716fcf7e5d5c45effd55e9b2a267bbaf333f7253766f572d58e4f7991b36',
            '[wrapper]=1',
          ],
        ],
      ],
      '<div id="block-example"><h2>Example</h2>Bar</div>',
    ];

    yield 'Without wrapper' => [
      'User 1',
      [],
      FALSE,
      [
        'content' => [
          '#markup' => 'Foo',
          // Since the block is built without wrapper #attributes must remain in
          // 'content' element.
          '#attributes' => [
            'id' => 'foo',
          ],
          '#cache' => [
            'contexts' => ['url'],
            'tags' => ['tag_from_build'],
          ],
        ],
        '#cache' => [
          'contexts' => ['user'],
          'tags' => ['tag_from_blockAccess'],
          'max-age' => 35,
          'keys' => [
            'twig_tweak_block',
            'twig_tweak_test_foo',
            '[configuration]=04c46ea912d2866a3a36c67326da1ef38f1c93cc822d6c45e1639f3decdebbdc',
            '[wrapper]=0',
          ],
        ],
      ],
      'Foo',
    ];

    yield 'Unprivileged user' => [
      'User 2',
      [],
      TRUE,
      [
        '#cache' => [
          'contexts' => ['user'],
          'tags' => ['tag_from_blockAccess'],
          'max-age' => 35,
          'keys' => [
            'twig_tweak_block',
            'twig_tweak_test_foo',
            '[configuration]=04c46ea912d2866a3a36c67326da1ef38f1c93cc822d6c45e1639f3decdebbdc',
            '[wrapper]=1',
          ],
        ],
      ],
      '',
    ];
  }

  /**
   * Renders a render array.
   */
  private function renderInIsolation(array $build): string {
    /** @var \Drupal\Core\Render\RendererInterface $renderer */
    $renderer = $this->container->get('renderer');
    return \rtrim(\preg_replace('#\s{2,}#', '', (string) $renderer->renderInIsolation($build)));
  }

}
