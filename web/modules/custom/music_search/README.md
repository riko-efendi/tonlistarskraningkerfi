# Music Search

Music Search is a custom Drupal module that allows users to search for music data
(artists, albums, songs), compare results from multiple providers, and create
Drupal content from the selected data.

## Features
- Search music data from external providers (Spotify and Discogs)
- Compare results side-by-side
- Select which fields to use when creating content
- Create Artist, Album, Band, or Song nodes

## Requirements
- Drupal 11
- Core modules:
  - Node
  - Field
  - Media
  - Media Library
- Contributed modules:
  - Duration Field (`duration_field`)

## Installation

1. Place the module in:
   ```
   modules/custom/music_search
   ```

2. Install required dependencies:
   ```bash
   ddev composer require drupal/duration_field
   ```

3. Enable required core modules:
   ```bash
   ddev drush en media media_library file image -y
   ```

4. Enable the module and provider modules:
   ```bash
   ddev drush en music_search spotify_lookup discogs_lookup -y
   ```

5. Configure API keys:
   ```bash
   ddev drush cset music_search.settings discogs_client_id "YOUR_DISCOGS_CLIENT_ID"
   ddev drush cset music_search.settings discogs_client_secret "YOUR_DISCOGS_CLIENT_SECRET"

   ddev drush cset music_search.settings spotify_client_id "YOUR_SPOTIFY_CLIENT_ID"
   ddev drush cset music_search.settings spotify_client_secret "YOUR_SPOTIFY_CLIENT_SECRET"
   ```

## Setup

Run the setup command to create all required content types and fields:
```bash
ddev drush music-search:setup
```

This is safe to run multiple times.

Fields will appear under **Disabled** in *Manage form display* and
*Manage display*

## Usage
- User must be logged in as an administrator
- go to:
  ```
  admin/content/music-search
  ```
- Search for an artist, album, or song
- Select results from different providers
- Compare values and create content
