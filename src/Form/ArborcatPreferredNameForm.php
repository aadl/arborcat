<?php

/**
 * @file
 * Contains \Drupal\arborcat\Form\ArborcatPreferredNameForm.
 */

namespace Drupal\arborcat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class ArborcatPreferredNameForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'arborcat_preferred_name_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $uid = NULL, $delta = 0) {
    $form = [];

    // Check access to Account
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    if ($user->get('uid')->value == $uid ||
        $user->hasPermission('administer users')) {

      $account = \Drupal\user\Entity\User::load($uid);

      // Add Barcode Buttons if account has existing barcodes
      $field_barcodes = $account->get('field_barcode')->getValue();

      if (isset($field_barcodes[$delta])) {
        $guzzle = \Drupal::httpClient();
        $api_url = \Drupal::config('arborcat.settings')->get('api_url');
        $api_keys = $account->get('field_api_key')->getValue();

        // Get barcode and API key
        $field_barcode = $field_barcodes[$delta]['value'];
        $api_key = $api_keys[$delta]['value'];

        // Pull Patron Data
        $patron = FALSE;
        try {
          $patron = json_decode($guzzle->get("$api_url/patron/$api_key/get")->getBody()->getContents());
        }
        catch (\Exception $e) {
          drupal_set_message('Error retrieving patron data for ' . $field_barcode, 'error');
        }

        if ($patron) {
          $form['account'] = [
            '#type' => 'value',
            '#value' => $account,
          ];
          $form['delta'] = [
            '#type' => 'value',
            '#value' => $delta,
          ];
          $form['pref_prefix'] = [
            '#prefix' => "<p>$field_barcode, $patron->name</p>",
            '#type' => 'textfield',
            '#title' => t('Preferred Prefix'),
            '#default_value' => $patron->evg_user->pref_prefix,
            '#size' => 32,
            '#maxlength' => 64,
            '#description' => t('Official Prefix: ' . $patron->evg_user->prefix),
          ];
          $form['pref_first_given_name'] = [
            '#type' => 'textfield',
            '#title' => t('Preferred First Given Name'),
            '#default_value' => $patron->evg_user->pref_first_given_name,
            '#size' => 32,
            '#maxlength' => 64,
            '#description' => t('Official First Given Name: ' . $patron->evg_user->first_given_name),
          ];
          $form['pref_second_given_name'] = [
            '#type' => 'textfield',
            '#title' => t('Preferred Second Given Name'),
            '#default_value' => $patron->evg_user->pref_second_given_name,
            '#size' => 32,
            '#maxlength' => 64,
            '#description' => t('Official Second Given Name: ' . $patron->evg_user->second_given_name),
          ];
          $form['pref_family_name'] = [
            '#type' => 'textfield',
            '#title' => t('Preferred Family Name'),
            '#default_value' => $patron->evg_user->pref_family_name,
            '#size' => 32,
            '#maxlength' => 64,
            '#description' => t('Official Family Name: ' . $patron->evg_user->family_name),
          ];
          $form['pref_suffix'] = [
            '#type' => 'textfield',
            '#title' => t('Preferred Suffix'),
            '#default_value' => $patron->evg_user->pref_suffix,
            '#size' => 32,
            '#maxlength' => 64,
            '#description' => t('Official Suffix: ' . $patron->evg_user->suffix),
          ];
          $form['update'] = [
            '#type' => 'submit',
            '#value' => "Update Preferred Names for Library Card $field_barcode",
          ];
        }
      }
    }
    else {
      drupal_set_message('Access Denied to User ID ' . $uid, 'error');
      return $this->redirect('<front>');
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $guzzle = \Drupal::httpClient();
    $api_url = \Drupal::config('arborcat.settings')->get('api_url');

    $values = $form_state->getValues();
    $account = $values['account'];
    $api_keys = $account->get('field_api_key')->getValue();
    $field_barcodes = $account->get('field_barcode')->getValue();
    $delta = $values['delta'];

    $api_key = $api_keys[$delta]['value'];
    $field_barcode = $field_barcodes[$delta]['value'];

    // Update evg user fields using API
    $query = [
      'pref_prefix' => $values['pref_prefix'],
      'pref_first_given_name' => $values['pref_first_given_name'],
      'pref_second_given_name' => $values['pref_second_given_name'],
      'pref_family_name' => $values['pref_family_name'],
      'pref_suffix' => $values['pref_suffix'],
    ];
    $response = $guzzle->request('GET', "$api_url/patron/$api_key/set", ['query' => $query]);

    drupal_set_message('Successfully updated Preferred Names for Library Card ' . $field_barcode);

    $form_state->setRedirect('entity.user.canonical', ['user' => $account->get('uid')->value]);

    return;
  }
}
