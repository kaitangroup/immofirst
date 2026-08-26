<?php

declare(strict_types=1);

namespace Drupal\twig_tweak_test\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup as TM;

/**
 * Provides a foo block.
 */
#[Block(
  id: self::ID,
  admin_label: new TM('Foo'),
  category: new TM('Twig Tweak'),
)]
final class FooBlock extends BlockBase {

  public const string ID = 'twig_tweak_test_foo';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['content' => 'Foo'];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    // @see \Drupal\Tests\twig_tweak\Kernel\BlockViewBuilderTest
    $result = AccessResult::allowedIf($account->getAccountName() == 'User 1');
    $result->addCacheTags(['tag_from_' . __FUNCTION__]);
    $result->setCacheMaxAge(35);
    $result->cachePerUser();
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#markup' => $this->getConfiguration()['content'],
      '#attributes' => [
        'id' => 'foo',
      ],
      '#cache' => [
        'contexts' => ['url'],
        'tags' => ['tag_from_' . __FUNCTION__],
      ],
    ];
  }

}
