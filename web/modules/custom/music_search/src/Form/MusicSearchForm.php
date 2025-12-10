<?php

namespace Drupal\music_search\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\music_search\MusicSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for searching music.
 */
class MusicSearchForm extends FormBase {

  /**
   * The music search service.
   *
   * @var \Drupal\music_search\MusicSearchService
   */
  protected $musicSearch;

  /**
   * The tempstore.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;

  /**
   * Constructs a MusicSearchForm object.
   */
  public function __construct(MusicSearchService $music_search, PrivateTempStoreFactory $temp_store_factory) {
    $this->musicSearch = $music_search;
    $this->tempStore = $temp_store_factory->get('music_search');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): MusicSearchForm {
    return new static(
      $container->get('music_search.music_search'),
      $container->get('tempstore.private')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'music_search_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $selections = $this->tempStore->get('selections') ?? [];
    $selectedAmount = count($selections);

    $isLocked = FALSE;
    $defaultType = 'artist';

    if ($selectedAmount === 1) {
      $isLocked = TRUE;
      $first = reset($selections);
      if (!empty($first['type'])) {
        $defaultType = $first['type']; // artist | album | song
      }
    }

    if ($isLocked) {
      $displayType = ucfirst($defaultType);
    } else {
      $displayType = 'for ...';
    }

    $form['#attached']['library'][] = 'music_search/music_search';

    $form['search'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Search ' . $displayType),
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
        'album'  => $this->t('Album'),
        'song'   => $this->t('Song'),
      ],
      '#default_value' => $defaultType,
      '#required' => TRUE,
      '#disabled' => $isLocked,
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
   * AJAX Callback for search.
   */
  public function ajaxSearchCallback(array &$form, FormStateInterface $form_state) {
    return $form['results'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = $form_state->getValue('query');
    $type  = $form_state->getValue('type');  // when locked, this will be the locked type

    $results = $this->musicSearch->searchAll($query, $type);

    $form_state->set('results', $results);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Render search results.
   */
  protected function renderResults($all_results, FormStateInterface $form_state): string {
    if (empty($all_results)) {
      return '<p>' . $this->t('No results found.') . '</p>';
    }

    $type = $form_state->getValue('type');

    $output = '<div class="music-search-results">';
    $output .= '<p>' . $this->t('Click "Select" to choose which data to use from each provider.') . '</p>';

    $output .= '<div class="music-search-results__columns">';

    foreach ($all_results as $provider => $results) {
      if (empty($results)) {
        continue;
      }

      $output .= '<div class="music-search-results__column music-search-results__column--' . $provider . '">';

      $output .= '<h3 class="provider-title">' . $this->t('Results from @provider', [
          '@provider' => ucfirst($provider),
        ]) . '</h3>';

      foreach ($results as $index => $item) {
        $item_id = $item['id'];

        $output .= '<div class="result-item" data-provider="' . $provider . '" data-id="' . $item_id . '">';

        if (!empty($item['image'])) {
          $output .= '<img class="result-item__image" src="' . $item['image'] . '" alt="' . htmlspecialchars($item['name']) . '">';
        }

        $output .= '<div class="result-item__content">';
        $output .= '<h4 class="result-item__title">' . htmlspecialchars($item['name']) . '</h4>';

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

        $output .= '</div>';
        $output .= '</div>';

        $select_url = '/admin/content/music-search/compare/' . $provider . '/' . $item_id . '/' . $type;
        $output .= '<div class="result-item__actions">';
        $output .= '<a href="' . $select_url . '" class="button button--primary result-item__button">';
        $output .= $this->t('Select for Comparison');
        $output .= '</a>';
        $output .= '</div>';
      }

      $output .= '</div>';
    }

    $output .= '</div>';
    $output .= '</div>';

    return $output;
  }



}
