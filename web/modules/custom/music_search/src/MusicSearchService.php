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

      // Add fields based on type
      if ($type === 'artist') {
        // Genres
        if (!empty($data['genres']) && is_array($data['genres'])) {
          $values['field_music_genre'] = $this->getOrCreateTerms($data['genres']);
        }

        // Image
        if (!empty($data['image'])) {
          $media_id = $this->createMediaFromUrl($data['image'], $data['name'] ?? 'Artist Image');
          if ($media_id) {
            $values['field_images'] = $media_id;
          }
        }
      }
      elseif ($type === 'album') {
        // Artist (if it's a string, not entity reference)
        if (!empty($data['artist'])) {
          // For now, store as plain text or find/create artist entity
          // You might want to enhance this to link to actual artist nodes
          $values['field_artist'] = $data['artist'];
        }

        // Year
        if (!empty($data['year'])) {
          $values['field_year_release'] = $data['year'];
        }

        // Genres
        if (!empty($data['genres']) && is_array($data['genres'])) {
          $values['field_music_genre'] = $this->getOrCreateTerms($data['genres']);
        }

        // Cover Image
        if (!empty($data['image'])) {
          $media_id = $this->createMediaFromUrl($data['image'], $data['name'] ?? 'Album Cover');
          if ($media_id) {
            $values['field_cover_image'] = $media_id;
          }
        }
      }
      elseif ($type === 'song') {
        // Artist
        if (!empty($data['artist'])) {
          $values['field_artist'] = $data['artist'];
        }

        // Album
        if (!empty($data['album'])) {
          $values['field_album'] = $data['album'];
        }

        // Length
        if (!empty($data['length'])) {
          $values['field_length'] = $data['length'];
        }
      }

      $node = $node_storage->create($values);
      $node->save();

      $this->loggerFactory->get('music_search')->info('Created @type: @title (nid: @nid)', [
        '@type' => $type,
        '@title' => $node->label(),
        '@nid' => $node->id(),
      ]);

      return $node;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('music_search')->error('Error creating content: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Helper to get or create taxonomy terms.
   */
  protected function getOrCreateTerms(array $term_names) {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = [];

    foreach ($term_names as $name) {
      // Check if term exists
      $terms = $term_storage->loadByProperties([
        'name' => $name,
        'vid' => 'music_genre',
      ]);

      if (empty($terms)) {
        // Create new term
        $term = $term_storage->create([
          'vid' => 'music_genre',
          'name' => $name,
        ]);
        $term->save();
        $tids[] = $term->id();
      }
      else {
        // Use existing term
        $term = reset($terms);
        $tids[] = $term->id();
      }
    }

    return $tids;
  }

  /**
   * Helper to create media from URL.
   */
  protected function createMediaFromUrl($url, $name = 'Image') {
    try {
      // Download the image
      $image_data = file_get_contents($url);

      if (!$image_data) {
        return NULL;
      }

      // Create file
      $file_repository = \Drupal::service('file.repository');
      $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '.jpg';

      $file = $file_repository->writeData(
        $image_data,
        'public://music_images/' . $filename,
        \Drupal\Core\File\FileSystemInterface::EXISTS_RENAME
      );

      if (!$file) {
        return NULL;
      }

      // Create media entity
      $media_storage = $this->entityTypeManager->getStorage('media');
      $media = $media_storage->create([
        'bundle' => 'image',
        'name' => $name,
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt' => $name,
        ],
      ]);
      $media->save();

      return $media->id();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('music_search')->error('Error creating media: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }
}
