<?php

namespace Drupal\discogs_lookup;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for Discogs API integration.
 */
class DiscogsLookupService
{
  /**
   * Discogs API base URL
   */
  const API_BASE = 'https://api.discogs.org/';

  /**
   * The config factory
   */
  protected $configFactory;

  /**
   * The Http client.
   */
  protected $httpClient;

  /**
   * The logger
   */
  protected $logger;

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('discogs_lookup');
  }

  /**
   * Search Discogs
   */
  public function search($query, $type) {
    $config = $this->configFactory->get('discogs_lookup.settings');
    $api_key = $config->get('discogs_api_key');
    $api_secret = $config->get('discogs_api_secret');

    if (empty($api_key) || empty($api_secret)) {
      $this->logger->error('Discogs credentials not configured');
      return [];
    }

    // Map type to Discogs type
    $discogs_type = $type === 'song' ? 'release' : $type;

    try {
      $response = $this->httpClient->get(self::API_BASE . '/database/search', [
        'query' => [
          'q' => $query,
          'type' => $discogs_type,
          'key' => $api_key,
          'secret' => $api_secret,
        ],
        'headers' => [
          'User-Agent' => 'MusicSearchDrupal/1.0',
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);

      return $this->formatResults($data, $type);
    }
    catch (RequestException $e) {
      $this->logger->error('Discogs search error: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Get details for a specific item.
   */
  public function getDetails($id, $type) {
    $config = $this->configFactory->get('music_search.settings');
    $api_key = $config->get('discogs_api_key');
    $api_secret = $config->get('discogs_api_secret');

    if (empty($api_key) || empty($api_secret)) {
      return NULL;
    }

    try {
      $endpoint = self::API_BASE . '/';

      if ($type === 'artist') {
        $endpoint .= 'artists/' . $id;
      }
      elseif ($type === 'album' || $type === 'song') {
        $endpoint .= 'releases/' . $id;
      }

      $response = $this->httpClient->get($endpoint, [
        'query' => [
          'key' => $api_key,
          'secret' => $api_secret,
        ],
        'headers' => [
          'User-Agent' => 'MusicSearchDrupal/1.0',
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);

      return $this->formatDetails($data, $type);
    }
    catch (RequestException $e) {
      $this->logger->error('Discogs details error: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Format search results.
   */
  protected function formatResults($data, $type) {
    $results = [];

    if (empty($data['results'])) {
      return $results;
    }

    foreach ($data['results'] as $item) {
      if ($type === 'artist') {
        $results[] = [
          'id' => $item['id'],
          'name' => $item['title'] ?? 'Unknown',
          'image' => $item['cover_image'] ?? $item['thumb'] ?? NULL,
          'type' => $item['type'] ?? 'artist',
          'provider' => 'discogs',
        ];
      }
      elseif ($type === 'album') {
        $results[] = [
          'id' => $item['id'],
          'name' => $item['title'] ?? 'Unknown',
          'artist' => !empty($item['artist']) ? (is_array($item['artist']) ? implode(', ', $item['artist']) : $item['artist']) : 'Unknown',
          'image' => $item['cover_image'] ?? $item['thumb'] ?? NULL,
          'year' => $item['year'] ?? NULL,
          'format' => $item['format'] ?? [],
          'label' => $item['label'] ?? [],
          'provider' => 'discogs',
        ];
      }
      elseif ($type === 'song') {
        // Discogs doesn't have individual tracks in search, use releases
        $results[] = [
          'id' => $item['id'],
          'name' => $item['title'] ?? 'Unknown',
          'artist' => !empty($item['artist']) ? (is_array($item['artist']) ? implode(', ', $item['artist']) : $item['artist']) : 'Unknown',
          'year' => $item['year'] ?? NULL,
          'provider' => 'discogs',
        ];
      }
    }
    return $results;
  }

  /**
   * Format detailed information.
   */
  protected function formatDetails($data, $type) {
    if ($type === 'artist') {
      return [
        'id' => $data['id'],
        'name' => $data['name'] ?? 'Unknown',
        'image' => $data['images'][0]['uri'] ?? NULL,
        'profile' => $data['profile'] ?? '',
        'members' => $data['members'] ?? [],
        'genres' => array_merge($data['genres'] ?? [], $data['styles'] ?? []),
        'discogs_url' => $data['uri'] ?? NULL,
        'provider' => 'discogs',
      ];
    }
    elseif ($type === 'album' || $type === 'song') {
      return [
        'id' => $data['id'],
        'name' => $data['title'] ?? 'Unknown',
        'artist' => $data['artists_sort'] ?? 'Unknown',
        'image' => $data['images'][0]['uri'] ?? NULL,
        'year' => $data['year'] ?? NULL,
        'genres' => array_merge($data['genres'] ?? [], $data['styles'] ?? []),
        'tracklist' => $data['tracklist'] ?? [],
        'labels' => $data['labels'] ?? [],
        'formats' => $data['formats'] ?? [],
        'discogs_url' => $data['uri'] ?? NULL,
        'provider' => 'discogs',
      ];
    }
    return $data;
  }
}
