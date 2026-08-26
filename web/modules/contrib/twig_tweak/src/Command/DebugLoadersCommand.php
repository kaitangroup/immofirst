<?php

declare(strict_types=1);

namespace Drupal\twig_tweak\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

#[AsCommand(
  name: 'twig-tweak:debug:loaders',
  description: 'Show a list of Twig loaders',
)]
final class DebugLoadersCommand extends Command {

  /**
   * {@inheritdoc}
   */
  public function __construct(private readonly Environment $twig) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);

    $rows = [];
    foreach ($this->getLoaderPaths() as $namespace => $paths) {
      foreach ($paths as $path) {
        $rows[] = [$namespace, $path . \DIRECTORY_SEPARATOR];
      }
    }
    $io->table(['Namespace', 'Path'], $rows);

    return self::SUCCESS;
  }

  /**
   * Gets loader paths.
   */
  private function getLoaderPaths(): array {
    $loader_paths = [];
    foreach ($this->getFilesystemLoaders() as $loader) {
      foreach ($loader->getNamespaces() as $namespace) {
        $paths = $loader->getPaths($namespace);
        $namespace = FilesystemLoader::MAIN_NAMESPACE === $namespace ? '(None)' : '@' . $namespace;
        $loader_paths[$namespace] = \array_merge($loader_paths[$namespace] ?? [], $paths);
      }
    }
    \ksort($loader_paths);
    return $loader_paths;
  }

  /**
   * Returns files system loaders.
   *
   * @return \Twig\Loader\FilesystemLoader[]
   *   File system loaders.
   */
  private function getFilesystemLoaders(): array {
    $loaders = [];
    $loader = $this->twig->getLoader();
    if ($loader instanceof FilesystemLoader) {
      $loaders[] = $loader;
    }
    elseif ($loader instanceof ChainLoader) {
      foreach ($loader->getLoaders() as $chained_loaders) {
        if ($chained_loaders instanceof FilesystemLoader) {
          $loaders[] = $chained_loaders;
        }
      }
    }
    return $loaders;
  }

}
