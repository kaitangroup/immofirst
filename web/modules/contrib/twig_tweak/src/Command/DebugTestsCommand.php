<?php

declare(strict_types=1);

namespace Drupal\twig_tweak\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Twig\Environment;

#[AsCommand(
  name: 'twig-tweak:debug:tests',
  description: 'Show a list of Twig tests',
)]
final class DebugTestsCommand extends Command {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    private readonly Environment $twig,
    private readonly SignatureFormatter $metaDataExtractor,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $items = \array_map(
      $this->metaDataExtractor->formatSignature(...),
      $this->twig->getTests(),
    );

    \ksort($items);
    foreach ($items as $item) {
      $output->writeln($item);
    }

    return self::SUCCESS;
  }

}
