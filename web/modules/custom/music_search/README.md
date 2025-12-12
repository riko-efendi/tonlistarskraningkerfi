# Music Search

Music Search is a custom Drupal module that allows users to search for music data
(artists, albums, songs), compare results from multiple providers and create
Drupal content from the selected data.

## Features
- Search music data from external providers (Spotify and Discogs)
- Compare results side-by-side
- Select which fields to use when creating content
- Create Artist, Album, or Song nodes

## Requirements
- Drupal 11
- Core modules:
  - Node
  - Field
- Contributed modules:
  - Duration Field (`duration_field`)

## Installation
1. Place the module in:
   modules/custom

2. Install required dependencies:
   ```bash
   ddev composer require drupal/duration_field
   ```

3. Enable the module:
    ```bash
    ddev drush en music_search spotify_lookup discogs_lookup -y
    ```

4. Place api keys and secrets in readable location
    ```bash
    ddev drush cset music_search.settings discogs_client_id "YOUR_DISCOGS_CLIENT_ID"
    ddev drush cset music_search.settings discogs_client_secret "YOUR_DISCOGS_CLIENT_SECRET"

    ddev drush cset music_search.settings spotify_client_id "YOUR_SPOTIFY_CLIENT_ID"
    ddev drush cset music_search.settings spotify_client_secret "YOUR_SPOTIFY_CLIENT_SECRET"
    ```

## Preparation
We have made a command to create all content types for ease of use.
To run the command please do:
  ```bash
  ddev drush cr
  ddev drush music-search:create-fields
  ```

## Usage
- User must be logged in as admin
- Go to the Music Search page
  ```bash
  admin/content/music-search
  ```
- Search for an artist, album, or song
- Select a result from a provider
- search for another result from the other provider and compare values
- Create content based on the selected data
