<?php

declare(strict_types=1);

namespace Drupal\twig_tweak\View;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds render arrays for blocks in Twig templates.
 */
final readonly class BlockViewBuilder {

  /**
   * {@selfdoc}
   */
  public function __construct(
    private BlockManagerInterface $pluginManagerBlock,
    private ContextRepositoryInterface $contextRepository,
    private ContextHandlerInterface $contextHandler,
    private AccountInterface $account,
    private RequestStack $requestStack,
    private RouteMatchInterface $routeMatch,
    private TitleResolverInterface $titleResolver,
  ) {}

  /**
   * Builds the render array for a block.
   *
   * @param string $id
   *   The ID of block plugin to render.
   * @param array $configuration
   *   (optional) Pass on any configuration to the plugin block.
   * @param bool $wrapper
   *   (optional) Whether to wrap the output in a block theme template.
   *
   * @return array
   *   A renderable array representing the content of the block.
   */
  public function build(string $id, array $configuration = [], bool $wrapper = TRUE): array {
    $configuration += ['label_display' => BlockPluginInterface::BLOCK_LABEL_VISIBLE];

    $block_plugin = $this->pluginManagerBlock->createInstance($id, $configuration);

    // Provide runtime contexts to context-aware block plugins.
    if ($block_plugin instanceof ContextAwarePluginInterface) {
      $contexts = $this->contextRepository->getRuntimeContexts($block_plugin->getContextMapping());
      $this->contextHandler->applyContextMapping($block_plugin, $contexts);
    }

    $build = [];
    $access = $block_plugin->access($this->account, TRUE);
    if ($access->isAllowed()) {
      // Title block needs a special treatment.
      if ($block_plugin instanceof TitleBlockPluginInterface) {
        // Account for the scenario that a NullRouteMatch is returned. This, for
        // example, is the case when Search API is indexing the site during
        // Drush cron.
        if ($route = $this->routeMatch->getRouteObject()) {
          $request = $this->requestStack->getCurrentRequest();
          $title = $this->titleResolver->getTitle($request, $route);
          $block_plugin->setTitle($title);
        }
      }

      // Place the content returned by the block plugin into a 'content' child
      // element, as a way to allow the plugin to have complete control of its
      // properties and rendering (for instance, its own #theme) without
      // conflicting with the properties used above.
      $build['content'] = $block_plugin->build();

      if ($block_plugin instanceof TitleBlockPluginInterface) {
        $build['content']['#cache']['contexts'][] = 'url';
      }
      if ($wrapper && !Element::isEmpty($build['content'])) {
        $build += [
          '#theme' => 'block',
          '#id' => $configuration['id'] ?? NULL,
          '#attributes' => [],
          '#contextual_links' => [],
          '#configuration' => $block_plugin->getConfiguration(),
          '#plugin_id' => $block_plugin->getPluginId(),
          '#base_plugin_id' => $block_plugin->getBaseId(),
          '#derivative_plugin_id' => $block_plugin->getDerivativeId(),
        ];
        // Move block-level properties from content to the top-level render array.
        // This preserves the plugin's rendering control while ensuring proper
        // theming via Drupal's block template.
        foreach (['#attributes', '#contextual_links'] as $property) {
          if (isset($build['content'][$property])) {
            $build[$property] = $build['content'][$property];
            unset($build['content'][$property]);
          }
        }
      }
    }

    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($access)
      ->addCacheableDependency($block_plugin)
      ->applyTo($build);

    // This will allow caching the block individually.
    if (!isset($build['#cache']['keys'])) {
      $build['#cache']['keys'] = [
        'twig_tweak_block',
        $id,
        '[configuration]=' . \hash('sha256', \serialize($configuration)),
        '[wrapper]=' . (int) $wrapper,
      ];
    }

    return $build;
  }

}
