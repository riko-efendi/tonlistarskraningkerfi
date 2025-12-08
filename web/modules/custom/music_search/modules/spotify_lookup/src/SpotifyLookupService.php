<?php

namespace Drupal\spotify_lookup;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/*
 * Service for Spotify API lookups.
 */

Class SpotifyLookupService {

    /**
     * Spotify API base URL.
    */
    const API_BASE = 'https://api.spotify.com/v1/';

    /**
     * Spotify Auth URL.
    */
    const AUTH_URL = 'https://accounts.spotify.com/api/token';

    /**
     * The config factory.
     *
     * @var \Drupal\Core\Config\ConfigFactoryInterface
    */
    protected $configFactory;

    /**
     * The HTTP client.
    */
    protected $httpClient;

    /**
     * The config factory.
     *
     * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
    */
    protected $loggerFactory;

    /**
     * Cached access token
     *
     * @var string|null
    */
    protected $accessToken;

    public function __construct(
        ConfigFactoryInterface $config_factory,
        ClientInterface $http_client,
        LoggerChannelFactoryInterface $logger_factory,
    ) {
        $this->configFactory = $config_factory;
        $this->httpClient = $http_client;
        $this->loggerFactory = $logger_factory;
    }

    /**
     * Get Spotify access token.
     *
     * @return string|null
     *      the access token or NULL.
    */

    protected function getAccessToken(): string | NULL {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $config = $this->configFactory->get('music_search.settings');
        $client_id = $config->get('spotify_client_id');
        $client_secret = $config->get('spotify_client_secret');

        if (empty($client_id) || empty($client_secret)) {
            $this->loggerFactory->get('spotify_lookup')->error('No Spotify Credentials Configured');
            return NULL;
        }

        try {
            $response = $this->httpClient->post(self::AUTH_URL, [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                ],
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
                ],
            ]);

            $data = json_decode($response->getBody(), TRUE);
            $this->accessToken = $data['access_token'] ?? NULL;

            return $this->accessToken;
        }
        catch (RequestException $e) {
            $this->loggerFactory->get('spotify_lookup')->error('Spotify auth error: @message', ['@message' => $e->getMessage()]);
            return NULL;
        }
    }

    /**
     * Search Spotify.
     *
     * @param string $query
     *  The search query.
     * @param string $type
     *      The type: artist, album or track.
     *  @return array
     *     Search results
    */

    public function search($query, $type): array {
         $token = $this->getAccessToken();
         if (!$token) {
             return [];
         }

         $spotify_type = $type === 'song' ? 'track' : $type;

         try {
             $response = $this->httpClient->get(self::API_BASE . $spotify_type . '/search', [
                'query' => [
                    'q' => $query,
                    'type' => $spotify_type,
                    'limit' => 10,
                ],
                 'headers' => [
                     'Authorization' => 'Bearer ' . $token,
                 ],
             ]);

             $data = json_decode($response->getBody(), TRUE);

             return $this->formatResults($data, $type);
         }
         catch (RequestException $e) {
             $this->loggerFactory->get('spotify_lookup')->error('Spotify auth error: @message', ['@message' => $e->getMessage()]);
             return [];
         }
    }

    /**
     * Get details for a specific item.
     *
     * @param string $id
     *     The spotify id.
     * @param string $type
     *      The type: artist, album or track.
     *
     * @return array|null
     *     Item details or NULL.
    */

    public function getDetails($id, $type) {
        $token = $this->getAccessToken();
        if (!$token) {
            return NULL;
        }

        $spotify_type = $type === 'song' ? 'track' : $type;
        $endpoint = self::API_BASE . $spotify_type . '/' . $id;

        try {
            $response = $this->httpClient->get($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);

            $data = json_decode($response->getBody(), TRUE);

            return $this->formatDetails($data, $type);
        }
        catch (RequestException $e) {
            $this->loggerFactory->get('spotify_lookup')->error('Spotify auth error: @message', ['@message' => $e->getMessage()]);
            return NULL;
        }
    }

    /**
     * Format search results.
    */
    protected function formatResults($data, $type) {
        $results = [];

        if ($type === 'artist' && isset($data['artists']['items'])) {
            foreach ($data['artists']['items'] as $item) {
                $results[] = [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'image' => $item['images'][0]['url'] ?? NULL,
                    'genres' => $item['genres'] ?? NULL,
                    'provider' => 'spotify',
                ];
            }
        }
        elseif ($type === 'album' && isset($data['albums']['items'])) {
            foreach ($data['albums']['items'] as $item) {
                $results[] = [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'artist' => $item['artist'],
                    'artist_id' => $item['artist'][0]['url'] ?? NULL,
                    'image' => $item['images'][0]['url'] ?? NULL,
                    'release_date' => $item['release_date'] ?? NULL,
                    'year' => substr($item['release_date'] ?? '', 0, 4),
                    'total_tracks' => $item['total_tracks'] ?? 0,
                    'provider' => 'spotify',
                ];
            }
        }
        elseif ($type === 'song' && isset($data['tracks']['items'])) {
            foreach ($data['tracks']['items'] as $item) {
                $duration_ms = $item['duration'] ?? 0;
                $minutes = floor($duration_ms / 60000);
                $seconds = floor(($duration_ms % 60000) / 1000);

                $results[] = [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'artist' => $item['artists'][0]['name'] ?? 'Unknown',
                    'artist_id' => $item['artists'][0]['id'] ?? NULL,
                    'album' => $item['albums']['name'] ?? NULL,
                    'album_id' => $item['albums']['id'] ?? NULL,
                    'length' => sprintf('%d:%02d', $minutes, $seconds),
                    'duration_ms' => $duration_ms,
                    'provider' => 'spotify',
                ];
            }
        }

        return $results;
    }

    /**
     *   Format detailed information
     */
    protected function formatDetails($data, $type) {
        if ($type === 'artist') {
            return [
                'id' => $data['id'],
                'name' => $data['name'],
                'image' => $data['images'][0]['url'] ?? NULL,
                'genres' => $data['genres'] ?? [],
                'spotify_url' => $data['external_urls']['spotify'] ?? NULL,
                'provider' => 'spotify',
            ];
        }
        elseif ($type === 'album') {
            return [
                'id' => $data['id'],
                'name' => $data['name'],
                'artist' => $data['artists'][0]['name'] ?? 'Unknown',
                'artist_id' => $data['artists'][0]['id'] ?? NULL,
                'image' => $data['images'][0]['url'] ?? NULL,
                'release_date' => $data['release_date'] ?? NULL,
                'year' => substr($data['release_date'] ?? '', 0, 4),
                'total_tracks' => $data['total_tracks'] ?? 0,
                'genres' => $data['genres'] ?? [],
                'label' => $data['label'] ?? NULL,
                'spotify_url' => $data['external_urls']['spotify'] ?? NULL,
                'tracks' => $this->formatTracks($data['tracks']['items'] ?? []),
                'provider' => 'spotify',
            ];
        }
        elseif ($type === 'song') {
            $duration_ms = $data['duration_ms'] ?? 0;
            $minutes = floor($duration_ms / 60000);
            $seconds = floor(($duration_ms % 60000) / 1000);

            return [
                'id' => $data['id'],
                'name' => $data['name'],
                'artist' => $data['artists'][0]['name'] ?? 'Unknown',
                'artist_id' => $data['artists'][0]['id'] ?? NULL,
                'album' => $data['albums']['name'] ?? NULL,
                'album_id' => $data['albums']['id'] ?? NULL,
                'length' => sprintf('%d:%02d', $minutes, $seconds),
                'duration_ms' => $duration_ms,
                'track_number' => $data['track_number'] ?? NULL,
                'spotify_url' => $data['external_urls']['spotify'] ?? NULL,
                'provider' => 'spotify',
            ];
        }

        return $data;
    }

    /**
     * Format track list
     */
    protected function formatTracks($tacks) {
        $formatted = [];

        foreach ($tacks as $track) {
            $duration_ms = $track['duration_ms'] ?? 0;
            $minutes = floor($duration_ms / 60000);
            $seconds = floor(($duration_ms % 60000) / 1000);

            $formatted[] = [
                'id' => $track['id'],
                'name' => $track['name'],
                'track_number' => $track['track_number'] ?? NULL,
                'length' => sprintf('%d:%02d', $minutes, $seconds),
            ];
        }

        return $formatted;
    }

}
