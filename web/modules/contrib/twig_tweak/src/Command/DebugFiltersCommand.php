<?php

declare(strict_types=1);

namespace Drupal\twig_tweak\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Twig\Environment;

#[AsCommand(
  name: 'twig-tweak:debug:filters',
  description: 'Show a list of Twig filters',
)]
final class DebugFiltersCommand extends Command {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    private readonly Environment $twig,
    private readonly SignatureFormatter $formatter,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $items = \array_map(
      $this->formatter->formatSignature(...),
      $this->twig->getFilters(),
    );

    \ksort($items);
    $output->writeln('');
    foreach ($items as $item) {
      $output->writeln($item);
      $output->writeln('');
    }

    return self::SUCCESS;
  }

}
