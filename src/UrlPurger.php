<?php

namespace Drupal\custom_purge;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Class UrlPurger which defines a service for purging Urls.
 *
 * TODO Replace curl with Guzzle.
 */
class UrlPurger {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection $database
   */
  protected $database;

  /**
   * UrlPurger constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The purge settings.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Connection $database) {
    $this->configFactory = $config_factory;
    $this->database = $database;
  }

  /**
   * Clean up given Urls on any known provider.
   *
   * @param array $urls
   *   An array of urls to be purged.
   */
  public function purgeAllProviders(array $urls) {
    $this->purgeDrupalCacheRender($urls);
    $this->purgeVarnishCache($urls);
    $this->purgeCloudflareCache($urls);
  }

  /**
   * Clean up drupal cache_render table by given urls.
   *
   * @param $urls
   *   An array of urls to be purged.
   *
   * @return array
   *   Successfully processed urls are grouped into the key 'processed',
   *   whereas not successfully process urls are grouped into the key 'errors'.
   */
  public function purgeDrupalCacheRender($urls) {
    // Clear cache_render for defined urls.
    foreach ($urls as $url) {
      // Build cid key.
      $cid = $url . ':html';
      $this->database->delete('cache_render')
        ->condition('cid', $cid)
        ->execute();
    }
    return ['processed' => $urls, 'errors' => []];
  }

  /**
   * Clean up varnish cache with given urls.
   *
   * @param $urls
   *   An array of urls to be purged.
   *
   * @return array
   *   Successfully processed urls are grouped into the key 'processed',
   *   whereas not successfully process urls are grouped into the key 'errors'.
   */
  public function purgeVarnishCache($urls) {
    $info = ['processed' => [], 'errors' => []];
    // Clear varnish cache for defined urls.
    foreach ($urls as $url) {
      if ($this->setVarnishPurgeCall($url)) {
        $info['processed'][] = $url;
      }
      else {
        $info['errors'][] = $url;
      }
    }
    return $info;
  }

  /**
   * Build curl request for purging varnish.
   *
   * @param $url
   *  Single url that will be purged from varnish
   *
   * @return boolean
   */
  protected function setVarnishPurgeCall($url) {
    // Default return value.
    $processed = FALSE;
    if ($config = $this->configFactory->get('custom_purge.settings')) {
      $curlopt_resolve = $config->get('domain') . ':' . $config->get('varnish_port') . ':' . $config->get('varnish_ip');

      // Initialize curl call.
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_RESOLVE, [$curlopt_resolve]);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PURGE');
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept-Encoding: gzip',
        'X-Acquia-Purge: ' . $config->get('varnish_acquia_environment')
      ]);
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

      // Execute curl call.
      curl_exec($ch);
      // Check for possible errors.
      if (!curl_errno($ch)) {
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
          $processed = TRUE;
        }
      }
      // Close curl connection.
      curl_close($ch);
    }
    return $processed;
  }

  /**
   * Purge cloudflare cache for given urls.
   *
   * Requires the cloudflare module to work.
   *
   * @param $urls
   *   An array of urls to be purged.
   * @return array
   *   Successfully processed urls are grouped into the key 'processed',
   *   whereas not successfully process urls are grouped into the key 'errors'.
   */
  function purgeCloudflareCache($urls) {
    // Default processed status.
    $info = ['processed' => [], 'errors' => []];
    if ($config = $this->configFactory->get('cloudflare.settings')) {
      // Set cloudflare related API url.
      $cf_api_url = "https://api.cloudflare.com/client/v4/zones/" . $config->get('zone_id') . "/purge_cache";
      // Set cloudflare related headers used by curl call.
      $cf_header = [
        'X-Auth-Email: ' . $config->get('email'),
        'X-Auth-Key: ' . $config->get('apikey'),
        'Content-Type: application/json'
      ];

      // Initialize curl call.
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
      curl_setopt($ch, CURLOPT_HTTPHEADER, $cf_header);
      curl_setopt($ch, CURLOPT_URL, $cf_api_url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_TIMEOUT, 30);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
      // Set urls to be purged.
      $json_data = json_encode(['files' => $urls]);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);

      // Execute curl call.
      curl_exec($ch);

      // Check for possible errors.
      if (!curl_errno($ch)) {
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
          $info['processed'] = $urls;
        }
      }
      else {
        $info['errors'] = $urls;
      }
      // Close curl connection.
      curl_close($ch);
    }
    return $info;
  }

  /**
   * Get the config factory.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   */
  public function getConfigFactory() {
    return $this->configFactory;
  }

}
