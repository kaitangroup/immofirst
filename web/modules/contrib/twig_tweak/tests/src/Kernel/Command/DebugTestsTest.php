<?php

declare(strict_types=1);

namespace Drupal\Tests\twig_tweak\Kernel\Command;

use Drupal\KernelTests\KernelTestBase;
use Drupal\twig_tweak\Command\DebugTestsCommand;
use Drupal\twig_tweak\Command\SignatureFormatter;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A test for 'twig-tweak:debug:tests' console command.
 */
final class DebugTestsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['twig_tweak', 'views', 'filter'];

  /**
   * {@selfdoc}
   */
  public function testCommand(): void {
    $command = new DebugTestsCommand($this->container->get('twig'), new SignatureFormatter());
    $tester = new CommandTester($command);
    $result = $tester->execute([]);
    self::assertSame(0, $result);
    $expected_display = <<< 'TXT'
     constant
     defined
     divisible by
     empty
     even
     iterable
     mapping
     none
     null
     odd
     same as
     sequence
     true

    TXT;
    self::assertSame($expected_display, $tester->getDisplay());
  }

}
