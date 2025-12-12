<?php

namespace Drupal\music_search\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\music_search\MusicSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Form for selecting and combining data from multiple providers.
 */
class MusicSearchSelectionForm extends FormBase {

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
    MusicSearchService $music_search_service,
    PrivateTempStoreFactory $temp_store_factory
  ) {
    $this->musicSearchService = $music_search_service;
    $this->tempStore = $temp_store_factory->get('music_search');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('music_search.music_search'),
      $container->get('tempstore.private')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'music_search_selection_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $provider = NULL, $id = NULL, $type = NULL) {
    if ($provider && $id && $type) {
      $details = $this->musicSearchService->getDetails($provider, $id, $type);

      if ($details) {
        $form['details'] = [
          '#markup' => $this->renderDetails($details, $provider, $type),
        ];

        $form['provider'] = [
          '#type' => 'hidden',
          '#value' => $provider,
        ];

        $form['provider_id'] = [
          '#type' => 'hidden',
          '#value' => $id,
        ];

        $form['content_type'] = [
          '#type' => 'hidden',
          '#value' => $type,
        ];

        $form['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Create @type', ['@type' => ucfirst($type)]),
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $provider = $form_state->getValue('provider');
    $provider_id = $form_state->getValue('provider_id');
    $type = $form_state->getValue('content_type');

    $details = $this->musicSearchService->getDetails($provider, $provider_id, $type);

    if ($details) {
      $node = $this->musicSearchService->createContent($details, $type);

      if ($node) {
        $this->messenger()->addStatus($this->t('Created @type: @title', [
          '@type' => $type,
          '@title' => $node->label(),
        ]));

        $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      }
      else {
        $this->messenger()->addError($this->t('Failed to create content.'));
      }
    }
  }

  /**
   * Render details.
   */
  protected function renderDetails($details, $provider, $type) {
    $output = '<div class="music-search-details">';
    $output .= '<h2>' . htmlspecialchars($details['name']) . '</h2>';
    $output .= '<p><strong>Source:</strong> ' . ucfirst($provider) . '</p>';

    if (!empty($details['image'])) {
      $output .= '<img src="' . $details['image'] . '" style="max-width: 300px;">';
    }

    foreach ($details as $key => $value) {
      if (in_array($key, ['id', 'name', 'image', 'provider'])) {
        continue;
      }

      if (is_array($value)) {
        $output .= '<p><strong>' . ucfirst($key) . ':</strong> ' . implode(', ', $value) . '</p>';
      }
      else {
        $output .= '<p><strong>' . ucfirst($key) . ':</strong> ' . htmlspecialchars($value) . '</p>';
      }
    }

    $output .= '</div>';

    return $output;
  }

}

