<?php

namespace Drupal\media_entity_spotify\Plugin\media\Source;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media\Attribute\MediaSource;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceBase;
use Drupal\media\MediaTypeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides media source plugin for Spotify.
 */
#[MediaSource(
  id: "spotify",
  label: new TranslatableMarkup("Spotify"),
  description: new TranslatableMarkup("Provides business logic and metadata for Spotify."),
  allowed_field_types: ["string", "string_long", "link"],
  default_thumbnail_filename: "spotify.png",
)]
class Spotify extends MediaSourceBase {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Cached Spotify oEmbed data keyed by URL.
   *
   * @var array
   */
  protected array $spotify = [];

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    FieldTypePluginManagerInterface $field_type_manager,
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    FileSystemInterface $file_system,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $entity_field_manager, $field_type_manager, $config_factory);
    $this->httpClient = $http_client;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.field_type'),
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('file_system'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'source_field' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['source_field']['#title'] = $this->t('Spotify URL source field');
    $form['source_field']['#description'] = $this->t('Select the field on the media entity that stores the Spotify URL.');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadataAttributes(): array {
    return [
      'uri' => $this->t('Spotify URI'),
      'html' => $this->t('HTML embed code'),
      'thumbnail_uri' => $this->t('Thumbnail URI'),
      'type' => $this->t('Content type (track, album, or playlist)'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(MediaInterface $media, $attribute_name) {
    $url = $this->getSourceFieldValue($media);

    if (empty($url)) {
      return parent::getMetadata($media, $attribute_name);
    }

    switch ($attribute_name) {
      case 'default_name':
        if ($oembed_data = $this->oEmbed($url)) {
          return $oembed_data['title'] ?? parent::getMetadata($media, 'default_name');
        }
        return parent::getMetadata($media, 'default_name');

      case 'thumbnail_uri':
        return $this->getLocalThumbnailUri($url) ?: parent::getMetadata($media, 'thumbnail_uri');

      case 'uri':
        return $this->getSpotifyUri($url);

      case 'html':
        if ($oembed_data = $this->oEmbed($url)) {
          return $oembed_data['html'] ?? NULL;
        }
        return NULL;

      case 'type':
        $uri = $this->getSpotifyUri($url);
        if (preg_match('/^spotify:track:/', $uri)) {
          return 'track';
        }
        if (preg_match('/^spotify:album:/', $uri)) {
          return 'album';
        }
        return 'playlist';

      default:
        return parent::getMetadata($media, $attribute_name);
    }
  }

  /**
   * Converts a Spotify URL to a Spotify URI.
   *
   * @param string $url
   *   The Spotify URL.
   *
   * @return string|null
   *   The Spotify URI or NULL.
   */
  protected function getSpotifyUri(string $url): ?string {
    // Test for track.
    if (preg_match('/^https?:\/\/(?:open|play)\.spotify\.com\/track\/([\w\d]+)(?:\?.*)?$/i', $url, $matches)) {
      return 'spotify:track:' . $matches[1];
    }

    // Test for playlist (new format without user).
    if (preg_match('/^https?:\/\/(?:open|play)\.spotify\.com\/playlist\/([\w\d]+)(?:\?.*)?$/i', $url, $matches)) {
      return 'spotify:playlist:' . $matches[1];
    }

    // Test for playlist (old format with user).
    if (preg_match('/^https?:\/\/(?:open|play)\.spotify\.com\/user\/([\w\d]+)\/playlist\/([\w\d]+)(?:\?.*)?$/i', $url, $matches)) {
      return 'spotify:user:' . $matches[1] . ':playlist:' . $matches[2];
    }

    // Test for album.
    if (preg_match('/^https?:\/\/(?:open|play)\.spotify\.com\/album\/([\w\d]+)(?:\?.*)?$/i', $url, $matches)) {
      return 'spotify:album:' . $matches[1];
    }

    // Test for artist.
    if (preg_match('/^https?:\/\/(?:open|play)\.spotify\.com\/artist\/([\w\d]+)(?:\?.*)?$/i', $url, $matches)) {
      return 'spotify:artist:' . $matches[1];
    }

    // Test for show (podcast).
    if (preg_match('/^https?:\/\/(?:open|play)\.spotify\.com\/show\/([\w\d]+)(?:\?.*)?$/i', $url, $matches)) {
      return 'spotify:show:' . $matches[1];
    }

    // Test for episode.
    if (preg_match('/^https?:\/\/(?:open|play)\.spotify\.com\/episode\/([\w\d]+)(?:\?.*)?$/i', $url, $matches)) {
      return 'spotify:episode:' . $matches[1];
    }

    return NULL;
  }

  /**
   * Returns the local URI for a thumbnail.
   *
   * @param string $url
   *   The Spotify URL.
   *
   * @return string|null
   *   The local thumbnail URI or NULL.
   */
  protected function getLocalThumbnailUri(string $url): ?string {
    $oembed_data = $this->oEmbed($url);

    if (empty($oembed_data['thumbnail_url'])) {
      return NULL;
    }

    $thumbnail_url = $oembed_data['thumbnail_url'];

    // Parse the URL to extract the filename properly.
    $parsed_url = parse_url($thumbnail_url);
    $path_parts = pathinfo($parsed_url['path'] ?? '');
    $filename = $path_parts['filename'] ?? 'thumbnail';
    $extension = $path_parts['extension'] ?? 'jpg';

    // Create a unique filename with proper extension.
    $local_filename = $filename . '.' . $extension;

    $destination = $this->configFactory->get('media_entity_spotify.settings')->get('thumbnail_destination') ?? 'public://spotify';
    $local_uri = $destination . '/' . $local_filename;

    // Prepare the directory.
    $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // Check if file exists using realpath for stream wrapper support.
    $real_path = $this->fileSystem->realpath($local_uri);
    if ($real_path && file_exists($real_path)) {
      return $local_uri;
    }

    // Download and save the thumbnail.
    try {
      $image = file_get_contents($thumbnail_url);
      if ($image) {
        $saved_uri = $this->fileSystem->saveData($image, $local_uri, FileSystemInterface::EXISTS_REPLACE);
        if ($saved_uri) {
          return $saved_uri;
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('media_entity_spotify')->error('Failed to download thumbnail from @url: @error', [
        '@url' => $thumbnail_url,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }

    return NULL;
  }

  /**
   * Returns oEmbed data for a Spotify URL.
   *
   * @param string $url
   *   The Spotify URL.
   *
   * @return array|null
   *   An array of oEmbed data or NULL.
   */
  protected function oEmbed(string $url): ?array {
    // Return cached data if available for this URL.
    if (isset($this->spotify[$url])) {
      return $this->spotify[$url];
    }

    try {
      $oembed_url = 'https://embed.spotify.com/oembed/?url=' . urlencode($url);
      $response = $this->httpClient->get($oembed_url);
      $data = json_decode((string) $response->getBody(), TRUE);

      // Cache the data for this specific URL.
      $this->spotify[$url] = $data;

      return $data;
    }
    catch (GuzzleException $e) {
      \Drupal::logger('media_entity_spotify')->error('Failed to fetch oEmbed data from Spotify: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
