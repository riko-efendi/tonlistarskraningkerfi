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

    \Drupal::logger('music_search')->notice(
      'Compare params: provider=@p id=@id type=@t',
      ['@p' => $provider, '@id' => $id, '@t' => $type]
    );


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

    if (count($stored_selections) >= 2) {
      $form['comparison'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Compare Data from Multiple Sources'),
        '#description' => $this->t('Review the data from each provider, then select which fields to use.'),
      ];

      // Add visual comparison table
      $form['comparison']['table'] = [
        '#markup' => $this->renderComparisonTable($stored_selections),
      ];

      // Add field selection radio buttons
      $form['comparison']['fields'] = $this->buildComparisonFields($stored_selections, $type);
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
   * Build comparison fields with radio buttons for selection.
   */
  protected function buildComparisonFields($selections, $type) {
    $fields = [];

    // Define which fields to compare based on content type
    $compare_fields = $this->getCompareFields($type);

    foreach ($compare_fields as $field_info) {
      $field_name = $field_info['field'];
      $field_label = $field_info['label'];
      $is_required = $field_info['required'] ?? FALSE;

      $options = [];
      $default = NULL;
      $has_values = FALSE;

      // Build options from each provider - skip empty values
      foreach ($selections as $key => $selection) {
        $value = $selection['details'][$field_name] ?? NULL;

        $is_empty = (
          $value === NULL ||
          $value === '' ||
          $value === '-' ||
          $value === 'null' ||
          (is_array($value) && empty($value)) ||
          (is_string($value) && trim($value) === '')
        );

        if (!$is_empty) {
          $has_values = TRUE;

          // Format the display value
          $display_value = $this->formatFieldValue($value, $field_name);

          // Create option: "Spotify: The Beatles"
          $options[$key . '|' . $field_name] = ucfirst($selection['provider']) . ': ' . $display_value;

          // Set first option as default
          if ($default === NULL) {
            $default = $key . '|' . $field_name;
          }
        }
      }

      // ✅ Only add field if we have at least one non-empty value
      if (!empty($options) && $has_values) {
        $fields['field_' . $field_name] = [
          '#type' => 'radios',
          '#title' => $this->t('Select @field', ['@field' => $field_label]),
          '#options' => $options,
          '#default_value' => $default,
          '#required' => $is_required && count($options) > 0,  // Only required if options exist
          '#description' => $this->t('Choose which source to use for @field.', ['@field' => strtolower($field_label)]),
        ];
      }
    }

    // Add note about provider IDs
    $fields['provider_note'] = [
      '#markup' => '<div style="padding: 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 5px; margin-top: 20px;">' .
        '<strong>Note:</strong> All provider IDs will be saved with the content, regardless of which fields you select.' .
        '</div>',
    ];

    return $fields;
  }

  /**
   * Get fields to compare based on content type.
   */
  protected function getCompareFields($type) {
    $fields = [];

    if ($type === 'artist') {
      $fields = [
        ['field' => 'name', 'label' => 'Artist Name', 'required' => TRUE],
        ['field' => 'image', 'label' => 'Image'],
        ['field' => 'profile', 'label' => 'Description'],
        ['field' => 'discogs_url', 'label' => 'Website'],
        ['field' => 'genres', 'label' => 'Genres'],
      ];
    }
    elseif ($type === 'album') {
      $fields = [
        ['field' => 'name', 'label' => 'Album Name', 'required' => TRUE],
        ['field' => 'artist', 'label' => 'Artist Name'],
        ['field' => 'year', 'label' => 'Release Year'],
        ['field' => 'image', 'label' => 'Cover Image'],
        ['field' => 'genres', 'label' => 'Genres'],
      ];
    }
    elseif ($type === 'song') {
      $fields = [
        ['field' => 'name', 'label' => 'Song Title', 'required' => TRUE],
        ['field' => 'artist', 'label' => 'Artist Name'],
        ['field' => 'album', 'label' => 'Album Name'],
        ['field' => 'length', 'label' => 'Length'],
      ];
    }

    return $fields;
  }

  /**
   * Format field value for display.
   */
  protected function formatFieldValue($value, $field_name, $short = FALSE) {
    if (is_array($value)) {
      $joined = implode(', ', $value);
      return $short ? (strlen($joined) > 50 ? substr($joined, 0, 50) . '...' : $joined) : $joined;
    }

    if ($field_name === 'image') {
      return '[Image Available]';
    }

    if (is_string($value)) {
      return $short ? (strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value) : $value;
    }

    return (string) $value;
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
        $output .= '<img src="' . htmlspecialchars($details['image']) . '"
        alt="' . htmlspecialchars($details['name']) . '"
        class="result-item__image">';
      }

      $output .= '</div>';
    }

    $output .= '</div>';
    return $output;
  }

  /**
   * Render comparison table.
   */
  protected function renderComparisonTable($selections)
  {
    $output = '<div style="overflow-x: auto; margin: 20px 0;">';
    $output .= '<table style="width: 100%; border-collapse: collapse; font-size: 0.9em;">';

    // Header row with provider names
    $output .= '<thead><tr style="background: #f0f0f0;">';
    $output .= '<th style="padding: 12px; border: 1px solid #ddd; text-align: left; font-weight: bold;">Field</th>';

    foreach ($selections as $selection) {
      $provider = ucfirst($selection['provider']);
      $output .= '<th style="padding: 12px; border: 1px solid #ddd; text-align: left;">';
      $output .= '<div style="font-weight: bold; color: #2196f3;">' . $provider . '</div>';
      $output .= '<div style="font-size: 0.85em; color: #666;">' . htmlspecialchars($selection['details']['name']) . '</div>';
      $output .= '</th>';
    }

    $output .= '</tr></thead><tbody>';

    // Get all unique field names
    $all_fields = [];
    foreach ($selections as $selection) {
      $all_fields = array_merge($all_fields, array_keys($selection['details']));
    }
    $all_fields = array_unique($all_fields);

    $display_fields = [
      'name', 'artist', 'album', 'year',
      'genres', 'length', 'image',
      'profile', 'discogs_url'
    ];

    foreach ($display_fields as $field_name) {
      if (!in_array($field_name, $all_fields)) {
        continue;
      }
      $has_content = FALSE;
      foreach ($selections as $selection) {
        $value = $selection['details'][$field_name] ?? '-';

        $is_empty = (
          $value === '-' ||
          $value === '' ||
          $value === NULL ||
          (is_array($value) && empty($value))
        );

        if (!$is_empty) {
          $has_content = TRUE;
          break;
        }
      }

      // Skip this row if all values are empty
      if (!$has_content) {
        continue;
      }

      $output .= '<tr>';
      $output .= '<td style="padding: 10px; border: 1px solid #ddd; font-weight: bold; background: #fafafa;">';
      $output .= ucfirst(str_replace('_', ' ', $field_name));
      $output .= '</td>';

      foreach ($selections as $selection) {
        $value = $selection['details'][$field_name] ?? '-';

        $output .= '<td style="padding: 10px; border: 1px solid #ddd;">';

        if ($field_name === 'image' && !empty($value) && $value !== '-') {
          $output .= '<img src="' . htmlspecialchars($value) . '"
          alt="' . htmlspecialchars($selection['details']['name'] ?? 'Image') . '"
          class="result-item__image">';
        }
        elseif (is_array($value)) {
          if (empty($value)) {
            $output .= '-';
          } else {
            $output .= htmlspecialchars(implode(', ', $value));
          }
        }
        else {
          $output .= htmlspecialchars($value);
        }

        $output .= '</td>';
      }

      $output .= '</tr>';
    }

    $output .= '</tbody></table>';
    $output .= '</div>';

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
    $type = $form_state->getValue('content_type');

    // Get all provider IDs first
    $provider_ids = [];
    foreach ($selections as $key => $selection) {
      $provider = $selection['provider'];
      $provider_ids[$provider . '_id'] = $selection['id'];
    }

    // Get field selections from form
    $compare_fields = $this->getCompareFields($type);

    foreach ($compare_fields as $field_info) {
      $field_name = $field_info['field'];
      $form_field_name = 'field_' . $field_name;

      // Get user's selection (format: "key|fieldname")
      $selected = $form_state->getValue($form_field_name);

      if ($selected) {
        // Parse the selection
        list($selected_key, $selected_field) = explode('|', $selected);

        // Get the value from selected provider
        if (isset($selections[$selected_key])) {
          $value = $selections[$selected_key]['details'][$selected_field] ?? NULL;

          if ($value !== NULL && $value !== '' && $value !== '-') {
            $merged[$field_name] = $value;
          }
        }
      }
    }

    // Add all provider IDs
    $merged = array_merge($merged, $provider_ids);

    // Add type
    $merged['type'] = $type;

    if ($type === 'artist') {
      foreach ($selections as $selection) {
        if (!empty($selection['details']['members'])) {
          $merged['members'] = $selection['details']['members'];
          break;
        }
      }
    }

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
