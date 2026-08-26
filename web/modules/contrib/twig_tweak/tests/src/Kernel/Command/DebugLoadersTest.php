<?php

declare(strict_types=1);

namespace Drupal\Tests\twig_tweak\Kernel\Command;

use Drupal\KernelTests\KernelTestBase;
use Drupal\twig_tweak\Command\DebugLoadersCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A test for 'twig-tweak:debug:loaders' console command.
 */
final class DebugLoadersTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['twig_tweak'];

  /**
   * {@selfdoc}
   */
  public function testCommand(): void {
    $command = new DebugLoadersCommand($this->container->get('twig'));
    $tester = new CommandTester($command);
    $result = $tester->execute([]);
    self::assertSame(0, $result);
    $display = $tester->getDisplay();
    self::assertStringContainsString('Namespace     Path', $display);
    self::assertStringContainsString(' (None)        ./', $display);
    self::assertStringContainsString('/twig_tweak/templates/', $display);
  }

}
