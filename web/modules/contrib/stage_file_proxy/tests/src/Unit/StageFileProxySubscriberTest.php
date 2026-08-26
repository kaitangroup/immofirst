<?php

declare(strict_types=1);

namespace Drupal\Tests\stage_file_proxy\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\Utility\UnroutedUrlAssemblerInterface;
use Drupal\stage_file_proxy\DownloadManagerInterface;
use Drupal\stage_file_proxy\EventSubscriber\StageFileProxySubscriber;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Unit tests for StageFileProxySubscriber.
 */
#[Group('stage_file_proxy')]
class StageFileProxySubscriberTest extends TestCase {

  /**
   * The mocked download manager.
   */
  protected DownloadManagerInterface $manager;

  /**
   * The mocked logger.
   */
  protected LoggerInterface $logger;

  /**
   * The mocked event dispatcher.
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * The mocked config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * The mocked page cache kill switch.
   */
  protected KillSwitch $pageCacheKillSwitch;

  /**
   * The mocked image factory.
   */
  protected ImageFactory $imageFactory;

  /**
   * The mocked file URL generator.
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * The mocked stream wrapper manager.
   */
  protected StreamWrapperManager $streamWrapperManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->manager = $this->createStub(DownloadManagerInterface::class);
    $this->manager->method('filePublicPath')->willReturn('sites/default/files');
    $this->manager->method('styleOriginalPath')->willReturn(FALSE);

    $this->logger = $this->createStub(LoggerInterface::class);
    $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
    $this->requestStack = new RequestStack();
    $this->pageCacheKillSwitch = $this->createStub(KillSwitch::class);
    $this->imageFactory = $this->createStub(ImageFactory::class);
    $this->imageFactory->method('getSupportedExtensions')->willReturn(['jpg', 'jpeg', 'png', 'gif', 'webp']);
    $this->fileUrlGenerator = $this->createStub(FileUrlGeneratorInterface::class);
    // Use a real-ish object so $instance::getTarget() resolves to the
    // static method on StreamWrapperManager.
    $this->streamWrapperManager = new class extends StreamWrapperManager {

      public function __construct() {}

    };

    // Set up a minimal container for Url::fromUri() calls.
    $url_assembler = $this->createStub(UnroutedUrlAssemblerInterface::class);
    $url_assembler->method('assemble')->willReturnCallback(function ($uri) {
      return $uri;
    });

    $url_generator = $this->createStub(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')->willReturnCallback(function ($route, $parameters, $options) {
      $generated = new GeneratedUrl();
      $generated->setGeneratedUrl($options['absolute'] ?? FALSE ? 'http://localhost/' : '/');
      return $generated;
    });

    $container = new ContainerBuilder();
    $container->set('unrouted_url_assembler', $url_assembler);
    $container->set('url_generator', $url_generator);
    \Drupal::setContainer($container);
  }

  /**
   * Creates a subscriber with the given config values.
   *
   * @param array $config_values
   *   Config values for stage_file_proxy.settings.
   *
   * @return \Drupal\stage_file_proxy\EventSubscriber\StageFileProxySubscriber
   *   The subscriber.
   */
  protected function createSubscriber(array $config_values): StageFileProxySubscriber {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(function ($key) use ($config_values) {
      return $config_values[$key] ?? NULL;
    });

    $this->configFactory = $this->createStub(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('stage_file_proxy.settings')
      ->willReturn($config);

    return new StageFileProxySubscriber(
      $this->manager,
      $this->logger,
      $this->eventDispatcher,
      $this->configFactory,
      $this->requestStack,
      $this->pageCacheKillSwitch,
      $this->imageFactory,
      $this->fileUrlGenerator,
      $this->streamWrapperManager,
    );
  }

  /**
   * Creates a RequestEvent for the given path.
   *
   * @param string $path
   *   The request path.
   * @param string $host
   *   The request host.
   *
   * @return \Symfony\Component\HttpKernel\Event\RequestEvent
   *   The request event.
   */
  protected function createRequestEvent(string $path, string $host = 'localhost'): RequestEvent {
    $request = Request::create("https://$host/$path");
    $this->requestStack->push($request);

    $kernel = $this->createStub(HttpKernelInterface::class);
    return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Pop any remaining requests to avoid state leaking.
    while ($this->requestStack->getCurrentRequest()) {
      $this->requestStack->pop();
    }
    parent::tearDown();
  }

  /**
   * Tests hotlink mode behavior.
   *
   * Verifies hotlink redirects for normal files and that hotlink is skipped
   * for image styles when use_imagecache_root changes the fetch path.
   */
  public function testHotlinkMode(): void {
    // Normal file: the hotlink should redirect to the origin.
    $subscriber = $this->createSubscriber([
      'origin' => 'https://prod.example.com',
      'origin_dir' => '',
      'hotlink' => TRUE,
      'use_imagecache_root' => TRUE,
      'excluded_extensions' => '',
      'verify' => TRUE,
      'proxy_headers' => '',
    ]);

    $event = $this->createRequestEvent('sites/default/files/images/photo.jpg');
    $subscriber->checkFileOrigin($event);

    $response = $event->getResponse();
    $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
    $this->assertStringContainsString('prod.example.com', $response->getTargetUrl());
    $this->assertStringContainsString('images/photo.jpg', $response->getTargetUrl());

    // Image style with use_imagecache_root: hotlink should be skipped since
    // fetch_path gets changed to the original, so fetch_path !== relative_path.
    $this->requestStack->pop();

    $manager = $this->createMock(DownloadManagerInterface::class);
    $manager->method('filePublicPath')->willReturn('sites/default/files');
    $manager->method('styleOriginalPath')
      ->willReturn('/nonexistent/photo.jpg');
    $manager->expects($this->once())
      ->method('fetch')
      ->willReturn(TRUE);

    $subscriber = new StageFileProxySubscriber(
      $manager,
      $this->logger,
      $this->eventDispatcher,
      $this->configFactory,
      $this->requestStack,
      $this->pageCacheKillSwitch,
      $this->imageFactory,
      $this->fileUrlGenerator,
      $this->streamWrapperManager,
    );

    $event = $this->createRequestEvent('sites/default/files/styles/large/public/photo.jpg');
    $subscriber->checkFileOrigin($event);

    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertNotInstanceOf(TrustedRedirectResponse::class, $response);
    $this->assertEquals(302, $response->getStatusCode());

    // Unsupported extension: image style conversion with a non-image extension
    // should still hotlink since the unconverted path extension is not in
    // supported extensions (so the conversion logic is skipped).
    $this->requestStack->pop();

    $subscriber = $this->createSubscriber([
      'origin' => 'https://prod.example.com',
      'origin_dir' => '',
      'hotlink' => TRUE,
      'use_imagecache_root' => TRUE,
      'excluded_extensions' => '',
      'verify' => TRUE,
      'proxy_headers' => '',
    ]);

    $event = $this->createRequestEvent('sites/default/files/documents/report.pdf');
    $subscriber->checkFileOrigin($event);

    $response = $event->getResponse();
    $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
    $this->assertStringContainsString('prod.example.com', $response->getTargetUrl());
  }

  /**
   * Tests no response is set when the origin is not configured.
   */
  public function testNoOriginDoesNothing(): void {
    $subscriber = $this->createSubscriber([
      'origin' => '',
    ]);

    $event = $this->createRequestEvent('sites/default/files/test.jpg');
    $subscriber->checkFileOrigin($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * Tests the request is ignored when the host matches origin.
   */
  public function testSameHostAsOriginDoesNothing(): void {
    $subscriber = $this->createSubscriber([
      'origin' => 'https://localhost',
    ]);

    $event = $this->createRequestEvent('sites/default/files/test.jpg');
    $subscriber->checkFileOrigin($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * Tests the request outside the files directory is ignored.
   */
  public function testNonFilePathDoesNothing(): void {
    $subscriber = $this->createSubscriber([
      'origin' => 'https://prod.example.com',
    ]);

    $event = $this->createRequestEvent('admin/config');
    $subscriber->checkFileOrigin($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * Tests excluded extensions are not proxied.
   */
  public function testExcludedExtensionDoesNothing(): void {
    $subscriber = $this->createSubscriber([
      'origin' => 'https://prod.example.com',
      'origin_dir' => '',
      'hotlink' => TRUE,
      'excluded_extensions' => 'css, js, map',
      'use_imagecache_root' => TRUE,
      'verify' => TRUE,
      'proxy_headers' => '',
    ]);

    $event = $this->createRequestEvent('sites/default/files/test.css');
    $subscriber->checkFileOrigin($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * Tests parent directory traversal is blocked.
   */
  public function testParentTraversalBlocked(): void {
    $subscriber = $this->createSubscriber([
      'origin' => 'https://prod.example.com',
      'origin_dir' => '',
      'hotlink' => TRUE,
      'excluded_extensions' => '',
      'use_imagecache_root' => TRUE,
      'verify' => TRUE,
      'proxy_headers' => '',
    ]);

    $event = $this->createRequestEvent('sites/default/files/../../../etc/passwd');
    $subscriber->checkFileOrigin($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * Tests CSS/JS aggregation paths are skipped.
   */
  public function testCssJsAggregationSkipped(): void {
    $subscriber = $this->createSubscriber([
      'origin' => 'https://prod.example.com',
      'origin_dir' => '',
      'hotlink' => TRUE,
      'excluded_extensions' => '',
      'use_imagecache_root' => TRUE,
      'verify' => TRUE,
      'proxy_headers' => '',
    ]);

    $event = $this->createRequestEvent('sites/default/files/css/style.css');
    $subscriber->checkFileOrigin($event);
    $this->assertNull($event->getResponse());

    $this->requestStack->pop();

    $event = $this->createRequestEvent('sites/default/files/js/script.js');
    $subscriber->checkFileOrigin($event);
    $this->assertNull($event->getResponse());
  }

  /**
   * Tests download mode calls fetch on the manager.
   */
  public function testDownloadModeFetchesFile(): void {
    $manager = $this->createMock(DownloadManagerInterface::class);
    $manager->method('filePublicPath')->willReturn('sites/default/files');
    $manager->method('styleOriginalPath')->willReturn(FALSE);
    $manager->expects($this->once())
      ->method('fetch')
      ->with(
        'https://prod.example.com',
        'sites/default/files',
        'images/photo.jpg',
        $this->anything(),
      )
      ->willReturn(TRUE);

    $this->createSubscriber([
      'origin' => 'https://prod.example.com',
      'origin_dir' => '',
      'hotlink' => FALSE,
      'excluded_extensions' => '',
      'use_imagecache_root' => TRUE,
      'verify' => TRUE,
      'proxy_headers' => '',
    ]);

    // Recreate with the custom manager mock.
    $subscriber = new StageFileProxySubscriber(
      $manager,
      $this->logger,
      $this->eventDispatcher,
      $this->configFactory,
      $this->requestStack,
      $this->pageCacheKillSwitch,
      $this->imageFactory,
      $this->fileUrlGenerator,
      $this->streamWrapperManager,
    );

    $event = $this->createRequestEvent('sites/default/files/images/photo.jpg');
    $subscriber->checkFileOrigin($event);

    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertEquals(302, $response->getStatusCode());
  }

}
