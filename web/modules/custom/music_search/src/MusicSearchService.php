<?php

namespace Drupal\music_search;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
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
        $this->loggerFactory->get('music_search')->error(
          'Spotify search error: @message',
          ['@message' => $e->getMessage()]
        );
      }
    }

    if ($this->discogsService) {
      try {
        $results['discogs'] = $this->discogsService->search($query, $type);
      } catch (\Exception $e) {
        $this->loggerFactory->get('music_search')->error(
          'Discogs search error: @message',
          ['@message' => $e->getMessage()]
        );
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
  public function createContent(array $data, string $type) {

    $this->loggerFactory->get('music_search')->debug(
      'Raw data array: @data',
      ['@data' => Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]
    );

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
          $values['field_music_genre_artist'] = $this->getOrCreateTerms($data['genres']);
        }

        if (!empty($data['image'])) {
          $media_id = $this->createMediaFromUrl($data['image'], $data['name'] ?? 'Artist Image');
          if ($media_id) {
            $values['field_artist_image'] = [
              'target_id' => $media_id,
            ];
          }
        }

        if (!empty($data['profile'])) {
          $values['field_artist_description_long'] = [
            'value' => $data['profile'],
            'format' => 'basic_html',  // or 'plain_text' or 'full_html'
          ];
        }
        elseif (!empty($data['description'])) {
          $values['field_artist_description_long'] = [
            'value' => $data['description'],
            'format' => 'basic_html',
          ];
        }

        if (!empty($data['spotify_url'])) {
          $values['field_website'] = [
            'uri' => $data['spotify_url'],
            'title' => 'Spotify Profile',
          ];
        }
        elseif (!empty($data['discogs_url'])) {
          $values['field_website'] = [
            'uri' => $data['discogs_url'],
            'title' => 'Discogs Profile',
          ];
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
          $values['field_release_year'] = $data['year'];
        }

        // Genres
        if (!empty($data['genres']) && is_array($data['genres'])) {
          $values['field_music_genre'] = $this->getOrCreateTerms($data['genres']);
        }

        // Cover Image
        if (!empty($data['image'])) {
          $media_id = $this->createMediaFromUrl($data['image'], $data['name'] ?? 'Album Cover');
          if ($media_id) {
            $values['field_album_cover'] = $media_id;
          }
        }
      }
      elseif ($type === 'song') {
        // Artist
        if (!empty($data['artist'])) {
          $artist_node_id = $this->findOrCreateArtist($data['artist'], $data);
          if ($artist_node_id) {
            $values['field_artist_song'] = [
              'target_id' => $artist_node_id,
            ];
          }
        }

        // Album
        if (!empty($data['album'])) {
          $values['field_album'] = $data['album'];
        }

        // Genres
        if (!empty($data['genres']) && is_array($data['genres'])) {
          $values['field_music_genre'] = $this->getOrCreateTerms($data['genres']);
        }

        // Length
        if (!empty($data['length'])) {
          $parts = explode(':', $data['length']);
          $minutes = isset($parts[0]) ? (int)$parts[0] : 0;
          $seconds = isset($parts[1]) ? (int)$parts[1] : 0;

          $values['field_song_duration'] = [
            'duration' => 'PT'.$minutes.'M'.$seconds.'S',
            'seconds' => $seconds + (60 * $minutes),
          ];

          $this->loggerFactory->get('music_search')->info('Song duration: @length → @min minutes @sec seconds', [
            '@length' => $data['length'],
            '@min' => $minutes,
            '@sec' => $seconds,
          ]);
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
      $image_data = file_get_contents($url);
      if (!$image_data) {
        return NULL;
      }

      $file_system = \Drupal::service('file_system');
      $directory = 'public://music_images';

      $file_system->prepareDirectory(
        $directory,
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
      );

      $file_repository = \Drupal::service('file.repository');
      $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '.jpg';

      $file = $file_repository->writeData(
        $image_data,
        $directory . '/' . $filename,
        FileSystemInterface::EXISTS_RENAME
      );

      if (!$file) {
        return NULL;
      }

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

  /**
   * Find or create an artist node.
   *
   * @param string $artist_name
   *   The artist name.
   * @param array $data
   *   Additional data from API (optional).
   *
   * @return int|null
   *   The artist node ID or NULL.
   */
  protected function findOrCreateArtist($artist_name, array $data = []) {
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Search for existing artist by name
    $existing_artists = $node_storage->loadByProperties([
      'type' => 'artist',
      'title' => $artist_name,
    ]);

    if (!empty($existing_artists)) {
      // Artist exists, return the ID
      $artist_node = reset($existing_artists);

      $this->loggerFactory->get('music_search')->info('Found existing artist: @name (nid: @nid)', [
        '@name' => $artist_name,
        '@nid' => $artist_node->id(),
      ]);

      return $artist_node->id();
    }

    // Artist doesn't exist, create it
    try {
      $values = [
        'type' => 'artist',
        'title' => $artist_name,
        'status' => 1,
      ];

      // Add Spotify ID if available
      if (!empty($data['artist_id'])) {
        $values['field_spotify_id'] = $data['artist_id'];
      }

      // Add Discogs ID if available
      if (!empty($data['discogs_artist_id'])) {
        $values['field_discogs_id'] = $data['discogs_artist_id'];
      }

      $artist_node = $node_storage->create($values);
      $artist_node->save();

      $this->loggerFactory->get('music_search')->info('Created new artist: @name (nid: @nid)', [
        '@name' => $artist_name,
        '@nid' => $artist_node->id(),
      ]);

      return $artist_node->id();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('music_search')->error('Error creating artist: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }
}
