<?php

declare(strict_types=1);

namespace Drupal\Tests\twig_tweak\Kernel\Command;

use Drupal\Component\Utility\Unicode;
use Drupal\KernelTests\KernelTestBase;
use Drupal\twig_tweak\Command\SignatureFormatter;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * A test for signature formatter.
 */
final class SignatureFormatterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['twig_tweak', 'system'];

  /**
   * {@selfdoc}
   *
   * @dataProvider dataProvider
   */
  public function testFormatter(TwigFunction|TwigFilter|TwigTest $entity, string $expected_output): void {
    $formatter = new SignatureFormatter();
    $output = $formatter->formatSignature($entity);
    self::assertSame($output, $expected_output);
  }

  /**
   * {@selfdoc}
   */
  public static function dataProvider(): \Generator {
    yield [
      new TwigFunction('length', 'strlen'),
      ' <fg=#37e>length</>(<fg=#cfc>string</> <fg=#e85>$string</>): <fg=#cfc>int</>',
    ];
    yield [
      new TwigFunction('default_region', 'system_default_region'),
      <<< 'TXT'
      <fg=green> /**
        * Gets the name of the default region for a given theme.
        *
        * @param string $theme
        *   The name of a theme.
        *
        * @return string
        *   A string that is the region name.
        */</>
       <fg=#37e>default_region</>(<fg=#e85>$theme</>)
      TXT,
      yield [
        new TwigFilter('ucfirst', [Unicode::class, 'ucfirst']),
        <<< 'TXT'
        <fg=green> /**
          * Capitalizes the first character of a UTF-8 string.
          *
          * @param string $text
          *   The string to convert.
          *
          * @return string
          *   The string with the first character as uppercase.
          */</>
         <fg=#37e>ucfirst</>(<fg=#e85>$text</>)
        TXT,
      ],
      yield [
        new TwigFilter('lcfirst', '\Drupal\Component\Utility\Unicode::lcfirst'),
        <<< 'TXT'
        <fg=green> /**
          * Converts the first character of a UTF-8 string to lowercase.
          *
          * @param string $text
          *   The string that will be converted.
          *
          * @return string
          *   The string with the first character as lowercase.
          *
          * @ingroup php_wrappers
          */</>
         <fg=#37e>lcfirst</>(<fg=#e85>$text</>)
        TXT,
      ],
      yield [
        new TwigFilter('lcfirst', '\Drupal\Component\Utility\Unicode::lcfirst'),
        <<< 'TXT'
        <fg=green> /**
          * Converts the first character of a UTF-8 string to lowercase.
          *
          * @param string $text
          *   The string that will be converted.
          *
          * @return string
          *   The string with the first character as lowercase.
          *
          * @ingroup php_wrappers
          */</>
         <fg=#37e>lcfirst</>(<fg=#e85>$text</>)
        TXT,
      ],
      yield [
        new TwigTest('is_color', '\Drupal\Component\Utility\Color::validateHex'),
        ' <fg=#37e>is_color</>',
      ],
    ];
  }

}
