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
    $form['couch_server'] = [
      '#type' => 'textfield',
      '#title' => t('Couch Server'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('couch_server'),
      '#size' => 64,
      '#maxlength' => 128,
      '#description' => t('CouchDB Server'),
    ];
    $form['couch_database'] = [
      '#type' => 'textfield',
      '#title' => t('Couch Database'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('couch_database'),
      '#size' => 32,
      '#maxlength' => 64,
      '#description' => t('CouchDB Database'),
    ];

    return parent::buildForm($form, $form_state);
  }

}
