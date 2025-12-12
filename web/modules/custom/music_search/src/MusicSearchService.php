<?php

namespace Drupal\music_search;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Service for searching music across multiple providers.
 */
class MusicSearchService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The HTTP client.
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
   * The Discogs lookup Service.
   *
   * @var \Drupal\discogs_lookup\DiscogsLookupService|null
   */
  protected $discogsService;

  /**
   * Constructs a MusicSearchService object.
   */
  public function __construct(
    ConfigFactoryInterface        $config_factory,
    ClientInterface               $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface    $entity_type_manager,
  ) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Sets the Spotify service.
   */
  public function setSpotifyService($service) {
    $this->spotifyService = $service;
  }

  /**
   * Sets the Discogs service.
   */
  public function setDiscogsService($service) {
    $this->discogsService = $service;
  }

  /**
   * Search across all available services.
   *
   * @param string $query
   *   The search query.
   * @param string $type
   *   The type: 'artist', 'album', or 'song'.
   *
   * @return array
   *   Combined results from all services.
   */
  public function searchAll(string $query, string $type = 'artist') {
    $results = [
      'spotify' => [],
      'discogs' => [],
    ];

    if ($this->spotifyService) {
      try {
        $results['spotify'] = $this->spotifyService->search($query, $type);
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('music_search')->error(
          'Spotify search error: @message',
          ['@message' => $e->getMessage()]
        );
      }
    }

    if ($this->discogsService) {
      try {
        $results['discogs'] = $this->discogsService->search($query, $type);
      }
      catch (\Exception $e) {
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
   *   The provider: 'spotify' or 'discogs'.
   * @param string $id
   *   The item ID.
   * @param string $type
   *   The type: 'artist', 'album', or 'song'.
   *
   * @return array|null
   *   Detailed information or NULL.
   */
  public function getDetails($provider, $id, $type) {
    if ($provider === 'spotify' && $this->spotifyService) {
      return $this->spotifyService->getDetails($id, $type);
    }
    elseif ($provider === 'discogs' && $this->discogsService) {
      return $this->discogsService->getDetails($id, $type);
    }
    return NULL;
  }

  /**
   * Create content from selected data.
   *
   * @param array $data
   *   The selected data from various providers.
   * @param string $type
   *   The type: 'artist', 'album', or 'song'.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The created node or NULL.
   */
  public function createContent(array $data, string $type) {

    $this->loggerFactory->get('music_search')->debug(
      'Raw data array: @data',
      ['@data' => Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]
    );

    // --- NEW: if an artist has members, treat it as a band -------------------
    if ($type === 'artist' && !empty($data['members']) && is_array($data['members'])) {
      $this->loggerFactory->get('music_search')->info(
        'Artist has members, creating Band instead for @name',
        ['@name' => $data['name'] ?? 'Unknown']
      );
      // Assumes your Band content type machine name is "band".
      $type = 'band';
    }

    try {
      $node_storage = $this->entityTypeManager->getStorage('node');

      if ($type === 'artist' && !empty($data['members'])) {
        $type = 'band';
      }

      $values = [
        'type' => $type,
        'title' => $data['name'] ?? ($data['title'] ?? 'Untitled'),
        'status' => 1,
      ];

      // Provider IDs (shared across bundles if the fields exist there).
      if (!empty($data['spotify_id'])) {
        $values['field_spotify_id'] = $data['spotify_id'];
      }
      if (!empty($data['discogs_id'])) {
        $values['field_discogs_id'] = $data['discogs_id'];
      }

      // ---------------------- ARTIST ----------------------------------------
      if ($type === 'artist') {
        // Genres (taxonomy: music_genre) – artist-specific field.
        if (!empty($data['genres']) && is_array($data['genres'])) {
          $values['field_music_genre_artist'] = $this->getOrCreateTerms($data['genres']);
        }

        // Artist image -> field_artist_image (Media reference).
        if (!empty($data['image'])) {
          $media_id = $this->createMediaFromUrl($data['image'], $data['name'] ?? 'Artist Image');
          if ($media_id) {
            $values['field_artist_image'] = [
              'target_id' => $media_id,
            ];
          }
        }

        // Long description / profile.
        if (!empty($data['profile'])) {
          $values['field_artist_description_long'] = [
            'value' => $data['profile'],
            'format' => 'basic_html',
          ];
        }
        elseif (!empty($data['description'])) {
          $values['field_artist_description_long'] = [
            'value' => $data['description'],
            'format' => 'basic_html',
          ];
        }

        // Website (Spotify or Discogs).
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
        $node = $this->createOrUpdateArtist($data, TRUE);
        return $node;
      }

      // ---------------------- BAND ------------------------------------------
      elseif ($type === 'band') {
        // Description: use profile/description into field_band_description (plain long).
        $band_description = $data['profile'] ?? ($data['description'] ?? '');
        if (!empty($band_description)) {
          // Text (plain, long) only needs the value.
          $values['field_band_description'] = $band_description;
        }

        // Band logo image -> field_band_logo (Media reference).
        if (!empty($data['image'])) {
          $media_id = $this->createMediaFromUrl($data['image'], $data['name'] ?? 'Band Logo');
          if ($media_id) {
            $values['field_band_logo'] = [
              'target_id' => $media_id,
            ];
          }
        }

        // Website (same field name as Artist: field_website).
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

        // Members → field_band_members (entity reference to Artist content).
        if (!empty($data['members']) && is_array($data['members'])) {
          $member_refs = [];

          foreach ($data['members'] as $member) {
            // Handle both "string" and ["name" => "..."] formats.
            if (is_string($member)) {
              $member_name = trim($member);
            }
            elseif (is_array($member) && !empty($member['name'])) {
              $member_name = trim($member['name']);
            }
            else {
              continue;
            }

            if ($member_name === '') {
              continue;
            }

            $artist_nid = $this->findOrCreateArtist($member_name);
            if ($artist_nid) {
              $member_refs[] = ['target_id' => $artist_nid];
            }
          }

          if (!empty($member_refs)) {
            $values['field_band_members'] = $member_refs;
          }
        }

        // (Optional) If you later want to attach albums here:
        // $values['field_band_albums'] = [...];
      }

      // ---------------------- ALBUM -----------------------------------------
      elseif ($type === 'album') {
        // Artist (stored as plain text for now).
        if (!empty($data['artist'])) {
          $values['field_artist'] = $data['artist'];
        }

        // Year.
        if (!empty($data['year'])) {
          $values['field_release_year'] = $data['year'];
        }

        // Genres (shared music_genre vocabulary).
        if (!empty($data['genres']) && is_array($data['genres'])) {
          $values['field_music_genre'] = $this->getOrCreateTerms($data['genres']);
        }

        // Cover image -> field_album_cover (Media reference).
        if (!empty($data['image'])) {
          $media_id = $this->createMediaFromUrl($data['image'], $data['name'] ?? 'Album Cover');
          if ($media_id) {
            $values['field_album_cover'] = $media_id;
          }
        }
      }

      // ---------------------- SONG ------------------------------------------
      elseif ($type === 'song') {
        // Artist reference: create/find Artist node.
        if (!empty($data['artist'])) {
          $artist_node_id = $this->findOrCreateArtist($data['artist'], $data);
          if ($artist_node_id) {
            $values['field_artist_song'] = [
              'target_id' => $artist_node_id,
            ];
          }
        }

        // Album (stored as plain text for now).
        if (!empty($data['album'])) {
          $values['field_album'] = $data['album'];
        }

        // Genres.
        if (!empty($data['genres']) && is_array($data['genres'])) {
          $values['field_music_genre'] = $this->getOrCreateTerms($data['genres']);
        }

        // Length as duration.
        if (!empty($data['length'])) {
          $parts = explode(':', $data['length']);
          $minutes = isset($parts[0]) ? (int) $parts[0] : 0;
          $seconds = isset($parts[1]) ? (int) $parts[1] : 0;

          $values['field_song_duration'] = [
            'duration' => 'PT' . $minutes . 'M' . $seconds . 'S',
            'seconds' => $seconds + (60 * $minutes),
          ];

          $this->loggerFactory->get('music_search')->info(
            'Song duration: @length → @min minutes @sec seconds',
            [
              '@length' => $data['length'],
              '@min' => $minutes,
              '@sec' => $seconds,
            ]
          );
        }
      }

      // ----------------------------------------------------------------------
      $node = $node_storage->create($values);
      $node->save();

      $this->loggerFactory->get('music_search')->info(
        'Created @type: @title (nid: @nid)',
        [
          '@type' => $type,
          '@title' => $node->label(),
          '@nid' => $node->id(),
        ]
      );

      return $node;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('music_search')->error(
        'Error creating content: @message',
        ['@message' => $e->getMessage()]
      );
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
  protected function createMediaFromUrl($url, $name = 'Image') {
    try {
      $image_data = file_get_contents($url);
      if (!$image_data) {
        return NULL;
      }

      /** @var \Drupal\Core\File\FileSystemInterface $file_system */
      $file_system = \Drupal::service('file_system');
      $directory = 'public://music_images';

      $file_system->prepareDirectory(
        $directory,
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
      );

      /** @var \Drupal\file\FileRepositoryInterface $file_repository */
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
      $this->loggerFactory->get('music_search')->error(
        'Error creating media: @message',
        ['@message' => $e->getMessage()]
      );
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

    // Search for existing artist by name.
    $existing_artists = $node_storage->loadByProperties([
      'type' => 'artist',
      'title' => $artist_name,
    ]);

    if (!empty($existing_artists)) {
      $artist_node = reset($existing_artists);

      $this->loggerFactory->get('music_search')->info(
        'Found existing artist: @name (nid: @nid)',
        [
          '@name' => $artist_name,
          '@nid' => $artist_node->id(),
        ]
      );

      return $artist_node->id();
    }

    // Artist doesn't exist, create it.
    try {
      $values = [
        'type' => 'artist',
        'title' => $artist_name,
        'status' => 1,
      ];

      // Add Spotify ID if available.
      if (!empty($data['artist_id'])) {
        $values['field_spotify_id'] = $data['artist_id'];
      }

      // Add Discogs ID if available.
      if (!empty($data['discogs_artist_id'])) {
        $values['field_discogs_id'] = $data['discogs_artist_id'];
      }

      $artist_node = $node_storage->create($values);
      $artist_node->save();

      $this->loggerFactory->get('music_search')->info(
        'Created new artist: @name (nid: @nid)',
        [
          '@name' => $artist_name,
          '@nid' => $artist_node->id(),
        ]
      );

      return $artist_node->id();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('music_search')->error(
        'Error creating artist: @message',
        ['@message' => $e->getMessage()]
      );
      return NULL;
    }
  }

  /**
   * Create or update artist content.
   *
   * @param array $data
   *   The artist data.
   * @param bool $update_existing
   *   Whether to update existing artist if found.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The created/updated node or NULL.
   */
  public function createOrUpdateArtist(array $data, $update_existing = TRUE) {
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');

      $existing_artists = $node_storage->loadByProperties([
        'type' => 'artist',
        'title' => $data['name'],
      ]);

      if (!empty($existing_artists) && $update_existing) {
        $node = reset($existing_artists);

        $this->loggerFactory->get('music_search')->info('Updating existing artist: @name (nid: @nid)', [
          '@name' => $data['name'],
          '@nid' => $node->id(),
        ]);

        // Update fields with new data
        if (!empty($data['spotify_id'])) {
          $node->set('field_spotify_id', $data['spotify_id']);
        }

        if (!empty($data['discogs_id'])) {
          $node->set('field_discogs_id', $data['discogs_id']);
        }

        // Update genres
        if (!empty($data['genres']) && is_array($data['genres'])) {
          $tids = $this->getOrCreateTerms($data['genres']);
          if (!empty($tids)) {
            $node->set('field_music_genre_artist', $tids);
          }
        }

        // Update image
        if (!empty($data['image'])) {
          $media_id = $this->createMediaFromUrl($data['image'], $data['name'] ?? 'Artist Image');
          if ($media_id) {
            $node->set('field_artist_image', ['target_id' => $media_id]);
          }
        }

        // Update description
        if (!empty($data['profile']) || !empty($data['description'])) {
          $description = $data['profile'] ?? $data['description'];
          $node->set('field_artist_description_long', [
            'value' => $description,
            'format' => 'basic_html',
          ]);
        }

        // Update website
        if (!empty($data['spotify_url']) || !empty($data['discogs_url'])) {
          $url = $data['spotify_url'] ?? $data['discogs_url'];
          $node->set('field_website', [
            'uri' => $url,
            'title' => !empty($data['spotify_url']) ? 'Spotify' : 'Discogs',
          ]);
        }

        $node->save();

        $this->loggerFactory->get('music_search')->info('Successfully updated artist: @name (nid: @nid)', [
          '@name' => $node->label(),
          '@nid' => $node->id(),
        ]);

        return $node;
      }
      elseif (!empty($existing_artists) && !$update_existing) {
        $node = reset($existing_artists);

        $this->loggerFactory->get('music_search')->warning('Artist already exists: @name (nid: @nid)', [
          '@name' => $data['name'],
          '@nid' => $node->id(),
        ]);

        return NULL;
      }

      $values = [
        'type' => 'artist',
        'title' => $data['name'],
        'status' => 1,
      ];

      // Add all fields
      if (!empty($data['spotify_id'])) {
        $values['field_spotify_id'] = $data['spotify_id'];
      }

      if (!empty($data['discogs_id'])) {
        $values['field_discogs_id'] = $data['discogs_id'];
      }

      if (!empty($data['genres']) && is_array($data['genres'])) {
        $tids = $this->getOrCreateTerms($data['genres']);
        if (!empty($tids)) {
          $values['field_music_genre_artist'] = $tids;
        }
      }

      if (!empty($data['image'])) {
        $media_id = $this->createMediaFromUrl($data['image'], $data['name'] ?? 'Artist Image');
        if ($media_id) {
          $values['field_artist_image'] = ['target_id' => $media_id];
        }
      }

      if (!empty($data['profile']) || !empty($data['description'])) {
        $description = $data['profile'] ?? $data['description'];
        $values['field_artist_description_long'] = [
          'value' => $description,
          'format' => 'basic_html',
        ];
      }

      if (!empty($data['spotify_url']) || !empty($data['discogs_url'])) {
        $url = $data['spotify_url'] ?? $data['discogs_url'];
        $values['field_website'] = [
          'uri' => $url,
          'title' => !empty($data['spotify_url']) ? 'Spotify' : 'Discogs',
        ];
      }

      $node = $node_storage->create($values);
      $node->save();

      $this->loggerFactory->get('music_search')->info('Created artist: @name (nid: @nid)', [
        '@name' => $node->label(),
        '@nid' => $node->id(),
      ]);

      return $node;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('music_search')->error('Error creating/updating artist: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }
}
