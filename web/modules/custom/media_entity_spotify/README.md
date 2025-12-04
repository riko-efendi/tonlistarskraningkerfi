[![CI](https://github.com/drupalviking/media_entity_spotify/workflows/CI/badge.svg)](https://github.com/drupalviking/media_entity_spotify/actions)

# Media Entity Spotify

Provides **Spotify** media source for Drupal core's Media module, enabling you to embed Spotify tracks, playlists, albums, artists, shows (podcasts), and episodes.

## Features

- ✅ **Drupal 11 Ready** - Fully refactored for Drupal 11 with PHP 8 attributes
- ✅ **Core Media Integration** - Works with Drupal core's Media module (no contrib dependencies!)
- ✅ **Multiple Content Types** - Supports tracks, playlists, albums, artists, shows, and episodes
- ✅ **Auto Thumbnails** - Automatically downloads and caches thumbnails from Spotify
- ✅ **Configurable Player** - Customizable embed player (theme, view, size)
- ✅ **Modern URLs** - Supports both old and new Spotify URL formats

## Requirements

- **Drupal:** 11.x
- **PHP:** 8.1 or higher
- **Media module:** Core (included in Drupal)

## Installation

### Via Composer (Recommended)

```bash
composer require drupal/media_entity_spotify
drush en media_entity_spotify
```

### Manual Installation

1. Download and extract the module to `web/modules/custom/media_entity_spotify`
2. Enable the module:
   ```bash
   drush en media_entity_spotify
   ```

## Configuration

### 1. Create a Media Type

1. Navigate to **Structure** → **Media types** → **Add media type**
   (`/admin/structure/media/add`)
2. Enter a name (e.g., "Spotify")
3. Under **Media source**, select **"Spotify"**
4. Click **Save**

### 2. Configure Source Field

1. The media type will be created with a default source field
2. Edit the media type if needed
3. Under **Type configuration**, select the field that will store Spotify URLs
   - Supported field types: String, String (long), or Link
4. Save the configuration

### 3. Configure Display

1. Go to **Manage display** for your Spotify media type
2. For the source field, select **"Spotify embed"** as the formatter
3. Click the **settings icon** (⚙️) to configure:
   - **Playlist/Album settings:**
     - Theme: Dark or Light
     - View: List or Cover Art
     - Width and Height
   - **Track settings:**
     - Theme: Dark or Light
     - View: List or Cover Art
     - Width and Height (auto-adjusted for tracks)
4. Save the display configuration

## Usage

### Supported Spotify URLs

The module supports all modern Spotify URL formats:

| Content Type | URL Example |
|-------------|-------------|
| **Track** | `https://open.spotify.com/track/4tozzHVS3vnc8xks2PekDr` |
| **Playlist** | `https://open.spotify.com/playlist/0bXZMCsiOoESIuMBXU45gX` |
| **Playlist (old)** | `https://open.spotify.com/user/{user}/playlist/{id}` |
| **Album** | `https://open.spotify.com/album/{album_id}` |
| **Artist** | `https://open.spotify.com/artist/{artist_id}` |
| **Show/Podcast** | `https://open.spotify.com/show/{show_id}` |
| **Episode** | `https://open.spotify.com/episode/{episode_id}` |

URLs with query parameters (e.g., `?si=xxx`) are automatically handled.

### Creating Media Items

1. Go to **Content** → **Media** → **Add media**
2. Select your Spotify media type
3. Paste a Spotify URL into the source field
4. The title and thumbnail will be automatically fetched from Spotify
5. Save the media item

## Configuration Options

### Thumbnail Storage

By default, thumbnails are saved to `public://spotify`. You can change this in:

```yaml
# config/install/media_entity_spotify.settings.yml
thumbnail_destination: 'public://spotify'
```

### Player Settings

Configure the embedded player via the field formatter settings:

- **Theme:** Dark (default) or Light
- **View:** List or Cover Art
- **Dimensions:** Custom width and height for playlists and tracks

## Technical Details

### Architecture

This module has been **fully refactored for Drupal 11**:

- **Media Source Plugin** - Uses core's `MediaSourceBase` instead of contrib `MediaTypeBase`
- **PHP 8 Attributes** - Modern attribute syntax instead of annotations
- **Typed Properties** - Strict PHP 8.1+ type declarations
- **Stream Wrapper Support** - Proper file handling with Drupal's file system
- **Cached oEmbed Data** - URL-keyed caching for optimal performance

### Key Classes

- **MediaSource:** `Drupal\media_entity_spotify\Plugin\media\Source\Spotify`
- **Field Formatter:** `Drupal\media_entity_spotify\Plugin\Field\FieldFormatter\SpotifyEmbedFormatter`
- **Theme:** `media_spotify_embed` (template: `media-spotify-embed.html.twig`)

### Metadata Fields

The media source provides these metadata attributes:

- `uri` - Spotify URI (e.g., `spotify:track:{id}`)
- `html` - Embed HTML code from oEmbed
- `thumbnail_uri` - Local thumbnail file path
- `type` - Content type (track, album, playlist)
- `default_name` - Title from Spotify oEmbed data

## Troubleshooting

### Thumbnails Not Displaying

If thumbnails aren't showing:

1. Clear Drupal cache:
   ```bash
   drush cr
   ```

2. Regenerate thumbnails by resaving media items:
   ```bash
   drush php:eval "foreach (\Drupal::entityTypeManager()->getStorage('media')->loadByProperties(['bundle' => 'spotify']) as \$media) { \$media->save(); }"
   ```

3. Check file permissions on `sites/default/files/spotify/`

### Playlists Not Rendering

Ensure you're using the correct URL format. Both formats are supported:
- ✅ New: `https://open.spotify.com/playlist/{id}`
- ✅ Old: `https://open.spotify.com/user/{user}/playlist/{id}`

## Development

### Running Tests

The module uses GitHub Actions for CI/CD. Tests run automatically on push/PR.

To run tests locally:

```bash
cd /path/to/drupal
vendor/bin/phpunit -c core modules/contrib/media_entity_spotify
```

### Coding Standards

Check code quality:

```bash
phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/media_entity_spotify
```

### Checking for Deprecated Code

```bash
drupal-check web/modules/custom/media_entity_spotify
```

## Changelog

### 2.x (Drupal 11 - Current)

- ✅ **Complete refactor for Drupal 11**
- ✅ Converted to PHP 8 attributes from annotations
- ✅ Updated to use core Media module (removed media_entity dependency)
- ✅ Fixed thumbnail handling bugs
- ✅ Added support for modern Spotify URL formats
- ✅ Added support for artists, shows, and episodes
- ✅ Improved error handling and logging
- ✅ Replaced Travis CI with GitHub Actions
- ✅ Fixed critical oEmbed caching bug

### 1.x (Drupal 8 - Legacy)

- Legacy version using contrib media_entity module
- PHP 5.6/7.x support
- Annotations-based plugins

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Ensure tests pass (`GitHub Actions will run automatically`)
5. Submit a pull request

## Credits

**Original Author:** [Original maintainer name]
**Drupal 11 Refactoring:** Completed December 2025

## License

GPL-2.0-or-later

http://www.gnu.org/licenses/old-licenses/gpl-2.0.html

## Support

- **Issue Queue:** [GitHub Issues](https://github.com/drupalviking/media_entity_spotify/issues)
- **Documentation:** See `/media_entity_spotify_summary.md` for detailed refactoring notes

---

**Note:** This module was originally created for Drupal 8's contrib `media_entity` module and has been completely refactored to work with Drupal core's Media module for Drupal 11.
