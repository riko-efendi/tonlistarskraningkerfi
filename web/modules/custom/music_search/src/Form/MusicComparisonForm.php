<?php

namespace Drupal\music_search\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\music_search\MusicSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;

/**
 * Form for comparing and selecting data from multiple providers.
 */
class MusicComparisonForm extends FormBase
{

  /**
   * The music search service.
   */
  protected $musicSearchService;

  /**
   * The tempstore.
   */
  protected $tempStore;

  /**
   * Constructor.
   */
  public function __construct(
    MusicSearchService      $music_search_service,
    PrivateTempStoreFactory $temp_store_factory
  )
  {
    $this->musicSearchService = $music_search_service;
    $this->tempStore = $temp_store_factory->get('music_search');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('music_search.music_search'),
      $container->get('tempstore.private')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'music_comparison_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $provider = NULL, $id = NULL, $type = NULL)
  {

    if (!$provider || !$id || !$type) {
      $this->messenger()->addError($this->t('Invalid parameters.'));
      return $form;
    }

    // Get details from the provider
    $details = $this->musicSearchService->getDetails($provider, $id, $type);

    if (!$details) {
      $this->messenger()->addError($this->t('Could not fetch details from @provider.', ['@provider' => $provider]));
      return $form;
    }

    // Store current selection in tempstore
    $stored_selections = $this->tempStore->get('selections') ?? [];
    $stored_selections[$provider] = [
      'provider' => $provider,
      'id' => $id,
      'type' => $type,
      'details' => $details,
    ];
    $this->tempStore->set('selections', $stored_selections);

    $form['#attached']['library'][] = 'music_search/comparison';

    // Display current selections
    $form['selections'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Selected Items'),
    ];

    $form['selections']['info'] = [
      '#markup' => $this->renderSelections($stored_selections),
    ];

    // Option to add another provider
    if (count($stored_selections) < 2) {
      $form['add_more'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('You can add another provider for comparison.') . ' ' .
          '<a href="/admin/content/music-search">' . $this->t('Search again') . '</a></p>',
      ];
    }

    // If we have multiple selections, show comparison
    if (count($stored_selections) >= 2) {
      $form['comparison'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Compare and Select Data'),
      ];

      $form['comparison']['table'] = [
        '#markup' => $this->renderComparisonTable($stored_selections, $type),
      ];
    }

    // Hidden field for content type
    $form['content_type'] = [
      '#type' => 'hidden',
      '#value' => $type,
    ];

    $form['actions'] = ['#type' => 'actions'];

    if (count($stored_selections) >= 1) {
      $form['actions']['create'] = [
        '#type' => 'submit',
        '#value' => $this->t('Create @type', ['@type' => ucfirst($type)]),
        '#button_type' => 'primary',
      ];
    }

    $form['actions']['clear'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear Selections'),
      '#submit' => ['::clearSelections'],
      '#limit_validation_errors' => [],
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to Search'),
      '#url' => Url::fromRoute('music_search.search_form'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * Render current selections.
   */
  protected function renderSelections($selections)
  {
    $output = '<div class="selected-items">';

    foreach ($selections as $provider => $selection) {
      $details = $selection['details'];
      $output .= '<div class="selected-item" style="border: 1px solid #ccc; padding: 10px; margin: 10px 0; background: #f0f0f0;">';
      $output .= '<h4>' . ucfirst($provider) . ': ' . htmlspecialchars($details['name']) . '</h4>';

      if (!empty($details['image'])) {
        $output .= '<img src="' . htmlspecialchars($details['image']) . '" style="max-width: 150px; max-height: 150px;">';
      }

      $output .= '</div>';
    }

    $output .= '</div>';
    return $output;
  }

  /**
   * Render comparison table.
   */
  protected function renderComparisonTable($selections, $type)
  {
    $output = '<table class="comparison-table" style="width: 100%; border-collapse: collapse;">';
    $output .= '<thead><tr style="background: #ddd;">';
    $output .= '<th style="padding: 10px; border: 1px solid #ccc;">Field</th>';

    foreach ($selections as $provider => $selection) {
      $output .= '<th style="padding: 10px; border: 1px solid #ccc;">' . ucfirst($provider) . '</th>';
    }

    $output .= '<th style="padding: 10px; border: 1px solid #ccc;">Use This</th>';
    $output .= '</tr></thead><tbody>';

    // Get all unique fields
    $all_fields = [];
    foreach ($selections as $selection) {
      $all_fields = array_merge($all_fields, array_keys($selection['details']));
    }
    $all_fields = array_unique($all_fields);

    // Fields to compare
    $compare_fields = ['name', 'artist', 'album', 'year', 'genres', 'length', 'image'];

    foreach ($compare_fields as $field) {
      if (!in_array($field, $all_fields)) {
        continue;
      }

      $output .= '<tr>';
      $output .= '<td style="padding: 10px; border: 1px solid #ccc;"><strong>' . ucfirst($field) . '</strong></td>';

      foreach ($selections as $provider => $selection) {
        $value = $selection['details'][$field] ?? '-';

        if (is_array($value)) {
          $value = implode(', ', $value);
        }

        if ($field === 'image' && !empty($value) && $value !== '-') {
          $value = '<img src="' . htmlspecialchars($value) . '" style="max-width: 100px;">';
        } else {
          $value = htmlspecialchars($value);
        }

        $output .= '<td style="padding: 10px; border: 1px solid #ccc;">' . $value . '</td>';
      }

      // Radio buttons to select which provider's data to use
      $output .= '<td style="padding: 10px; border: 1px solid #ccc;">';
      foreach ($selections as $provider => $selection) {
        $val = $selection['details'][$field] ?? NULL;
        if ($val && $val !== '-') {
          $output .= '<label><input type="radio" name="field_' . $field . '" value="' . $provider . '"> ' . ucfirst($provider) . '</label><br>';
        }
      }
      $output .= '</td>';

      $output .= '</tr>';
    }

    $output .= '</tbody></table>';

    $output .= '<p><em>' . $this->t('Note: Select which provider\'s data to use for each field.') . '</em></p>';

    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $type = $form_state->getValue('content_type');
    $selections = $this->tempStore->get('selections') ?? [];

    if (empty($selections)) {
      $this->messenger()->addError($this->t('No selections found.'));
      return;
    }

    // Merge data based on user selection (if multiple providers)
    $merged_data = $this->mergeData($selections, $form_state);

    // Create content
    $node = $this->musicSearchService->createContent($merged_data, $type);

    if ($node) {
      $this->messenger()->addStatus($this->t('Created @type: <a href="@url">@title</a>', [
        '@type' => $type,
        '@title' => $node->label(),
        '@url' => $node->toUrl()->toString(),
      ]));

      // Clear selections
      $this->tempStore->delete('selections');

      // Redirect to the created node
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
    } else {
      $this->messenger()->addError($this->t('Failed to create content.'));
    }
  }

  /**
   * Merge data from multiple providers.
   */
  protected function mergeData($selections, FormStateInterface $form_state)
  {
    $merged = [];

    // Get first provider's data as base
    $first = reset($selections);
    $merged = $first['details'];

    // Store provider IDs
    foreach ($selections as $provider => $selection) {
      $merged[$provider . '_id'] = $selection['id'];
    }

    // If user made selections from comparison table, use those
    // For now, just use first provider's data
    // You can enhance this to read radio button selections

    return $merged;
  }

  /**
   * Clear selections submit handler.
   */
  public function clearSelections(array &$form, FormStateInterface $form_state)
  {
    $this->tempStore->delete('selections');
    $this->messenger()->addStatus($this->t('Selections cleared.'));
    $form_state->setRedirect('music_search.search_form');
  }

}
