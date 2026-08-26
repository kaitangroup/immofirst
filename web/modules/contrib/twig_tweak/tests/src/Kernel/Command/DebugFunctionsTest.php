<?php

declare(strict_types=1);

namespace Drupal\Tests\twig_tweak\Kernel\Command;

use Drupal\KernelTests\KernelTestBase;
use Drupal\twig_tweak\Command\DebugFunctionsCommand;
use Drupal\twig_tweak\Command\SignatureFormatter;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A test for 'twig-tweak:debug:functions' console command.
 */
final class DebugFunctionsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['twig_tweak', 'views', 'filter'];

  /**
   * {@selfdoc}
   */
  public function testCommand(): void {
    $command = new DebugFunctionsCommand($this->container->get('twig'), new SignatureFormatter());
    $tester = new CommandTester($command);
    $result = $tester->execute([]);
    self::assertSame(0, $result);
    self::assertStringContainsString('url($name, $parameters = [], $options = [])', $tester->getDisplay());
  }

}
