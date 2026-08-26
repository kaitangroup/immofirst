<?php

declare(strict_types=1);

namespace Drupal\stage_file_proxy_test\HttpClientMiddleware;

use Drupal\Core\State\StateInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Test HTTP client middleware to intercept stage file proxy requests.
 */
class StageFileProxyTestHttpClientMiddleware {

  /**
   * Constructs a StageFileProxyTestHttpClientMiddleware object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state interface.
   */
  public function __construct(protected readonly StateInterface $state) {
  }

  /**
   * Middleware callback.
   */
  public function __invoke(): callable {
    return function ($handler): callable {
      return function (RequestInterface $request, array $options) {
        $request_uri = $request->getUri()->__toString();
        $mock_responses = $this->state->get('stage_file_proxy_test_responses', []);
        $mock_response = array_shift($mock_responses);
        if (empty($mock_response) || $mock_response['uri'] !== $request_uri) {
          // An extra request for this URI was made, or the requests were made
          // in the wrong order, return a 409 conflict response.
          $extra_requests = $this->state->get('stage_file_proxy_test_extra_requests', []);
          $extra_requests[] = $request_uri;
          $this->state->set('stage_file_proxy_test_extra_requests', $extra_requests);
          return Create::promiseFor(new Response(409, [], sprintf('An unexpected request to "%s" was made.', $request_uri)));
        }
        // Use the mocked response.
        $this->state->set('stage_file_proxy_test_responses', $mock_responses);
        $response = new Response($mock_response['status'] ?? 200, $mock_response['headers'], $mock_response['body']);
        return Create::promiseFor($response);
      };
    };
  }

  /**
   * Sets the mock responses for certain endpoints.
   *
   * The responses will be removed as they're consumed by the middleware.
   *
   * @param array $mock_responses
   *   The list of mock responses.
   */
  public static function setProxyResponses(array $mock_responses): void {
    // Convert the endpoint to an absolute URL.
    \Drupal::state()->set('stage_file_proxy_test_responses', $mock_responses);
  }

  /**
   * Returns the mock responses for this test.
   *
   * @return array
   *   The list of mock responses.
   */
  public static function getProxyResponses(): array {
    return \Drupal::state()->get('stage_file_proxy_test_responses', []);
  }

  /**
   * Returns any extra unexpected requests to the mock endpoint.
   *
   * @return array
   *   The list of extra requests.
   */
  public static function getExtraRequests(): array {
    return \Drupal::state()->get('stage_file_proxy_test_extra_requests', []);
  }

}
