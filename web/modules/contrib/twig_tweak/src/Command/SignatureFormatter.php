<?php

declare(strict_types=1);

namespace Drupal\twig_tweak\Command;

use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * A formatter for Twig callables.
 */
final readonly class SignatureFormatter {

  private const int PAD_LENGTH = 1;

  /**
   * Returns string presentation for a twig callable.
   */
  public function formatSignature(TwigFunction|TwigFilter|TwigTest $entity): string {
    if (!\in_array($entity->getType(), ['function', 'filter', 'test'])) {
      throw new \InvalidArgumentException('Wrong Twig entity type');
    }

    $name = \str_repeat(' ', self::PAD_LENGTH) . '<fg=#37e>' . $entity->getName() . '</>';

    // Extracting signature form Twig tests is tricky.
    if ($entity->getType() === 'test') {
      return $name;
    }
    $callable = $entity->getCallable();
    if (!$callable) {
      return $name;
    }
    if (\is_array($callable)) {
      if (!method_exists($callable[0], $callable[1])) {
        return $name;
      }
      $reflection = new \ReflectionMethod($callable[0], $callable[1]);
    }
    elseif (\is_object($callable) && \method_exists($callable, '__invoke')) {
      $reflection = new \ReflectionMethod($callable, '__invoke');
    }
    elseif (\function_exists($callable)) {
      $reflection = new \ReflectionFunction($callable);
    }
    elseif (\is_string($callable) && \preg_match('{^(.+)::(.+)$}', $callable, $m) && \method_exists($m[1], $m[2])) {
      $reflection = new \ReflectionMethod($m[1], $m[2]);
    }
    else {
      throw new \UnexpectedValueException('Unsupported callback type.');
    }

    $signature = '';

    // -- Docblock.
    if ($reflection->getDocComment()) {
      $doc_lines = \array_map(
        self::formatDocLine(...),
        \explode(\PHP_EOL, $reflection->getDocComment()),
      );
      $signature .= '<fg=green>' . \implode(\PHP_EOL, $doc_lines) . '</>' . \PHP_EOL;
    }

    // -- Name.
    $signature .= $name;

    // -- Parameters.
    $parameters = \array_map(self::formatParameter(...), $reflection->getParameters());
    $signature .= '(' . \implode(', ', $parameters) . ')';

    // -- Return type.
    if ($reflection->hasReturnType()) {
      $signature .= ': <fg=#cfc>' . $reflection->getReturnType() . '</>';
    }

    return $signature;
  }

  /**
   * The callable may be a function or a class name. So that the docblock may
   * have different padding length.
   */
  private static function formatDocLine(string $line): string {
    $pad = \str_repeat(' ', self::PAD_LENGTH);
    $prefix = \str_starts_with('/**', $line) ? $pad : $pad . ' ';
    return $prefix . \ltrim($line);
  }

  /**
   * {@selfdoc}
   */
  private static function formatParameter(\ReflectionParameter $parameter): string {
    $signature = '';
    if ($parameter->getType()) {
      $signature .= '<fg=#cfc>' . $parameter->getType() . '</> ';
    }
    $signature .= '<fg=#e85>$' . $parameter->getName() . '</>';
    if ($parameter->isDefaultValueAvailable()) {
      $signature .= ' = ' . \json_encode($parameter->getDefaultValue());
    }
    return $signature;
  }

}
