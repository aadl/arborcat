<?php

/**
 * @file
 * Contains \Drupal\arborcat\Form\ArborcatAdminForm.
 */

namespace Drupal\arborcat\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

class ArborcatAdminForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'arborcat_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('arborcat.settings');

    foreach (Element::children($form) as $variable) {
      $config->set($variable, $form_state->getValue($form[$variable]['#parents']));
    }
    $config->save();

    if (method_exists($this, '_submitForm')) {
      $this->_submitForm($form, $form_state);
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['arborcat.settings'];
  }

  public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $form = [];
    $form['api_url'] = [
      '#type' => 'textfield',
      '#title' => t('API URL'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('api_url'),
      '#size' => 32,
      '#maxlength' => 64,
      '#description' => t('URL of the API server for Arborcat. (e.g. https://api.website.org)'),
    ];
    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => t('API Auth Key'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('api_key'),
      '#size' => 32,
      '#maxlength' => 64,
      '#description' => t('API Key for authentication'),
    ];
    $form['mcb_lockers'] = [
      '#type' => 'textfield',
      '#title' => t('MCB Lockers URL'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('mcb_lockers'),
      '#size' => 32,
      '#maxlength' => 64,
      '#description' => t('Lockers URL for checking availability'),
    ];
    $form['pts_lockers'] = [
      '#type' => 'textfield',
      '#title' => t('PTS Lockers URL'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('pts_lockers'),
      '#size' => 32,
      '#maxlength' => 64,
      '#description' => t('Lockers URL for checking availability'),
    ];
    $form['pts_lockers_insert'] = [
      '#type' => 'textfield',
      '#title' => t('PTS Lockers Insert URL'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('pts_lockers_insert'),
      '#size' => 32,
      '#maxlength' => 64,
      '#description' => t('Lockers URL for adding lockers'),
    ];
    $form['lockers_pass'] = [
      '#type' => 'textfield',
      '#title' => t('Lockers Interface Password'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('lockers_pass'),
      '#size' => 32,
      '#maxlength' => 64,
      '#description' => t('Password for accessing lockers interfaces'),
    ];

    return parent::buildForm($form, $form_state);
  }

}
