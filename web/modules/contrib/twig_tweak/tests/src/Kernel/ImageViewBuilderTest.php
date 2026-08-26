<?php

declare(strict_types=1);

namespace Drupal\Tests\twig_tweak\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\file\FileInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A test class for testing the image view builder.
 *
 * @group twig_tweak
 */
final class ImageViewBuilderTest extends AbstractTestCase {

  private const string SOURCE_IMAGE = 'core/tests/fixtures/files/image-test.jpg';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'twig_tweak',
    'twig_tweak_test',
    'user',
    'system',
    'file',
    'image',
    'responsive_image',
    'breakpoint',
    'views',
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');

    $file_system = $this->container->get('file_system');
    $file_system->prepareDirectory($this->siteDirectory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $private_directory = $this->siteDirectory . '/private';
    $file_system->prepareDirectory($private_directory, FileSystemInterface::CREATE_DIRECTORY);
    $this->setSetting('file_private_path', $private_directory);

    $entity_type_manager = $this->container->get('entity_type.manager');
    $image_style_storage = $entity_type_manager->getStorage('image_style');
    $image_style = $image_style_storage->create([
      'name' => 'small',
      'label' => 'Small',
    ]);
    // Add a crop effect:
    $image_style->addImageEffect([
      'id' => 'image_resize',
      'data' => [
        'width' => 10,
        'height' => 10,
      ],
      'weight' => 0,
    ]);
    $image_style_storage->save($image_style);

    $responsive_image_style_storage = $entity_type_manager->getStorage('responsive_image_style');
    $responsive_image_style = $responsive_image_style_storage->create([
      'id' => 'wide',
      'label' => 'Wide',
      'breakpoint_group' => 'twig_tweak_image_view_builder',
      'fallback_image_style' => 'small',
    ]);
    $responsive_image_style_storage->save($responsive_image_style);
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.private', PrivateStream::class)
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@selfdoc}
   */
  #[DataProvider('dataProvider')]
  public function testImageViewBuilder(string $uri, ?string $style, array $attributes, bool $responsive, bool $check_access, array $expected_build, string $expected_output): void {
    $view_builder = $this->container->get('twig_tweak.image_view_builder');
    $build = $view_builder->build($this->createFile($uri), $style, $attributes, $responsive, $check_access);
    self::assertRenderArray($expected_build, $build);
    self::assertSame($expected_output, $this->renderInIsolation($build));
  }

  /**
   * {@selfdoc}
   */
  public static function dataProvider(): \Generator {
    $build_data = static fn (
      string $uri,
      ?string $style,
      array $attributes,
      bool $responsive,
      bool $check_access,
      array $expected_build,
      string $expected_output,
    ): array => \func_get_args();

    yield 'Without style' => $build_data(
      uri: 'public://image-test.jpg',
      style: NULL,
      attributes: [],
      responsive: FALSE,
      check_access: TRUE,
      expected_build: [
        '#uri' => 'public://image-test.jpg',
        '#attributes' => [],
        '#theme' => 'image',
        '#cache' => [
          'contexts' => [
            'user',
            'user.permissions',
          ],
          'tags' => [
            'file:1',
            'tag_for_public://image-test.jpg',
          ],
          'max-age' => 70,
        ],
      ],
      expected_output: '<img src="/files/image-test.jpg" alt="" />',
    );

    yield 'With style' => $build_data(
      uri: 'public://image-test.jpg',
      style: 'small',
      attributes: ['alt' => 'Image with style'],
      responsive: FALSE,
      check_access: TRUE,
      expected_build: [
        '#uri' => 'public://image-test.jpg',
        '#attributes' => ['alt' => 'Image with style'],
        '#width' => 40,
        '#height' => 20,
        '#theme' => 'image_style',
        '#style_name' => 'small',
        '#cache' => [
          'contexts' => [
            'user',
            'user.permissions',
          ],
          'tags' => [
            'file:1',
            'tag_for_public://image-test.jpg',
          ],
          'max-age' => 70,
        ],
      ],
      expected_output: '<img alt="Image with style" src="/files/styles/small/public/image-test.jpg?itok=abc" width="10" height="10" loading="lazy" />',
    );

    yield 'With responsive style' => $build_data(
      uri: 'public://image-test.jpg',
      style: 'wide',
      attributes: ['alt' => 'Image with responsive style'],
      responsive: TRUE,
      check_access: TRUE,
      expected_build: [
        '#uri' => 'public://image-test.jpg',
        '#attributes' => ['alt' => 'Image with responsive style'],
        '#width' => 40,
        '#height' => 20,
        '#type' => 'responsive_image',
        '#responsive_image_style_id' => 'wide',
        '#cache' => [
          'contexts' => [
            'user',
            'user.permissions',
          ],
          'tags' => [
            'file:1',
            'tag_for_public://image-test.jpg',
          ],
          'max-age' => 70,
        ],
      ],
      expected_output: '<picture><img width="10" height="10" src="/files/styles/small/public/image-test.jpg?itok=abc" alt="Image with responsive style" loading="lazy" /></picture>',
    );

    yield 'Private image with access check' => $build_data(
      uri: 'private://image-test.jpg',
      style: NULL,
      attributes: [],
      responsive: FALSE,
      check_access: TRUE,
      expected_build: [
        '#cache' => [
          'contexts' => ['user'],
          'tags' => [
            'file:1',
            'tag_for_private://image-test.jpg',
          ],
          'max-age' => 70,
        ],
      ],
      expected_output: '',
    );

    yield 'Private image without access check' => $build_data(
      uri: 'private://image-test.jpg',
      style: NULL,
      attributes: [],
      responsive: FALSE,
      check_access: FALSE,
      expected_build: [
        '#uri' => 'private://image-test.jpg',
        '#attributes' => [],
        '#theme' => 'image',
        '#cache' => [
          'contexts' => [],
          'tags' => ['file:1'],
          'max-age' => Cache::PERMANENT,
        ],
      ],
      expected_output: '<img src="/files/image-test.jpg" alt="" />',
    );
  }

  /**
   * Renders a render array.
   */
  private function renderInIsolation(array $build): string {
    $renderer = $this->container->get('renderer');
    $html = (string) $renderer->renderInIsolation($build);
    $html = \preg_replace('#src=".+/files/#s', 'src="/files/', $html);
    $html = \preg_replace('#\?itok=.+?"#', '?itok=abc"', $html);
    $html = \preg_replace(['#\s{2,}#', '#\n#'], '', $html);
    return \rtrim($html);
  }

  /**
   * {@selfdoc}
   */
  private function createFile(string $uri): FileInterface {
    $file_system = $this->container->get('file_system');

    // Create a copy of a test image file. Original size is 40×20 px.
    $file_system->copy(self::SOURCE_IMAGE, $uri, FileExists::Replace);
    self::assertFileExists($uri);

    $file_storage = $this->container->get('entity_type.manager')->getStorage('file');
    $file = $file_storage->create([
      'uri' => $uri,
      'status' => FileInterface::STATUS_PERMANENT,
    ]);
    $file_storage->save($file);
    return $file;
  }

}
