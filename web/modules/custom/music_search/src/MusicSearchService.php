<?php

namespace Drupal\music_search;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\node\NodeInterface;

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
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
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
   */
  public function createContent(array $data, string $type) {
    $this->loggerFactory->get('music_search')->debug(
      'Raw data array: @data',
      ['@data' => Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]
    );

    try {
      if ($type === 'artist') {
        if (!empty($data['members'])) {
          $this->loggerFactory->get('music_search')->info(
            'Artist has members; using band upsert. Name=@name',
            ['@name' => $data['name'] ?? ($data['title'] ?? 'Unknown')]
          );
          return $this->createOrUpdateBand($data, TRUE);
        }

        return $this->createOrUpdateArtist($data, TRUE);
      }

      $node_storage = $this->entityTypeManager->getStorage('node');

      if ($type === 'album') {
        $values = [
          'type' => 'album',
          'title' => $data['name'] ?? ($data['title'] ?? 'Untitled'),
          'status' => 1,
        ];

        if (!empty($data['spotify_id'])) {
          $values['field_spotify_id'] = $data['spotify_id'];
        }
        if (!empty($data['discogs_id'])) {
          $values['field_discogs_id'] = $data['discogs_id'];
        }

        if (!empty($data['artist'])) {
          $values['field_artist'] = $data['artist'];
        }

        if (!empty($data['year'])) {
          $values['field_release_year'] = $data['year'];
        }

        if (!empty($data['genres']) && is_array($data['genres'])) {
          $values['field_music_genre'] = $this->getOrCreateTerms($data['genres']);
        }

        if (!empty($data['image'])) {
          $media_id = $this->createMediaFromUrl($data['image'], $data['name'] ?? 'Album Cover');
          if ($media_id) {
            $values['field_album_cover'] = $media_id;
          }
        }

        $node = $node_storage->create($values);
        $node->save();
        return $node;
      }

      if ($type === 'song') {
        $this->loggerFactory->get('music_search')->warning(
          'Song data: @data',
          ['@data' => Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]
        );

        $values = [
          'type' => 'song',
          'title' => $data['name'] ?? ($data['title'] ?? 'Untitled'),
          'status' => 1,
        ];

        if (!empty($data['spotify_id'])) {
          $values['field_spotify_id'] = $data['spotify_id'];
        }
        if (!empty($data['discogs_id'])) {
          $values['field_discogs_id'] = $data['discogs_id'];
        }

        if (!empty($data['artist'])) {
          $artist_node_id = $this->resolveArtistOrBand($data['artist'], $data);
          if ($artist_node_id) {
            $values['field_artist_song'] = $this->buildNodeReferenceValue('field_artist_song', (int) $artist_node_id);
          }
        }

        if (!empty($data['album'])) {
          $values['field_album'] = $data['album'];
        }

        if (!empty($data['genres']) && is_array($data['genres'])) {
          $values['field_music_genre'] = $this->getOrCreateTerms($data['genres']);
        }

        if (!empty($data['length'])) {
          $parts = explode(':', $data['length']);
          $minutes = isset($parts[0]) ? (int) $parts[0] : 0;
          $seconds = isset($parts[1]) ? (int) $parts[1] : 0;

          $values['field_song_duration'] = [
            'duration' => 'PT' . $minutes . 'M' . $seconds . 'S',
            'seconds' => $seconds + (60 * $minutes),
          ];
        }

        $node = $node_storage->create($values);
        $node->save();
        return $node;
      }

      $this->loggerFactory->get('music_search')->warning('Unsupported content type: @type', ['@type' => $type]);
      return NULL;
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
   * Build an entity reference value that works for:
   * - entity_reference  => ['target_id' => X]
   * - entity_reference_revisions => ['target_id' => X, 'target_revision_id' => Y]
   */
  protected function buildNodeReferenceValue(string $field_name, int $target_nid): array {
    $node_storage = $this->entityTypeManager->getStorage('node');
    /** @var \Drupal\node\NodeInterface|null $target */
    $target = $node_storage->load($target_nid);

    if (!$target) {
      return [];
    }

    $this->loggerFactory->get('music_search')->debug(
      'Reference build: field=@field nid=@nid bundle=@bundle rev=@rev',
      [
        '@field' => $field_name,
        '@nid' => $target->id(),
        '@bundle' => $target->bundle(),
        '@rev' => $target->getRevisionId(),
      ]
    );

    $field_manager = \Drupal::service('entity_field.manager');
    $storage_defs = $field_manager->getFieldStorageDefinitions('node');
    $storage_type = isset($storage_defs[$field_name]) ? $storage_defs[$field_name]->getType() : NULL;

    if ($storage_type === 'entity_reference_revisions') {
      return [
        'target_id' => $target->id(),
        'target_revision_id' => $target->getRevisionId(),
      ];
    }

    return [
      'target_id' => $target->id(),
    ];
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
      $image_data = @file_get_contents($url);
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
      $this->loggerFactory->get('music_search')->error(
        'Error creating media: @message',
        ['@message' => $e->getMessage()]
      );
      return NULL;
    }
  }

  /**
   * Find or create an artist/band node by name.
   */
  protected function findOrCreateArtist($artist_name, array $data = []) {
    $node_storage = $this->entityTypeManager->getStorage('node');

    $existing_bands = $node_storage->loadByProperties([
      'type' => 'band',
      'title' => $artist_name,
    ]);
    if (!empty($existing_bands)) {
      $band_node = reset($existing_bands);
      return (int) $band_node->id();
    }

    $existing_artists = $node_storage->loadByProperties([
      'type' => 'artist',
      'title' => $artist_name,
    ]);

    if (!empty($existing_artists) && !empty($data['members']) && is_array($data['members'])) {
      /** @var \Drupal\node\NodeInterface $artist_node */
      $artist_node = reset($existing_artists);

      $band_nid = $this->convertArtistToBandAndDelete($artist_node, $data);
      return $band_nid ?? (int) $artist_node->id();
    }

    if (!empty($existing_artists)) {
      $artist_node = reset($existing_artists);
      return (int) $artist_node->id();
    }

    if (!empty($data['members']) && is_array($data['members'])) {
      $band = $this->createOrUpdateBand(['name' => $artist_name] + $data, TRUE);
      return $band ? (int) $band->id() : NULL;
    }

    try {
      $values = [
        'type' => 'artist',
        'title' => $artist_name,
        'status' => 1,
      ];

      if (!empty($data['artist_id'])) {
        $values['field_spotify_id'] = $data['artist_id'];
      }
      if (!empty($data['discogs_artist_id'])) {
        $values['field_discogs_id'] = $data['discogs_artist_id'];
      }

      $artist_node = $node_storage->create($values);
      $artist_node->save();

      return (int) $artist_node->id();
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
   */
  public function createOrUpdateArtist(array $data, $update_existing = TRUE) {
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');

      $name = $data['name'] ?? ($data['title'] ?? NULL);
      if (empty($name)) {
        $this->loggerFactory->get('music_search')->error('Artist create/update failed: missing name/title.');
        return NULL;
      }

      $existing = $node_storage->loadByProperties([
        'type' => 'artist',
        'title' => $name,
      ]);

      if (!empty($existing) && $update_existing) {
        $node = reset($existing);

        if (!empty($data['spotify_id'])) {
          $node->set('field_spotify_id', $data['spotify_id']);
        }
        if (!empty($data['discogs_id'])) {
          $node->set('field_discogs_id', $data['discogs_id']);
        }

        if (!empty($data['genres']) && is_array($data['genres'])) {
          $tids = $this->getOrCreateTerms($data['genres']);
          if (!empty($tids)) {
            $node->set('field_music_genre_artist', $tids);
          }
        }

        if (!empty($data['image'])) {
          $media_id = $this->createMediaFromUrl($data['image'], $name . ' Image');
          if ($media_id) {
            $node->set('field_artist_image', ['target_id' => $media_id]);
          }
        }

        if (!empty($data['profile']) || !empty($data['description'])) {
          $description = $data['profile'] ?? $data['description'];
          $node->set('field_artist_description_long', [
            'value' => $description,
            'format' => 'basic_html',
          ]);
        }

        if (!empty($data['spotify_url']) || !empty($data['discogs_url'])) {
          $url = $data['spotify_url'] ?? $data['discogs_url'];
          $node->set('field_website', [
            'uri' => $url,
            'title' => !empty($data['spotify_url']) ? 'Spotify' : 'Discogs',
          ]);
        }

        $node->save();
        return $node;
      }

      if (!empty($existing) && !$update_existing) {
        return NULL;
      }

      $values = [
        'type' => 'artist',
        'title' => $name,
        'status' => 1,
      ];

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
        $media_id = $this->createMediaFromUrl($data['image'], $name . ' Image');
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
          'title' => !empty($data['spotify_url']) ? 'Artists spotify page' : 'Artists discogs page',
        ];
      }

      $node = $node_storage->create($values);
      $node->save();
      return $node;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('music_search')->error('Error creating/updating artist: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Create or update band content.
   */
  public function createOrUpdateBand(array $data, $update_existing = TRUE) {
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');

      $title = $data['name'] ?? ($data['title'] ?? NULL);
      if (empty($title)) {
        $this->loggerFactory->get('music_search')->error('Band create/update failed: missing name/title.');
        return NULL;
      }

      $existing = $node_storage->loadByProperties([
        'type' => 'band',
        'title' => $title,
      ]);

      if (!empty($existing) && $update_existing) {
        $node = reset($existing);

        $this->setIfExists($node, 'field_spotify_id', $data['spotify_id'] ?? NULL);
        $this->setIfExists($node, 'field_discogs_id', $data['discogs_id'] ?? NULL);

        if (!empty($data['image'])) {
          $media_id = $this->createMediaFromUrl($data['image'], $title . ' Logo');
          if ($media_id) {
            $node->set('field_band_logo', ['target_id' => $media_id]);
          }
        }

        $description = $data['profile'] ?? ($data['description'] ?? '');
        if (!empty($description)) {
          $node->set('field_band_description', $description);
        }

        $url = $data['spotify_url'] ?? ($data['discogs_url'] ?? NULL);
        if (!empty($url)) {
          $node->set('field_website', [
            'uri' => $url,
            'title' => !empty($data['spotify_url']) ? 'Spotify' : 'Discogs',
          ]);
        }

        if (!empty($data['members']) && is_array($data['members'])) {
          $member_refs = [];

          foreach ($data['members'] as $member) {
            $member_name = NULL;
            if (is_string($member)) {
              $member_name = trim($member);
            }
            elseif (is_array($member) && !empty($member['name'])) {
              $member_name = trim($member['name']);
            }

            if (empty($member_name)) {
              continue;
            }

            $artist_nid = $this->findOrCreateArtist($member_name);
            if ($artist_nid) {
              $member_refs[] = ['target_id' => $artist_nid];
            }
          }

          if (!empty($member_refs)) {
            $node->set('field_band_members', $member_refs);
          }
        }

        $node->save();
        return $node;
      }

      if (!empty($existing) && !$update_existing) {
        return NULL;
      }

      $values = [
        'type' => 'band',
        'title' => $title,
        'status' => 1,
      ];

      if (!empty($data['image'])) {
        $media_id = $this->createMediaFromUrl($data['image'], $title . ' Logo');
        if ($media_id) {
          $values['field_band_logo'] = ['target_id' => $media_id];
        }
      }

      $description = $data['profile'] ?? ($data['description'] ?? '');
      if (!empty($description)) {
        $values['field_band_description'] = $description;
      }

      $url = $data['spotify_url'] ?? ($data['discogs_url'] ?? NULL);
      if (!empty($url)) {
        $values['field_website'] = [
          'uri' => $url,
          'title' => !empty($data['spotify_url']) ? 'Spotify' : 'Discogs',
        ];
      }

      if (!empty($data['members']) && is_array($data['members'])) {
        $member_refs = [];

        foreach ($data['members'] as $member) {
          $member_name = NULL;
          if (is_string($member)) {
            $member_name = trim($member);
          }
          elseif (is_array($member) && !empty($member['name'])) {
            $member_name = trim($member['name']);
          }

          if (empty($member_name)) {
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

      $node = $node_storage->create($values);

      $this->setIfExists($node, 'field_spotify_id', $data['spotify_id'] ?? NULL);
      $this->setIfExists($node, 'field_discogs_id', $data['discogs_id'] ?? NULL);

      $node->save();
      $this->adoptArtistSongsAndDeleteArtist($title, $node);
      return $node;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('music_search')->error('Error creating/updating band: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Safely set a field if it exists on the entity.
   */
  protected function setIfExists($entity, string $field, $value): void {
    if ($entity && $entity->hasField($field) && $value !== NULL && $value !== '') {
      $entity->set($field, $value);
    }
  }

  /**
   * Convert an existing Artist node into a Band and delete the Artist node.
   *
   * @return int|null
   *   Band nid or NULL.
   */
  protected function convertArtistToBandAndDelete(NodeInterface $artist_node, array $data): ?int {
    $title = $artist_node->label();

    $band = $this->createOrUpdateBand(['name' => $title] + $data, TRUE);
    if (!$band) {
      return NULL;
    }

    $band_nid = (int) $band->id();
    $artist_nid = (int) $artist_node->id();

    $this->rewireSongsFromArtistToBand($artist_nid, $band_nid);

    try {
      $artist_node->delete();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('music_search')->error(
        'Failed deleting artist nid=@nid during band conversion: @msg',
        ['@nid' => $artist_nid, '@msg' => $e->getMessage()]
      );
    }

    return $band_nid;
  }

  protected function resolveArtistOrBand(string $name, array $data = []): ?int {
    $storage = $this->entityTypeManager->getStorage('node');

    $bands = $storage->loadByProperties(['type' => 'band', 'title' => $name]);
    if (!empty($bands)) {
      return (int) reset($bands)->id();
    }

    $artists = $storage->loadByProperties(['type' => 'artist', 'title' => $name]);
    $artist_node = !empty($artists) ? reset($artists) : NULL;

    $data_says_band = !empty($data['members']) && is_array($data['members']);

    if ($data_says_band) {
      if ($artist_node) {
        $band_nid = $this->convertArtistToBandAndDelete($artist_node, $data);
        return $band_nid ?? (int) $artist_node->id();
      }
      $band = $this->createOrUpdateBand(['name' => $name] + $data, TRUE);
      return $band ? (int) $band->id() : NULL;
    }

    if ($artist_node && $this->discogsService) {
      try {
        $results = $this->discogsService->search($name, 'artist');

        if (!empty($results) && !empty($results[0]['id'])) {
          $discogs_id = $results[0]['id'];
          $details = $this->discogsService->getDetails($discogs_id, 'artist');

          if (!empty($details['members']) && is_array($details['members'])) {
            $details['name'] = $details['name'] ?? $name;

            $band_nid = $this->convertArtistToBandAndDelete($artist_node, $details);
            return $band_nid ?? (int) $artist_node->id();
          }
        }
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('music_search')->error(
          'Discogs band-detect failed for "@name": @msg',
          ['@name' => $name, '@msg' => $e->getMessage()]
        );
      }

      return (int) $artist_node->id();
    }

    if ($artist_node) {
      return (int) $artist_node->id();
    }

    try {
      $node = $storage->create([
        'type' => 'artist',
        'title' => $name,
        'status' => 1,
      ]);

      if (!empty($data['artist_id'])) {
        $this->setIfExists($node, 'field_spotify_id', $data['artist_id']);
      }
      if (!empty($data['discogs_artist_id'])) {
        $this->setIfExists($node, 'field_discogs_id', $data['discogs_artist_id']);
      }

      $node->save();
      return (int) $node->id();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('music_search')->error(
        'Error creating artist during resolve: @msg',
        ['@msg' => $e->getMessage()]
      );
      return NULL;
    }
  }

  /**
   * If an Artist node exists with the same title as this Band, delete the Artist.
   * (Band wins on name collisions.)
   */
  protected function deleteArtistIfBandExists(string $title): void {
    try {
      $storage = $this->entityTypeManager->getStorage('node');

      $artists = $storage->loadByProperties([
        'type' => 'artist',
        'title' => $title,
      ]);

      if (empty($artists)) {
        return;
      }

      /** @var \Drupal\node\NodeInterface $artist */
      $artist = reset($artists);

      $this->loggerFactory->get('music_search')->warning(
        'Deleting artist because band exists with same title. artist_nid=@nid title=@title',
        [
          '@nid' => $artist->id(),
          '@title' => $title,
        ]
      );

      $artist->delete();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('music_search')->error(
        'Failed deleting artist on band collision title="@title": @msg',
        ['@title' => $title, '@msg' => $e->getMessage()]
      );
    }
  }

  /**
   * Repoint all Song nodes that reference an Artist to instead reference a Band.
   */
  protected function rewireSongsFromArtistToBand(int $artist_nid, int $band_nid): void {
    try {
      $storage = $this->entityTypeManager->getStorage('node');

      $nids = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('type', 'song')
        ->condition('field_artist_song.target_id', $artist_nid)
        ->execute();

      if (empty($nids)) {
        $this->loggerFactory->get('music_search')->warning(
          'No songs found to rewire. artist_nid=@aid band_nid=@bid',
          ['@aid' => $artist_nid, '@bid' => $band_nid]
        );
        return;
      }

      $songs = $storage->loadMultiple($nids);

      foreach ($songs as $song) {
        $song->set('field_artist_song', $this->buildNodeReferenceValue('field_artist_song', $band_nid));
        $song->save();
      }

      $this->loggerFactory->get('music_search')->warning(
        'Rewired @count song(s) from artist_nid=@aid to band_nid=@bid',
        ['@count' => count($songs), '@aid' => $artist_nid, '@bid' => $band_nid]
      );
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('music_search')->error(
        'Failed rewiring songs from artist_nid=@aid to band_nid=@bid: @msg',
        ['@aid' => $artist_nid, '@bid' => $band_nid, '@msg' => $e->getMessage()]
      );
    }
  }

  /**
   * If an Artist exists with the same title as this Band:
   * - rewire Song references from Artist -> Band
   * - delete the old Artist
   */
  protected function adoptArtistSongsAndDeleteArtist(string $title, NodeInterface $band): void {
    try {
      $storage = $this->entityTypeManager->getStorage('node');

      $artists = $storage->loadByProperties([
        'type' => 'artist',
        'title' => $title,
      ]);

      if (empty($artists)) {
        return;
      }

      /** @var \Drupal\node\NodeInterface $artist */
      $artist = reset($artists);

      $this->rewireSongsFromArtistToBand((int) $artist->id(), (int) $band->id());

      $this->loggerFactory->get('music_search')->warning(
        'Band created/updated; deleting artist with same title after rewiring. artist_nid=@aid band_nid=@bid title=@title',
        ['@aid' => $artist->id(), '@bid' => $band->id(), '@title' => $title]
      );

      $artist->delete();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('music_search')->error(
        'Failed adopting artist songs / deleting artist for title="@title": @msg',
        ['@title' => $title, '@msg' => $e->getMessage()]
      );
    }
  }

}
