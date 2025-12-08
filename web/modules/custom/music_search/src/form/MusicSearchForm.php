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
        '#markup' => $this->renderResults($results),
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
  protected function renderResults(array $results) : string {
    $output = '<div class="music-search-results">';

    foreach ($results as $provider => $items) {
      if(empty($items)) {
        continue;
      }

      $output .= '<h3>' . ucfirst($provider) . '</h3>';
      $output .= '<div class="results-' . $provider . '">';

      foreach ($items as $item) {
        $output .= '<div class="result-item">';

        if (!empty($item['image'])) {
          $output .= '<img src="' . $item['image'] . '" alt="' . $item['name'] . '" width="100">';
        }

        $output .= '<h4>' . $item['name'] . '</h4>';

        if (!empty($item['artist'])) {
          $output .= '<p>Artist: ' . $item['artist'] . '</p>';
        }

        if (!empty($item['year'])) {
          $output .= '<p>Year: ' . $item['year'] . '</p>';
        }

        $output .= '<button class="button" data-provider="' . $provider . '" data-id="' . $item['id'] . '">Select</button>';
        $output .= '</div>';
      }

      $output .= '</div>';
    }
    $output .= '</div>';

    return $output;
  }
}
