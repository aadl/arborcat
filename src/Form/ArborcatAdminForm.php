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

    $form['pickup_requests_salt'] = [
      '#type' => 'textfield',
      '#title' => t('Salt for pickup requests'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('pickup_requests_salt'),
      '#size' => 32,
      '#maxlength' => 64,
      '#description' => t('Salt string for unpacking barcode numbers for pickup requests'),
    ];

    $form['selfcheck_key'] = [
      '#type' => 'textfield',
      '#title' => t('Self-check key for patron api requests'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('selfcheck_key'),
      '#size' => 32,
      '#maxlength' => 64,
      '#description' => t('self-check key for use with api requests pertaining to the patron data without the need to be signed in'),
    ];
 
    $form['max_locker_items_check'] = [
      '#type' => 'number',
      '#title' => t('Max Number of Items that should fit in a Locker'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('max_locker_items_check'),
      '#description' => t('If more items that this number are selected for locker pickup, a warning will be displayed to the patron'),
    ];

    $form['starting_day_offset'] = [
      '#type' => 'number',
      '#title' => t('Starting Day Offset'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('starting_day_offset'),
      '#description' => t('Number of days from today until the starting day of pickup dates that will be displayed to the user'),
    ];

    $form['number_of_pickup_days'] = [
      '#type' => 'number',
      '#title' => t('Number of Pickup Days'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('number_of_pickup_days'),
      '#description' => t('Number of pickup dates that will be displayed to the user'),
    ];

    $form['exclusion_marker_string'] = [
      '#type' => 'textfield',
      '#title' => t('Date Unavailable marker'),
      '#default_value' => \Drupal::config('arborcat.settings')->get('exclusion_marker_string'),
      '#description' => t('Marker string indicating that a pickup date in unavailable. This string is appended to pickup date options in the form select field'),
    ];

    return parent::buildForm($form, $form_state);
  }
}
