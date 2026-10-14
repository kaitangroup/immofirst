<?php

declare(strict_types=1);

namespace Drupal\immofirst_search_request\Service;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\PrivateKey;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Signed "Immobiliengesuch löschen" links for the requester's e-mails.
 *
 * Requesters have no account (the wizard is anonymous), so the e-mail link
 * itself is the authorization — built like Drupal's password-reset links:
 * an HMAC over the request's node ID, creation time and stored e-mail,
 * keyed with the site's private key and hash salt. It can't be guessed or
 * moved to another request, stops working if the e-mail address changes,
 * and is single-use by nature (the request is gone afterwards).
 *
 * The link only opens a confirmation form; nothing is deleted on GET.
 */
final class SearchRequestDeletionLink {

  public function __construct(
    private readonly PrivateKey $privateKey,
  ) {}

  /**
   * Absolute URL of the confirmation page, or NULL if not applicable.
   */
  public function url(NodeInterface $node): ?string {
    if (!$this->applies($node)) {
      return NULL;
    }
    return Url::fromRoute('immofirst_search_request.self_delete', [
      'node' => $node->id(),
      'hash' => $this->hash($node),
    ])->setAbsolute()->toString();
  }

  /**
   * Whether $hash authorizes deleting $node.
   */
  public function verify(NodeInterface $node, string $hash): bool {
    return $this->applies($node) && hash_equals($this->hash($node), $hash);
  }

  private function applies(NodeInterface $node): bool {
    return $node->bundle() === 'search_request'
      && !$node->isNew()
      && $node->hasField('field_email')
      && trim((string) $node->get('field_email')->value) !== '';
  }

  private function hash(NodeInterface $node): string {
    $data = implode(':', [
      'immofirst_search_request_delete',
      $node->id(),
      $node->getCreatedTime(),
      mb_strtolower(trim((string) $node->get('field_email')->value)),
    ]);
    return Crypt::hmacBase64($data, $this->privateKey->get() . Settings::getHashSalt());
  }

}
