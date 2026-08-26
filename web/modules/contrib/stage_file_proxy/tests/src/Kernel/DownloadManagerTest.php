<?php

declare(strict_types=1);

namespace Drupal\Tests\stage_file_proxy\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\stage_file_proxy\DownloadManager;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the DownloadManager service.
 */
#[CoversClass(DownloadManager::class)]
#[Group('stage_file_proxy')]
#[RunTestsInSeparateProcesses]
class DownloadManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'file'];

  /**
   * The download manager under test.
   *
   * @var \Drupal\stage_file_proxy\DownloadManager
   */
  protected DownloadManager $downloadManager;

  /**
   * The mock Guzzle handler.
   *
   * @var \GuzzleHttp\Handler\MockHandler
   */
  protected MockHandler $mockHandler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.file')->set('default_scheme', 'public')->save();
  }

  /**
   * Creates a DownloadManager with a mocked HTTP client.
   *
   * @param array $responses
   *   An array of GuzzleHttp\Psr7\Response objects.
   *
   * @return \Drupal\stage_file_proxy\DownloadManager
   *   The download manager.
   *
   * @throws \Exception
   */
  protected function createDownloadManager(array $responses): DownloadManager {
    $this->mockHandler = new MockHandler($responses);
    $handlerStack = HandlerStack::create($this->mockHandler);
    $client = new Client(['handler' => $handlerStack]);

    $requestStack = new RequestStack();
    $requestStack->push(Request::create('/'));

    return new DownloadManager(
      $client,
      $this->container->get('file_system'),
      \Drupal::logger('stage_file_proxy'),
      $this->container->get('config.factory'),
      \Drupal::lock(),
      $requestStack,
    );
  }

  /**
   * Tests styleOriginalPath with a standard image style path.
   *
   * @throws \Exception
   */
  public function testStyleOriginalPath(): void {
    $manager = $this->createDownloadManager([]);
    $this->assertEquals(
      'public://example.jpg',
      $manager->styleOriginalPath('styles/icon_50x50_/public/example.jpg')
    );
  }

  /**
   * Tests styleOriginalPath with a nested directory path.
   *
   * @throws \Exception
   */
  public function testStyleOriginalPathNested(): void {
    $manager = $this->createDownloadManager([]);
    $this->assertEquals(
      'public://images/photo.png',
      $manager->styleOriginalPath('styles/thumbnail/public/images/photo.png')
    );
  }

  /**
   * Tests styleOriginalPath returns FALSE for non-style paths.
   *
   * @throws \Exception
   */
  public function testStyleOriginalPathNonStyle(): void {
    $manager = $this->createDownloadManager([]);
    $this->assertFalse($manager->styleOriginalPath('images/photo.png'));
  }

  /**
   * Tests styleOriginalPath with style_only FALSE returns URI for non-styles.
   *
   * @throws \Exception
   */
  public function testStyleOriginalPathNonStyleWithFallback(): void {
    $manager = $this->createDownloadManager([]);
    $this->assertEquals(
      'public://images/photo.png',
      $manager->styleOriginalPath('images/photo.png', FALSE)
    );
  }

  /**
   * Tests styleOriginalPath with a stream wrapper URI.
   *
   * @throws \Exception
   */
  public function testStyleOriginalPathWithScheme(): void {
    $manager = $this->createDownloadManager([]);
    $this->assertEquals(
      'public://example.jpg',
      $manager->styleOriginalPath('public://styles/large/public/example.jpg')
    );
  }

  /**
   * Tests fetch with non-200 response returns FALSE.
   *
   * @throws \Exception
   */
  public function testFetchNon200Response(): void {
    $manager = $this->createDownloadManager([
      new Response(403, [], 'Forbidden'),
    ]);

    $result = $manager->fetch(
      'https://example.com',
      'sites/default/files',
      'forbidden-file.txt',
      []
    );

    $this->assertFalse($result);
  }

  /**
   * Tests fetch with content length mismatch returns FALSE.
   *
   * @throws \Exception
   */
  public function testFetchContentLengthMismatch(): void {
    $manager = $this->createDownloadManager([
      new Response(200, ['Content-Length' => 999], 'short'),
    ]);

    $result = $manager->fetch(
      'https://example.com',
      'sites/default/files',
      'mismatch-file.txt',
      []
    );

    $this->assertFalse($result);
  }

}
