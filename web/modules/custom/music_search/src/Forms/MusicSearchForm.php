<?php

namespace Drupal\music_search\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\music_search\MusicSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for searching music.
 */
class MusicSearchForm extends FormBase {

  /**
   * The music search service
   */
  protected $musicSearch;

  /**
   * Constructs a MusicSearchForm object.
   */
  public function __construct(MusicSearchService $music_search) {
    $this->musicSearch = $music_search;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) : MusicSearchForm {
    return new static(
      $container->get('music_search.music_search')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() : string {
    return 'music_search_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'music_search/music_search';

    $form['search'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Search Music'),
    ];

    $form['search']['query'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search'),
      '#required' => TRUE,
      '#placeholder' => $this->t('Enter artist, album, or song name...'),
    ];

    $form['search']['type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Type'),
      '#options' => [
        'artist' => $this->t('Artist'),
        'album' => $this->t('Album'),
        'song' => $this->t('Song'),
      ],
      '#default_value' => 'artist',
      '#required' => TRUE,
    ];

    $form['search']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#ajax' => [
        'callback' => '::ajaxSearchCallback',
        'wrapper' => 'search-results',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Searching for results...'),
        ],
      ],
    ];

    $form['results'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'search-results'],
    ];

    if ($results = $form_state->get('results')) {
      $form['results']['content'] = [
        '#markup' => $this->renderResults($results, $form_state),
      ];
    }

    return $form;
  }

  /**
   * AJAX Callback for search
   */
  public function ajaxSearchCallback(array &$form, FormStateInterface $form_state) {
    return $form['results'];
  }


  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) : void {
    $query = $form_state->getValue('query');
    $type = $form_state->getValue('type');

    $results = $this->musicSearch->searchAll($query, $type);

    // Use set(), not setValue().
    $form_state->set('results', $results);
    $form_state->setRebuild(TRUE);
  }


  /**
   * Render search results
   */
  protected function renderResults($all_results, FormStateInterface $form_state): string
  {
    if (empty($all_results)) {
      return '<p>' . $this->t('No results found.') . '</p>';
    }

    $type = $form_state->getValue('type');

    $output = '<div class="music-search-results">';
    $output .= '<p>' . $this->t('Click "Select" to choose which data to use from each provider.') . '</p>';

    foreach ($all_results as $provider => $results) {
      if (empty($results)) {
        continue;
      }

      $output .= '<h3>' . $this->t('Results from @provider', ['@provider' => ucfirst($provider)]) . '</h3>';

      foreach ($results as $index => $item) {
        $item_id = $item['id'];
        $output .= '<div class="result-item" data-provider="' . $provider . '" data-id="' . $item_id . '">';

        if (!empty($item['image'])) {
          $output .= '<img src="' . $item['image'] . '" alt="' . htmlspecialchars($item['name']) . '" style="width: 100px; height: 100px; object-fit: cover; float: left; margin-right: 15px;">';
        }

        $output .= '<h4>' . htmlspecialchars($item['name']) . '</h4>';

        if (!empty($item['artist'])) {
          $output .= '<p><strong>Artist:</strong> ' . htmlspecialchars($item['artist']) . '</p>';
        }

        if (!empty($item['album'])) {
          $output .= '<p><strong>Album:</strong> ' . htmlspecialchars($item['album']) . '</p>';
        }

        if (!empty($item['year'])) {
          $output .= '<p><strong>Year:</strong> ' . htmlspecialchars($item['year']) . '</p>';
        }

        if (!empty($item['length'])) {
          $output .= '<p><strong>Length:</strong> ' . htmlspecialchars($item['length']) . '</p>';
        }

        if (!empty($item['genres'])) {
          $output .= '<p><strong>Genres:</strong> ' . htmlspecialchars(implode(', ', $item['genres'])) . '</p>';
        }

        $output .= '<p><strong>' . ucfirst($provider) . ' ID:</strong> ' . htmlspecialchars($item['id']) . '</p>';

        // Add Select button
        /*$output .= '<button type="button" class="button button--primary select-result"
        data-provider="' . $provider . '"
        data-id="' . $item_id . '"
        data-name="' . htmlspecialchars($item['name']) . '"
        onclick="selectResult(\'' . $provider . '\', \'' . $item_id . '\', \'' . $type. '\')">';
        $output .= $this->t('Select This Result');
        $output .= '</button>';*/

        $select_url = '/admin/content/music-search/compare/' . $provider . '/' . $item_id . '/' . $type;
        $output .= '<a href="' . $select_url . '" class="button button--primary" style="margin-top: 10px; display: inline-block;">';
        $output .= $this->t('Select for Comparison');
        $output .= '</a>';

        $output .= '<div style="clear: both;"></div>';
        $output .= '</div>';
      }
    }

    $output .= '</div>';

    return $output;
  }
}
