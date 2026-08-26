<?php

namespace Drupal\Tests\stage_file_proxy\Functional;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\stage_file_proxy_test\HttpClientMiddleware\StageFileProxyTestHttpClientMiddleware;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\TestFileCreationTrait;

/**
 * Tests the stage file proxy public file download behavior.
 *
 * @group stage_file_proxy
 */
class StageFileProxyPublicFileTest extends BrowserTestBase {

  use TestFileCreationTrait;

  /**
   * Modules to install.
   *
   * @var array
   */
  protected static $modules = [
    'stage_file_proxy',
    'stage_file_proxy_test',
    'image',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The file that's used for the tests.
   *
   * @var \Drupal\file\Entity\FileInterface
   */
  protected FileInterface $file;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->configFactory = $this->container->get('config.factory');
    $this->state = $this->container->get('state');
    $this->fileSystem = $this->container->get('file_system');

    // Disable automatic following of redirects by the HTTP client, so that the
    // tests can analyze the redirect response headers properly.
    $this->toggleRedirects(FALSE);

    $test_files = $this->getTestFiles('image');
    /** @var \Drupal\file\FileInterface $file */
    $this->file = File::create([
      'uri' => $test_files[0]->uri,
      'uid' => 1,
    ]);
    $this->file->save();
  }

  /**
   * Tests the default behavior when the module is not configured.
   *
   * @dataProvider providerTestCleanUrls
   */
  public function testDefaultBehavior(bool $clean_url): void {
    // Whether requests are made with clean URLs in mind.
    $this->prepareRequestForGenerator($clean_url);
    $file_url = $this->file->createFileUrl();
    $this->drupalGet($file_url);

    if ($clean_url === FALSE) {
      // When the module is not configured to handle proxy files, unclean URLs
      // should 404 as normal.
      $this->assertSession()->statusCodeEquals(404);
    }
    else {
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->responseHeaderEquals('content-type', $this->file->getMimeType());
    }
  }

  /**
   * Test the hotlinking behavior when the file does not exist.
   *
   * @dataProvider providerTestCleanUrls
   */
  public function testHotlinking(bool $clean_url): void {
    // Whether requests are made with clean URLs in mind.
    $this->prepareRequestForGenerator($clean_url);

    $file_url = $this->file->createFileUrl();
    [, $file_name] = explode('://', $this->file->getFileUri());
    $this->deleteTestFile();

    // Enable hotlinking.
    $this->configFactory
      ->getEditable('stage_file_proxy.settings')
      ->set('hotlink', TRUE)
      ->set('origin', 'https://test-remote-stage-file-proxy-server.com')
      ->set('origin_dir', 'subdir')
      ->save(TRUE);

    // It should redirect to the remote server for hotlinking.
    $this->drupalGet($file_url);
    $this->assertSession()->statusCodeEquals(302);
    $this->assertSession()->responseHeaderEquals('location', 'https://test-remote-stage-file-proxy-server.com/subdir/' . $file_name);

    // Ensure that the change in origin is also picked up without being cached.
    $this->configFactory
      ->getEditable('stage_file_proxy.settings')
      ->set('origin', 'https://test-remote-stage-file-proxy-server2.com')
      ->set('origin_dir', 'sites/default/files')
      ->save(TRUE);

    $this->drupalGet($file_url);
    $this->assertSession()->statusCodeEquals(302);
    $this->assertSession()->responseHeaderEquals('location', 'https://test-remote-stage-file-proxy-server2.com/sites/default/files/' . $file_name);
  }

  /**
   * Test file proxy behavior.
   *
   * @dataProvider providerTestCleanUrls
   */
  public function testFileProxy(bool $clean_url): void {
    /** @var \Drupal\image\ImageStyleInterface $image_style */
    $image_style_id = $this->randomMachineName(8);
    $image_style = ImageStyle::create([
      'name' => $image_style_id,
      'label' => $image_style_id,
    ]);
    $image_style->save();

    // Fetch the clean image style url before updating the default request
    // generator.
    $clean_image_style_url = $image_style->buildUrl($this->file->getFileUri());

    // Whether requests are made with clean URLs in mind.
    $this->prepareRequestForGenerator($clean_url);
    $file_uri = $this->file->getFileUri();
    $file_url = $this->file->createFileUrl();
    [, $file_name] = explode('://', $file_uri);

    $image_style_uri = $image_style->buildUri($file_uri);
    $image_style_url = $image_style->buildUrl($file_uri);
    $this->prepareRequestForGenerator($clean_url);
    $image_style_url_parts = explode('/', substr($image_style_url, strrpos($image_style_url, $image_style->id() . '/public')), 3);
    // Fetch the file part of the url including the query strings.
    $image_style_url_file_path = $image_style_url_parts[2];

    // Get the file contents.
    $file_contents = file_get_contents($file_uri);

    $this->deleteTestFile();

    // Attempt to "download" the file using through the proxy.
    $this->configFactory
      ->getEditable('stage_file_proxy.settings')
      ->set('hotlink', FALSE)
      ->set('origin', 'https://test-remote-stage-file-proxy-server.com')
      ->set('origin_dir', 'subdir')
      ->save(TRUE);
    // Store the mock responses to use for this test.
    StageFileProxyTestHttpClientMiddleware::setProxyResponses([
      [
        // It should attempt to download the real image, but also passes the
        // query arguments from the original image style.
        'uri' => "https://test-remote-stage-file-proxy-server.com/subdir/$image_style_url_file_path",
        'status' => 200,
        'headers' => [
          'content-type' => $this->file->getMimeType(),
        ],
        'body' => $file_contents,
      ],
      [
        'uri' => "https://test-remote-stage-file-proxy-server.com/subdir/$file_name",
        'status' => 200,
        'headers' => [
          'content-type' => $this->file->getMimeType(),
        ],
        'body' => $file_contents,
      ],
    ]);
    $this->assertFalse(is_file($file_uri), 'File should not exist on disk');
    // Making a request to image styles will create the original file, then
    // trigger a redirect to the same URL in order to generate the appropriate
    // image style.
    $this->drupalGet($image_style_url);
    $this->assertTrue(is_file($file_uri), 'File should now exist on disk');
    $this->assertFalse(is_file($image_style_uri), 'Image style should not exist on disk');
    $this->assertSession()->statusCodeEquals(302);
    $this->assertEquals(
      $this->makeRelativeUrl($image_style_url),
      $this->getSession()->getResponseHeader('location'),
      'The same request should be remade'
    );
    // Following the redirect should ensure the image style now exists on disk.
    $this->followRedirectUrl($this->getSession()->getResponseHeader('location'));
    $this->assertTrue(is_file($image_style_uri), 'Image style should now exist on disk');

    // Delete the original file.
    $this->deleteTestFile(FALSE);

    // Making a request for the same file should download and trigger a
    // redirect back to the same URL.
    $this->drupalGet($file_url);
    $this->assertSession()->statusCodeEquals(302);
    // It should redirect to the same request url since it now exists on disk.
    $this->assertEquals(
      $clean_url ? $file_url : $this->makeUncleanUrl($file_url),
      $this->getSession()->getResponseHeader('location'),
      'The destination redirect should be the real file'
    );
    if ($clean_url === FALSE) {
      // Test that an unclean URL request for a file that exists on disk should
      // still be resolved.
      $this->drupalGet($file_url);
      $this->assertSession()->statusCodeEquals(302);
      // It should redirect to the real file since it now exists on disk.
      $this->assertEquals($file_url, $this->getSession()->getResponseHeader('location'), 'The destination redirect should be the real file');

      // Ensure that the same unclean index.php request should redirect to the
      // real file without attempting to re-download since it now exists on
      // disk.
      $this->drupalGet($file_url);
      $this->assertSession()->statusCodeEquals(302);
      // It should redirect to the real file since it now exists on disk.
      $this->assertEquals($file_url, $this->getSession()->getResponseHeader('location'));

      // Fetching the unclean image style url should redirect to the real file
      // on disk.
      $this->drupalGet($image_style_url);
      $this->assertSession()->statusCodeEquals(302);
      $this->assertEquals($this->makeRelativeUrl($clean_image_style_url), $this->getSession()->getResponseHeader('location'), 'The destination redirect should be the real file');
    }

    // Fetch the real file without the unclean url script being passed through.
    $this->drupalGet($file_url, [
      'script' => '',
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('content-type', $this->file->getMimeType());
    $this->assertSession()->responseContains($file_contents);
    $this->assertSession()->responseHeaderDoesNotExist('X-Generator');

    // Ensure that no extra requests were made to the middleware in an attempt
    // to download the file.
    $this->assertEquals('', implode('\n', StageFileProxyTestHttpClientMiddleware::getExtraRequests()), 'No extra requests to download the file were made');
    $this->assertEmpty(StageFileProxyTestHttpClientMiddleware::getProxyResponses(), 'There should be no handled proxy responses');
  }

  /**
   * Data provider for clean and unclean urls.
   */
  public static function providerTestCleanUrls() {
    return [
      'clean url' => [TRUE],
      'unclean url' => [FALSE],
    ];
  }

  /**
   * Delete the test file from disk.
   */
  protected function deleteTestFile(bool $check_remote = TRUE): void {
    $file_uri = $this->file->getFileUri();
    // Delete the file, ensuring that it no longer exists locally.
    $this->fileSystem->delete($file_uri);
    $this->assertFalse(is_file($file_uri), 'File should no longer exist');
    if ($check_remote) {
      // Check that request for the file is now a 404 response.
      $this->drupalGet($this->file->createFileUrl());
      $this->assertSession()->statusCodeEquals(404);
    }
  }

  /**
   * Toggle redirects.
   *
   * @param bool $enabled
   *   Whether redirects should be automatically followed.
   * @param null|int $refreshCount
   *   The max number of redirects to follow based off the http-equiv "Refresh"
   *   meta attribute.
   */
  protected function toggleRedirects(bool $enabled, int $refreshCount = 0): void {
    $this->getSession()->getDriver()->getClient()->followRedirects($enabled);
    $this->maximumMetaRefreshCount = $enabled ? NULL : $refreshCount;
  }

  /**
   * Converts the URL into an unclean version.
   */
  protected function makeUncleanUrl(string $url): string {
    global $base_path;
    $url_parts = parse_url($url);
    $path = $url_parts['path'];
    if (str_starts_with($path, $base_path)) {
      // Apply the index.url_path prefix after the base path.
      $path = substr($path, strlen($base_path));
      $path = $base_path . 'index.php/' . $path;
    }
    if (isset($url_parts['scheme'], $url_parts['host'])) {
      $unclean_url = $url_parts['scheme'] . '://' . $url_parts['host'];
      if (isset($url_parts['port'])) {
        $unclean_url .= ':' . $url_parts['port'];
      }
      $unclean_url .= $path;
    }
    else {
      $unclean_url = $path;
    }
    if (isset($url_parts['query'])) {
      $unclean_url .= '?' . $url_parts['query'];
    }
    return $unclean_url;
  }

  /**
   * Returns a relative URL for the URL.
   */
  protected function makeRelativeUrl(string $url): string {
    $components = parse_url($url);
    $result = $components['path'];
    if (isset($components['query'])) {
      $result .= '?' . $components['query'];
    }
    if (isset($components['fragment'])) {
      $result .= '#' . $components['fragment'];
    }
    return $result;
  }

  /**
   * Returns an absolute url if the current URL isn't one already.
   */
  protected function followRedirectUrl(string $url): void {
    $parsed = UrlHelper::parse($url);
    // Parse url into path and query string.
    $this->drupalGet($parsed['path'], [
      'query' => $parsed['query'],
      // Don't include the script as the URL should already have it.
      'script' => '',
    ]);
  }

}
