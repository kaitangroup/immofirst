<?php

declare(strict_types=1);

namespace Drupal\Tests\twig_tweak\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeInterface;
use Drupal\Tests\TestFileCreationTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A test for URL Extractor service.
 *
 * @group twig_tweak
 */
final class UrlExtractorTest extends KernelTestBase {

  use TestFileCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'twig_tweak',
    'twig_tweak_test',
    'system',
    'views',
    'node',
    'block',
    'image',
    'field',
    'text',
    'media',
    'file',
    'user',
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('media');
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installConfig(['node', 'twig_tweak_test']);
  }

  /**
   * Test callback.
   */
  public function testUrlExtractor(): void {
    $node = $this->createNode();

    $extractor = $this->container->get('twig_tweak.url_extractor');
    $base_url = $this->container->get(FileUrlGeneratorInterface::class)->generateAbsoluteString('');

    $request = $this->container->get(RequestStack::class)->getCurrentRequest();
    $absolute_url = "{$request->getScheme()}://{$request->getHost()}/foo/bar.txt";

    $url = $extractor->extractUrl($absolute_url);
    self::assertSame('/foo/bar.txt', $url);

    $url = $extractor->extractUrl($absolute_url, FALSE);
    self::assertSame($base_url . 'foo/bar.txt', $url);

    $url = $extractor->extractUrl('foo/bar.jpg');
    self::assertSame('/foo/bar.jpg', $url);

    $url = $extractor->extractUrl('foo/bar.jpg', FALSE);
    self::assertSame($base_url . 'foo/bar.jpg', $url);

    $url = $extractor->extractUrl('');
    self::assertSame('/', $url);

    $url = $extractor->extractUrl('', FALSE);
    self::assertSame($base_url, $url);

    $url = $extractor->extractUrl($node);
    self::assertNull($url);

    $url = $extractor->extractUrl($node->get('title'));
    self::assertNull($url);

    $url = $extractor->extractUrl($node->get('field_image')[0]);
    self::assertStringEndsWith('/files/image-test.png', $url);
    self::assertStringNotContainsString($base_url, $url);

    $url = $extractor->extractUrl($node->get('field_image')[0], FALSE);
    self::assertStringStartsWith($base_url, $url);
    self::assertStringEndsWith('/files/image-test.png', $url);

    $url = $extractor->extractUrl($node->get('field_image')[1]);
    self::assertNull($url);

    $url = $extractor->extractUrl($node->get('field_image'));
    self::assertStringEndsWith('/files/image-test.png', $url);

    $url = $extractor->extractUrl($node->get('field_image')->entity);
    self::assertStringEndsWith('/files/image-test.png', $url);

    $node->get('field_image')->removeItem(0);
    $url = $extractor->extractUrl($node->get('field_image'));
    self::assertNull($url);

    $url = $extractor->extractUrl($node->get('field_media')[0]);
    self::assertStringEndsWith('/files/image-test.gif', $url);

    $url = $extractor->extractUrl($node->get('field_media')[1]);
    self::assertNull($url);

    $url = $extractor->extractUrl($node->get('field_media'));
    self::assertStringEndsWith('/files/image-test.gif', $url);

    $url = $extractor->extractUrl($node->get('field_media')->entity);
    self::assertStringEndsWith('/files/image-test.gif', $url);

    $node->get('field_media')->removeItem(0);
    $url = $extractor->extractUrl($node->get('field_media'));
    self::assertNull($url);
  }

  /**
   * {@selfdoc}
   */
  private function createNode(): NodeInterface {
    $test_files = $this->getTestFiles('image');

    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);

    $file_storage = $entity_type_manager->getStorage('file');
    $image_file = $file_storage->create([
      'uri' => $test_files[0]->uri,
      'uuid' => 'a2cb2b6f-7bf8-4da4-9de5-316e93487518',
      'status' => FileInterface::STATUS_PERMANENT,
    ]);
    $file_storage->save($image_file);

    $media_file = $file_storage->create([
      'uri' => $test_files[2]->uri,
      'uuid' => '5dd794d0-cb75-4130-9296-838aebc1fe74',
      'status' => FileInterface::STATUS_PERMANENT,
    ]);
    $file_storage->save($media_file);

    $media_storage = $entity_type_manager->getStorage('media');
    $media = $media_storage->create([
      'bundle' => 'image',
      'name' => 'Image 1',
      'field_media_image' => ['target_id' => $media_file->id()],
    ]);
    $media_storage->save($media);

    $node_storage = $entity_type_manager->getStorage('node');
    $node_values = [
      'title' => 'Alpha',
      'type' => 'page',
      'field_image' => ['target_id' => $image_file->id()],
      'field_media' => ['target_id' => $media->id()],
    ];
    return $node_storage->create($node_values);
  }

}
