<?php

declare(strict_types=1);

namespace Drupal\stage_file_proxy_test\Http;

use Drupal\Core\Http\ClientFactory;
use GuzzleHttp\HandlerStack;

/**
 * Helper class to construct a mock HTTP client for testing.
 */
class TestClientFactory {

  /**
   * Constructs a new TestClientFactory instance.
   */
  public function __construct(protected readonly ClientFactory $clientFactory, protected readonly HandlerStack $stack) {
  }

  /**
   * Add a middleware to the current stack.
   */
  public function addMiddleware(callable $middleware, string $middleware_id) {
    $this->stack->push($middleware(), $middleware_id);
  }

  /**
   * Constructs a new client object from some configuration.
   *
   * @param array $config
   *   The config for the client.
   *
   * @return \GuzzleHttp\Client
   *   The HTTP client.
   */
  public function fromOptions(array $config = []) {
    // Specify the custom handler to use.
    $config['handler'] = $this->stack;
    return $this->clientFactory->fromOptions($config);
  }

}
