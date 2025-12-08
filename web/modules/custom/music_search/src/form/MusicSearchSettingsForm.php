<?php

namespace Drupal\music_search\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Music Search settings.
 */

class MusicSearchSettingsForm extends ConfigFormBase {

    /*
    * {@inheritdoc}
    */
    public function getFormId(): string {
        return 'music_search_settings_form';
    }
    /*
    * {@inheritdoc}
    */
    protected function getEditableConfigNames(): array {
        return ['music_search.settings'];
    }

    /*
    * {@inheritdoc}
    */
    public function buildForm(array $form, FormStateInterface $form_state) {
        $config = $this->config('music_search.settings');

        $form['spotify'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Spotify API Settings'),
        ];

        $form['spotify']['spotify_client_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Client ID'),
            '#default_value' => $config->get('spotify_client_id'),
            '#description' => $config->get('Get your credentials from https://developer.spotify.com/dashboard'),
        ];

        $form['spotify']['spotify_client_secret'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Client Secret'),
            '#default_value' => $config->get('spotify_client_secret'),
        ];

        $form['discogs'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Discogs API Settings'),
        ];

        $form['discogs']['discogs_api_key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('API Key'),
            '#default_value' => $config->get('discogs_api_key'),
            '#description' => $config->get('Get your credentials from https://discogs.com/settings/developers'),
        ];

        $form['discogs']['discogs_api_secret'] = [
          '#type' => 'textfield',
          '#title' => $this->t('API Secret'),
          '#default_value' => $config->get('discogs_api_secret'),
        ];

        return parent::buildForm($form, $form_state);
    }

    public function submitForm(array &$form, FormStateInterface $form_state) {
        $this->config('music_search.settings')
            ->set('spotify_client_id', $form_state->getValue('spotify_client_id'))
            ->set('spotify_client_secret', $form_state->getValue('spotify_client_secret'))
            ->set('discogs_api_key', $form_state->getValue('discogs_api_key'))
            ->set('discogs_api_secret', $form_state->getValue('discogs_api_secret'))
            ->save();

        parent::submitForm($form, $form_state);
    }
}
