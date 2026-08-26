<?php

declare(strict_types=1);

namespace Drupal\Tests\twig_tweak\Kernel\Command;

use Drupal\KernelTests\KernelTestBase;
use Drupal\twig_tweak\Command\DebugFiltersCommand;
use Drupal\twig_tweak\Command\SignatureFormatter;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A test for 'twig-tweak:debug:filters' console command.
 */
final class DebugFiltersTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['twig_tweak', 'filter', 'views'];

  /**
   * {@selfdoc}
   */
  public function testCommand(): void {
    $command = new DebugFiltersCommand($this->container->get('twig'), new SignatureFormatter());
    $tester = new CommandTester($command);
    $result = $tester->execute([]);
    self::assertSame(0, $result);
    self::assertStringContainsString('capitalize(string $charset, $string): string', $tester->getDisplay());
  }

}
