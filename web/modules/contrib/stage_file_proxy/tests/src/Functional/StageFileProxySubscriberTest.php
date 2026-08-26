<?php

declare(strict_types=1);

namespace Drupal\Tests\stage_file_proxy\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the StageFileProxySubscriber behavior.
 */
#[Group('stage_file_proxy')]
#[RunTestsInSeparateProcesses]
class StageFileProxySubscriberTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['stage_file_proxy', 'file'];

  /**
   * Tests paths that should not be proxied.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testPathsNotProxied(): void {
    $this->config('stage_file_proxy.settings')
      ->set('origin', 'https://example.com')
      ->set('excluded_extensions', 'map')
      ->save();

    // Excluded extension.
    $this->drupalGet('sites/default/files/test.map');
    $this->assertSession()->statusCodeEquals(404);

    // CSS/JS aggregation paths are hardcoded to be skipped.
    $this->drupalGet('sites/default/files/css/test.css');
    $this->assertSession()->statusCodeEquals(404);

    $this->drupalGet('sites/default/files/js/test.js');
    $this->assertSession()->statusCodeEquals(404);

    // Parent directory traversal is blocked.
    $this->drupalGet('sites/default/files/../../../etc/passwd');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Tests the settings form saves and validates correctly.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testSettingsForm(): void {
    $admin = $this->drupalCreateUser(['administer stage_file_proxy settings']);
    $this->drupalLogin($admin);

    $settings_path = Url::fromRoute('stage_file_proxy.admin_form');

    // Invalid origin fails validation.
    $this->drupalGet($settings_path);
    $this->submitForm(['origin' => 'not-a-valid-url'], 'Save configuration');
    $this->assertSession()->pageTextNotContains('The configuration options have been saved.');

    // Valid settings save correctly.
    $this->submitForm([
      'origin' => 'https://prod.example.com',
      'origin_dir' => 'sites/default/files',
      'use_imagecache_root' => TRUE,
      'hotlink' => FALSE,
      'verify' => TRUE,
      'excluded_extensions' => 'css, js',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('stage_file_proxy.settings');
    $this->assertEquals('https://prod.example.com', $config->get('origin'));
    $this->assertEquals('sites/default/files', $config->get('origin_dir'));
    $this->assertTrue($config->get('use_imagecache_root'));
    $this->assertFalse($config->get('hotlink'));
    $this->assertEquals('css, js', $config->get('excluded_extensions'));
  }

}
