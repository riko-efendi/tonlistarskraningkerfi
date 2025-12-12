<?php

namespace Drupal\music_search\Commands;

use Drush\Commands\DrushCommands;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;

/**
 * Drush commands for Music Search.
 */
class MusicSearchCommands extends DrushCommands
{

  /**
   * Create ONLY the required fields for Album, Artist, Band, Song.
   *
   * @command music-search:create-fields
   * @aliases ms-fields
   */
  public function createFields(): void
  {
    $bundles = ['album', 'artist', 'band', 'song'];

    foreach ($bundles as $bundle) {
      if (!NodeType::load($bundle)) {
        $this->logger()->warning("Content type '{$bundle}' does not exist. Skipping its fields.");
      }
    }

    // ---------- ALBUM ----------
    $this->createMediaReference('album', 'field_album_cover', 'Album Cover', ['image']);
    $this->createTextLong('album', 'field_album_description', 'Album Description');
    $this->createNodeReference('album', 'field_album_publisher', 'Album Publisher', ['publisher'], 1);
    $this->createNodeReference('album', 'field_artist', 'Artist', ['artist', 'band'], 1);
    $this->createString('album', 'field_discogs_id', 'discogs_id');
    $this->createBoolean('album', 'field_highlighted_album', 'Highlighted');
    $this->createDate('album', 'field_release_year', 'Release Year');
    $this->createNodeReference('album', 'field_album_song', 'Song(s)', ['song'], -1);
    $this->createMediaReference('album', 'field_spotify_album', 'Spotify Album', ['spotify']);
    $this->createString('album', 'field_spotify_id', 'spotify_id');

    // ---------- ARTIST ----------
    $this->createNodeReference('artist', 'field_artist_album', 'Album(s)', ['album'], -1);
    $this->createTextLong('artist', 'field_artist_description_long', 'Artist Description');
    $this->createMediaReference('artist', 'field_artist_image', 'Artist Image', ['image']);
    $this->createDate('artist', 'field_date_of_birth', 'Born');
    $this->createDate('artist', 'field_date_of_death', 'Died');
    $this->createString('artist', 'field_discogs_id', 'discogs_id');
    $this->createTaxonomyReference('artist', 'field_music_genre_artist', 'Music Genre', 'music_genre', 1);
    $this->createString('artist', 'field_spotify_id', 'spotify_id');
    $this->createLink('artist', 'field_website', 'Website');

    // ---------- BAND ----------
    $this->createNodeReference('band', 'field_band_albums', 'Album(s)', ['album'], -1);
    $this->createTextLong('band', 'field_band_description', 'Band Description');
    $this->createMediaReference('band', 'field_band_logo', 'Band Logo', ['image']);
    $this->createDate('band', 'field_date_of_disbanded', 'Date of Disbanded');
    $this->createDate('band', 'field_date_of_founding', 'Date of Founding');
    $this->createString('band', 'field_discogs_id', 'discogs_id');
    $this->createNodeReference('band', 'field_band_members', 'Members', ['artist'], -1);
    $this->createString('band', 'field_spotify_id', 'spotify_id');
    $this->createLink('band', 'field_website', 'Website');

    // ---------- SONG ----------
    $this->createNodeReference('song', 'field_artist_song', 'Artist', ['artist', 'band'], 1);
    $this->createString('song', 'field_discogs_id', 'discogs_id');
    $this->createTaxonomyReference('song', 'field_music_genre', 'Genre', 'music_genre', 1);
    $this->createDuration('song', 'field_song_duration', 'Length');
    $this->createMediaReference('song', 'field_spotify', 'Spotify', ['spotify']);
    $this->createString('song', 'field_spotify_id', 'spotify_id');

    $this->logger()->success('Field creation finished (existing fields were skipped).');
  }

  private function bundleExists(string $bundle): bool
  {
    return (bool)NodeType::load($bundle);
  }

  private function ensureStorage(string $entity_type, string $field_name, string $type, array $settings = [], int $cardinality = 1): void
  {
    if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => $type,
        'settings' => $settings,
        'cardinality' => $cardinality,
      ])->save();
    }
  }

  private function ensureInstance(string $entity_type, string $bundle, string $field_name, string $label, array $settings = []): void
  {
    if (FieldConfig::loadByName($entity_type, $bundle, $field_name)) {
      return;
    }
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $label,
      'settings' => $settings,
    ])->save();
  }

  private function createString(string $bundle, string $field_name, string $label): void
  {
    if (!$this->bundleExists($bundle)) return;
    $this->ensureStorage('node', $field_name, 'string');
    $this->ensureInstance('node', $bundle, $field_name, $label);
  }

  private function createTextLong(string $bundle, string $field_name, string $label): void
  {
    if (!$this->bundleExists($bundle)) return;
    $this->ensureStorage('node', $field_name, 'text_long');
    $this->ensureInstance('node', $bundle, $field_name, $label);
  }

  private function createBoolean(string $bundle, string $field_name, string $label): void
  {
    if (!$this->bundleExists($bundle)) return;
    $this->ensureStorage('node', $field_name, 'boolean');
    $this->ensureInstance('node', $bundle, $field_name, $label);
  }

  private function createDate(string $bundle, string $field_name, string $label): void
  {
    if (!$this->bundleExists($bundle)) return;
    $this->ensureStorage('node', $field_name, 'datetime', ['datetime_type' => 'date']);
    $this->ensureInstance('node', $bundle, $field_name, $label, ['datetime_type' => 'date']);
  }

  private function createLink(string $bundle, string $field_name, string $label): void
  {
    if (!$this->bundleExists($bundle)) return;
    $this->ensureStorage('node', $field_name, 'link');
    $this->ensureInstance('node', $bundle, $field_name, $label);
  }

  private function createDuration(string $bundle, string $field_name, string $label): void
  {
    if (!$this->bundleExists($bundle)) return;
    $this->ensureStorage('node', $field_name, 'duration');
    $this->ensureInstance('node', $bundle, $field_name, $label);
  }

  private function createNodeReference(string $bundle, string $field_name, string $label, array $target_bundles, int $cardinality = 1): void
  {
    if (!$this->bundleExists($bundle)) return;

    $this->ensureStorage('node', $field_name, 'entity_reference', [
      'target_type' => 'node',
    ], $cardinality);

    $this->ensureInstance('node', $bundle, $field_name, $label, [
      'handler' => 'default:node',
      'handler_settings' => [
        'target_bundles' => array_combine($target_bundles, $target_bundles),
      ],
    ]);
  }

  private function createMediaReference(string $bundle, string $field_name, string $label, array $media_bundles, int $cardinality = 1): void
  {
    if (!$this->bundleExists($bundle)) return;

    $this->ensureStorage('node', $field_name, 'entity_reference', [
      'target_type' => 'media',
    ], $cardinality);

    $this->ensureInstance('node', $bundle, $field_name, $label, [
      'handler' => 'default:media',
      'handler_settings' => [
        'target_bundles' => array_combine($media_bundles, $media_bundles),
      ],
    ]);
  }

  private function createTaxonomyReference(string $bundle, string $field_name, string $label, string $vocabulary, int $cardinality = 1): void
  {
    if (!$this->bundleExists($bundle)) return;

    $this->ensureStorage('node', $field_name, 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ], $cardinality);

    $this->ensureInstance('node', $bundle, $field_name, $label, [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => [
        'target_bundles' => [$vocabulary => $vocabulary],
      ],
    ]);
  }

}
