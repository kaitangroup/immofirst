<?php

declare(strict_types=1);

namespace Drupal\stage_file_proxy\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for stage_file_proxy module.
 */
class StageFileProxyHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    if ($route_name === 'help.page.stage_file_proxy') {
      $output = '<p>' . $this->t("Stage File Proxy is a general solution for getting production files on a development server on demand. It saves you time and disk space by sending requests to your development environment's files directory to the production environment and making a copy of the production file in your development site. You should not need to enable this module in production.") . '</p>';
      $output .= '<p>' . $this->t('See the <a href=":project_page">project page on Drupal.org</a> for more details.', [
        ':project_page' => 'https://www.drupal.org/project/stage_file_proxy',
      ]) . '</p>';
      return $output;
    }
    return '';
  }

}
