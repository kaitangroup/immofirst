<?php

namespace Drupal\Tests\stage_file_proxy\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the settings form validation.
 */
#[Group('stage_file_proxy')]
#[RunTestsInSeparateProcesses]
class SettingsFormTest extends BrowserTestBase {

  /**
   * Default Theme.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to install.
   *
   * @var array
   */
  protected static $modules = ['stage_file_proxy'];

  /**
   * A user with the permissions to edit the stage file proxy settings.
   */
  protected UserInterface $adminUser;

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function setUp(): void {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser(['administer stage_file_proxy settings']);
  }

  /**
   * Tests if the origin URL gets correctly trimmed.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  public function testOriginTrailingSlashIsRemoved() {
    $settings_path = Url::fromRoute('stage_file_proxy.admin_form');

    $this->drupalLogin($this->adminUser);

    $testOrigin = 'https://example.com';
    $edit = [
      // Test with adding a slash.
      'origin' => $testOrigin . '/',
    ];
    $this->drupalGet($settings_path);
    $this->submitForm($edit, 'Save configuration');

    // Test if the form was saved without error.
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // Test if the stored value has the trailing slash removed.
    $newOrigin = $this->config('stage_file_proxy.settings')->get('origin');
    $this->assertSame($newOrigin, $testOrigin);
  }

}
