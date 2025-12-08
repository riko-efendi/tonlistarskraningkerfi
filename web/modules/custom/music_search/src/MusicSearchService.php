<?php

namespace Drupal\music_search;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Service for searching music across multiple providers
 */

class MusicSearchService
{
  /**
   * The config factory
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Http client
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */

  protected $entityTypeManager;

  /**
   * The Spotify lookup service.
   *
   * @var \Drupal\spotify_lookup\SpotifyLookupService|null
   */
  protected $spotifyService;

  /**
   * The Discogs lookup Service
   *
   * @var \Drupal\discogs_lookup\DiscogsLookupService|null
   */
  protected $discogsService;

  /**
   * Constructs a MusicSearchService object
   */
  public function __construct(
    ConfigFactoryInterface        $config_factory,
    ClientInterface               $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface    $entity_type_manager,
  )
  {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Sets the Spotify service
   */
  public function setSpotifyService($service) {
    $this->spotifyService = $service;
  }

  /**
   * Sets the Discogs service
   */
public function setDiscogsService($service)
{
  $this->discogsService = $service;
}

  /**
   * Search across all available services
   *
   * @param string $query
   *  The search query
   * @param string $type
   *  The type: 'artist', 'album', or 'song'.
   *
   * @return array
   *  Combined results from all services.
   */
  public function searchAll(string $query, string $type = 'artist')
  {
    $results = [
      'spotify' => [],
      'discogs' => [],
    ];

    if ($this->spotifyService) {
      try {
        $results['spotify'] = $this->spotifyService->search($query, $type);
      } catch (\Exception $e) {
        $this->loggerFactory->get('music_search')->error('Spotify search error: @message', [$e->getMessage()]);
      }
    }

    if ($this->discogsService) {
      try {
        $results['discogs'] = $this->discogsService->search($query, $type);
      } catch (\Exception $e) {
        $this->loggerFactory->get('music_search')->error('Discogs search error: @message', [$e->getMessage()]);
      }
    }

    return $results;
  }

  /**
   * Get detailed information from a specific provider.
   *
   * @param string $provider
   *  The provider: 'spotify' or 'discogs'
   * @param string $id
   *  The item ID
   * @param string $type
   *  The type: 'artist', 'album', or 'song'.
   *
   * @return array|null
   *  Detailed information or null
   */
  public function getDetails($provider, $id, $type)
  {
    if ($provider === 'spotify' && $this->spotifyService) {
      return $this->spotifyService->getDetails($id, $type);
    } elseif ($provider === 'discogs' && $this->discogsService) {
      return $this->discogsService->getDetails($id, $type);
    }
    return NULL;
  }

  /**
   * Create content from selected data
   *
   * @param array $data
   * The selected data from various providers
   * @param string $type
   *  The type: 'artist', 'album', or 'song'.
   *
   * @return \Drupal\Core\Entity\EntityInterface|Null
   * The created node or NULL
   */
  public function createContent(array $data, $type) {
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');

      $values = [
        'type' => $type,
        'title' => $data['name'] ?? $data['title'], 'Untitled',
        'status' => 1,
      ];

      // Add provider IDs
      if (!empty($data['spotify_id'])) {
        $values['field_spotify_id'] = $data['spotify_id'];
      }
      if (!empty($data['discogs_id'])) {
        $values['field_discogs_id'] = $data['discogs_id'];
      }

      // Add other fields based on type
      if ($type === 'artist') {
        if(!empty($data['genre'])) {
          $values['field_music_genre'] = $this->getOrCreateTerms($data['genres']);
        }
        if(!empty($data['image'])) {
          $values['field_images'] = $this->createMediaFromUrl($data['image']);
        }
      }
      elseif ($type === 'album') {
        if (!empty($data['artist'])) {
          $values['field_artist'] = $data['artist'];
        }
        if (!empty($data['year'])) {
          $values['field_year_release'] = $data['year'];
        }
        if (!empty($data['cover_image'])) {
          $values['field_cover_image'] = $this->createMediaFromUrl($data['cover_image']);
        }
      }
      elseif ($type === 'song') {
        if (!empty($data['length'])) {
          $values['field_length'] = $data['length'];
        }
        if (!empty($data['artist'])) {
          $values['field_artist'] = $data['artist'];
        }
      }
      $node = $node_storage->create($values);
      $node->save();

      return $node;
    }
    catch(\Exception $e) {
      $this->loggerFactory->get('music_search')->error('Error creating content: @message', ['@message' => $e->getMessage()]);
      return null;
    }
  }

  /**
   * Helper to get or create taxonomy terms.
   */
  protected function getOrCreateTerms(array $term_names) {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = [];

    foreach ($term_names as $name) {
      $terms = $term_storage->loadByProperties([
        'name' => $name,
        'vid' => 'music_genre',
      ]);

      if (empty($terms)) {
        $term = $term_storage->create([
          'vid' => 'music_genre',
          'name' => $name,
        ]);
        $term->save();
        $tids[] = $term->id();
      }
      else {
        $term = reset($terms);
        $tids[] = $term->id();
      }
    }
    return $tids;
  }

  /**
   * Helper to create media from URL.
   */
  protected function createMediaFromUrl($url) {

    return NULL;
  }
}
