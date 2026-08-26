<?php

declare(strict_types=1);

namespace Drupal\twig_tweak\View;

use Drupal\Core\Menu\MenuLinkTreeInterface;

/**
 * Menu view builder.
 */
final readonly class MenuViewBuilder {

  /**
   * {@selfdoc}
   */
  public function __construct(private MenuLinkTreeInterface $menuLinkTree) {}

  /**
   * Returns the render array for a menu.
   *
   * @param non-empty-string $menu_name
   *   The name of the menu.
   * @param non-negative-int $level
   *   (optional) Initial menu level.
   * @param non-negative-int $depth
   *   (optional) Maximum number of menu levels to display.
   * @param bool $expand
   *   (optional) Expand all menu links.
   *
   * @return array
   *   A render array for the menu.
   *
   * @see \Drupal\system\Plugin\Block\SystemMenuBlock::build()
   */
  public function build(string $menu_name, int $level = 1, int $depth = 0, bool $expand = FALSE): array {
    $parameters = $this->menuLinkTree->getCurrentRouteMenuTreeParameters($menu_name);

    // Adjust the menu tree parameters based on the block's configuration.
    $parameters->setMinDepth($level);
    // When the depth is configured to zero, there is no depth limit. When depth
    // is non-zero, it indicates the number of levels that must be displayed.
    // Hence this is a relative depth that we must convert to an actual
    // (absolute) depth, that may never exceed the maximum depth.
    if ($depth > 0) {
      $parameters->setMaxDepth(min($level + $depth - 1, $this->menuLinkTree->maxDepth()));
    }

    // If expandedParents is empty, the whole menu tree is built.
    if ($expand) {
      $parameters->expandedParents = [];
    }

    $tree = $this->menuLinkTree->load($menu_name, $parameters);
    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $this->menuLinkTree->transform($tree, $manipulators);
    $build = $this->menuLinkTree->build($tree);

    if (!isset($build['#cache']['keys'])) {
      $build['#cache']['keys'] = [
        'twig_tweak_menu',
        $menu_name,
        '[level]=' . $level,
        '[depth]=' . $depth,
        '[expand]=' . (int) $expand,
      ];
    }

    $build['#cache']['tags'][] = 'config:system.menu.' . $menu_name;
    $build['#cache']['contexts'][] = 'route.menu_active_trails:' . $menu_name;
    $build['#cache']['contexts'][] = 'languages:language_content';

    return $build;
  }

}
