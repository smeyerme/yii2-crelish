<?php


namespace giantbits\crelish\components\Analytics;

use yii\base\Component;
use GuzzleHttp\Client;
use yii\helpers\Json;
use Yii;

class AnalyticsService extends Component
{
  public $measurementId;
  public $apiSecret;
  private $sessionDuration = 1800;
  private $client;
  private $batchSize = 1;
  private $eventQueue = [];

  // Configure debug mode for development
  public $debug = YII_DEBUG;

  public function init()
  {
    parent::init();
    $this->client = new Client([
      'timeout' => 2.0,
      'connect_timeout' => 1.0
    ]);

    $this->measurementId = Yii::$app->params['crelish']['ga_measurement_id'];
    $this->apiSecret = Yii::$app->params['crelish']['ga_api_secret'];

    // Register shutdown function to ensure queued events are sent
    register_shutdown_function([$this, 'flush']);
  }

  private function generateClientId()
  {
    $session = Yii::$app->session;
    $clientId = $session->get('ga_client_id');

    if (!$clientId) {
      $clientId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
      );
      $session->set('ga_client_id', $clientId);
    }

    return $clientId;
  }

  private function getBaseParams()
  {
    $request = Yii::$app->request;
    $params = [
      'page_location' => $request->absoluteUrl,
      'user_agent' => $request->userAgent,
      'ip_address' => $request->userIP,
      'referrer' => $request->referrer,
      'session_id' => Yii::$app->session->id,
      'language' => Yii::$app->language,
      'screen_resolution' => Yii::$app->session->get('screen_resolution'),
    ];

    // Add UTM parameters if present
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $utm) {
      if ($value = $request->get($utm)) {
        $params[$utm] = $value;
      }
    }

    return $params;
  }

  /**
   * Queue an event for batch processing
   */
  private function queueEvent($eventName, $params = [])
  {
    $this->eventQueue[] = [
      'name' => $eventName,
      'params' => array_merge($this->getBaseParams(), $params)
    ];

    if (count($this->eventQueue) >= $this->batchSize) {
      $this->flush();
    }
  }

  /**
   * Send queued events to GA
   */
  public function flush()
  {
    if (empty($this->eventQueue)) {
      return;
    }

    try {
      $payload = [
        'client_id' => $this->generateClientId(),
        'events' => $this->eventQueue
      ];

      // If using Yii queue component, push to queue for processing
      /*
      if (Yii::$app->has('queue')) {
        Yii::$app->queue->push(new AnalyticsJob([
          'payload' => $payload,
          'measurementId' => $this->measurementId,
          'apiSecret' => $this->apiSecret
        ]));
      } else {
      */
        // Direct processing
        $this->sendToGA($payload);
      //}

      $this->eventQueue = [];
    } catch (\Exception $e) {
      Yii::error("Analytics batch send error: " . $e->getMessage());
    }
  }

  private function sendToGA($payload): void
  {
    if ($this->debug) {
      Yii::debug('Analytics payload: ' . Json::encode($payload));
      //return;
    }

    if(empty($this->measurementId) || empty($this->apiSecret)) {
      return;
    }

    $this->client->postAsync('https://www.google-analytics.com/mp/collect', [
      'query' => [
        'measurement_id' => $this->measurementId,
        'api_secret' => $this->apiSecret,
      ],
      'json' => $payload
    ]);
  }

  // Standard Event Tracking Methods
  public function trackPageView($title = null)
  {

    $params = ['page_title' => Yii::$app->params['content']['systitle']];
    $this->queueEvent('page_view', $params);
  }

  public function trackDownload($fileName, $fileType, $fileSize = null)
  {
    $this->queueEvent('file_download', [
      'file_name' => $fileName,
      'file_type' => $fileType,
      'file_extension' => pathinfo($fileName, PATHINFO_EXTENSION),
      'file_size' => $fileSize
    ]);
  }

  // E-commerce Event Tracking
  public function trackAddToCart($product)
  {
    $this->queueEvent('add_to_cart', [
      'currency' => 'EUR',
      'value' => $product->price,
      'items' => [[
        'item_id' => $product->id,
        'item_name' => $product->name,
        'price' => $product->price,
        'quantity' => 1
      ]]
    ]);
  }

  public function trackPurchase($order)
  {
    $items = array_map(function ($item) {
      return [
        'item_id' => $item->product_id,
        'item_name' => $item->product->name,
        'price' => $item->price,
        'quantity' => $item->quantity
      ];
    }, $order->items);

    $this->queueEvent('purchase', [
      'transaction_id' => $order->id,
      'currency' => 'EUR',
      'value' => $order->total,
      'tax' => $order->tax,
      'shipping' => $order->shipping,
      'items' => $items
    ]);
  }

  // User Interaction Tracking
  public function trackSearch($searchTerm, $resultsCount)
  {
    $this->queueEvent('search', [
      'search_term' => $searchTerm,
      'results_count' => $resultsCount
    ]);
  }

  public function trackError($errorCode, $errorMessage)
  {
    $this->queueEvent('error', [
      'error_code' => $errorCode,
      'error_message' => $errorMessage,
      'page_location' => Yii::$app->request->absoluteUrl
    ]);
  }

  public function trackFormSubmission($formId, $success = true)
  {
    $this->queueEvent('form_submission', [
      'form_id' => $formId,
      'success' => $success
    ]);
  }

  // Custom Event Tracking
  public function trackCustomEvent($eventName, $params = [])
  {
    $this->queueEvent($eventName, $params);
  }

  public function trackPageLoadTiming($timing)
  {
    $this->queueEvent('page_load_timing', [
      'dns_time' => $timing['domainLookupEnd'] - $timing['domainLookupStart'],
      'tcp_connect_time' => $timing['connectEnd'] - $timing['connectStart'],
      'server_response_time' => $timing['responseEnd'] - $timing['requestStart'],
      'dom_interactive_time' => $timing['domInteractive'] - $timing['navigationStart'],
      'dom_complete_time' => $timing['domComplete'] - $timing['navigationStart'],
      'page_load_time' => $timing['loadEventEnd'] - $timing['navigationStart']
    ]);
  }
  /**
   * User Navigation Tracking
   */
  public function trackNavigation($from, $to)
  {
    $this->queueEvent('navigation', [
      'from_path' => $from,
      'to_path' => $to,
      'navigation_type' => 'internal'
    ]);
  }

  /**
   * Content Engagement
   */
  public function trackContentView($contentId, $contentType, $details = [])
  {
    $params = [
      'content_id' => $contentId,
      'content_type' => $contentType,
      'author' => $details['author'] ?? null,
      'publish_date' => $details['publish_date'] ?? null,
      'categories' => $details['categories'] ?? [],
      'tags' => $details['tags'] ?? []
    ];

    $this->queueEvent('content_view', $params);
  }

  /**
   * Enhanced E-commerce: Cart Abandonment
   */
  public function trackCartAbandonment($cart)
  {
    $items = array_map(function($item) {
      return [
        'item_id' => $item->product_id,
        'item_name' => $item->product->name,
        'price' => $item->price,
        'quantity' => $item->quantity
      ];
    }, $cart->items);

    $this->queueEvent('cart_abandonment', [
      'cart_id' => $cart->id,
      'cart_value' => $cart->total,
      'items' => $items,
      'abandonment_time' => time(),
      'last_activity' => $cart->updated_at
    ]);
  }

  /**
   * User Action Tracking
   */
  public function trackUserAction($action, $category, $details = [])
  {
    $this->queueEvent('user_action', array_merge([
      'action' => $action,
      'category' => $category,
      'timestamp' => time()
    ], $details));
  }

  public function trackApiRequest($endpoint, $method, $responseTime, $statusCode)
  {
    $this->queueEvent('api_request', [
      'endpoint' => $endpoint,
      'http_method' => $method,
      'response_time' => $responseTime,
      'status_code' => $statusCode,
      'user_type' => Yii::$app->user->isGuest ? 'anonymous' : 'authenticated'
    ]);
  }

  /**
   * Feature Usage Tracking
   */
  public function trackFeatureUsage($featureId, $action, $details = [])
  {
    $this->queueEvent('feature_usage', array_merge([
      'feature_id' => $featureId,
      'action' => $action,
      'user_type' => Yii::$app->user->isGuest ? 'anonymous' : 'authenticated',
      'user_role' => Yii::$app->user->isGuest ? null : Yii::$app->user->identity->role
    ], $details));
  }

  /**
   * Social Interaction Tracking
   */
  public function trackSocialInteraction($action, $network, $target)
  {
    $this->queueEvent('social_interaction', [
      'action' => $action,
      'network' => $network,
      'target' => $target,
      'page_url' => Yii::$app->request->absoluteUrl
    ]);
  }
}