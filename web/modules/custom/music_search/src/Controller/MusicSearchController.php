<?php

namespace Drupal\music_search\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\music_search\MusicSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
  * Controller for music search API endpoints.
 */
class MusicSearchController extends ControllerBase {

    /**
     * The music search service.
     *
     * @var \Drupal\music_search\MusicSearchService
     */
    protected $musicSearchService;

    /**
      * Contructor.
     *
     * @param \Drupal\music_search\MusicSearchService $music_Search_Service
     */
    public function __construct(MusicSearchService $music_Search_Service) {
        $this->musicSearchService = $music_Search_Service;
    }

    /**
      * {@inheritdoc }
     */
    public static function create(ContainerInterface $container) {
        return new static (
            $container->get('music_search.music_search')
        );
    }

    /**
     * API endpoint for searching music.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *     The request object
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *      JSON response with search results
     */
    public function searchApi(Request $request) {
        $query = $request->query->get('q');
        $type = $request->query->get('type', 'artist');

        if (empty($query)) {
            return new JsonResponse([
                'error' => 'Query parameter "q" is required',
                'usage' => '/music-search/api/search?q=artist_name&type=artist',
            ], 400);
        }

        $valid_types = ['artist', 'album', 'song'];
        if (!in_array($type, $valid_types)) {
            return new JsonResponse([
                'error' => 'Invalid type. Must be one of: artist, album, song',
                'provided' => $type,
            ], 400);
        }

        try {
            $results = $this->musicSearchService->searchAll($query, $type);

            $total = 0;
            foreach ($results as $provider_results) {
                $total += count($provider_results);
            }

            return new JsonResponse([
                'query' => $query,
                'type' => $type,
                'providers' => array_keys($results),
                'total_results' => $total,
                'results' => $results,
            ]);
        }
        catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Search failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API endpoint for getting detailed information.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The request object.
     * @param string $provider
     *   The provider (spotify or discogs).
     * @param string $id
     *   The item ID.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   JSON response with detailed information.
     */
    public function getDetailsApi(Request $request, $provider, $id) {
        $type = $request->query->get('type', 'artist');

        $valid_providers = ['spotify', 'discogs'];
        if (!in_array($provider, $valid_providers)) {
            return new JsonResponse([
                'error' => 'Invalid provider. Must be one of: spotify, discogs',
                'provided' => $provider,
            ], 400);
        }

        $valid_types = ['artist', 'album', 'song'];
        if (!in_array($type, $valid_types)) {
            return new JsonResponse([
                'error' => 'Invalid type. Must be one of: artist, album, song',
                'provided' => $type,
            ], 400);
        }

        try {
            $details = $this->musicSearchService->getDetails($provider, $id, $type);

            if (empty($details)) {
                return new JsonResponse([
                    'error' => 'No details found',
                    'provider' => $provider,
                    'id' => $id,
                    'type' => $type,
                ], 404);
            }

            return new JsonResponse([
                'provider' => $provider,
                'id' => $id,
                'type' => $type,
                'details' => $details,
            ]);
        }
        catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to get details',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
