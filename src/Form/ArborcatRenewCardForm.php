<?php

/**
 * @file
 * Contains \Drupal\arborcat\Form\ArborcatRenewCardForm.
 */

namespace Drupal\arborcat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class ArborcatRenewCardForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'arborcat_renew_card_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $uid = NULL, $subaccount = 0) {
    $form = [];

    // Check access to Account
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    if ($user->get('uid')->value == $uid ||
        $user->hasPermission('administer users')) {

      $account = \Drupal\user\Entity\User::load($uid);
      $field_barcodes = $account->get('field_barcode')->getValue();

      if (count($field_barcodes)) {
        $guzzle = \Drupal::httpClient();
        $api_url = \Drupal::config('arborcat.settings')->get('api_url');
        $api_keys = $account->get('field_api_key')->getValue();

        $field_barcode = $field_barcodes[$subaccount]['value'];

        // Get corresponding API Key
        $api_key = $api_keys[$subaccount]['value'];

        // Pull Patron Data
        $patron = FALSE;
        try {
          $patron = json_decode($guzzle->get("$api_url/patron/$api_key/get")->getBody()->getContents());
        }
        catch (\Exception $e) {
          \Drupal::messenger()->addError('Error retrieving patron data for ' . $field_barcode);
        }

        if ($patron) {
          $form['uid'] = [
            '#type' => 'value',
            '#value' => $uid,
          ];
          $form['api_key'] = [
            '#type' => 'value',
            '#value' => $api_key,
          ];
          $form['patron'] = [
            '#type' => 'value',
            '#value' => $patron,
          ];
          $form['dob'] = [
            '#prefix' => '<div class="barcode-form"><h2>Renewing Library Card ' . $field_barcode . '</h2>',
            '#type' => 'date', // types 'date_text' and 'date_timezone' are also supported. See .inc file.
            '#title' => 'Verify Card Holder Birthdate',
          ];
          $form['street'] = [
            '#type' => 'textfield',
            '#title' => 'Verify Card Holder Street Address',
            '#size' => 60,
            '#maxlength' => 128,
          ];
          $form['zip'] = [
            '#type' => 'textfield',
            '#title' => 'Verify Card Holder Zip Code',
            '#size' => 5,
            '#maxlength' => 5,
          ];
          $form['actions']['renew'] = array(
            '#type' => 'submit',
            '#value' => $this->t('Renew Card'),
            '#button_type' => 'primary',
          );
          $form['actions']['cancel'] = [
            '#suffix' => '</div>',
            '#type' => 'link',
            '#title' => $this->t('Cancel'),
            '#url' => \Drupal\Core\Url::fromRoute('entity.user.canonical', ['user' => $uid]),
          ];
        }
      }
    }
    else {
      \Drupal::messenger()->addError('Access Denied to User ID ' . $uid);
      return $this->redirect('<front>');
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $patron = $form_state->getValue('patron');

    if ($form_state->getValue('dob') != $patron->evg_user->dob) {
      $form_state->setErrorByName('dob', $this->t('Birthdate does not match record on file'));
    }
    if ($form_state->getValue('zip') != $patron->evg_user->addresses[0]->post_code) {
      $form_state->setErrorByName('zip', $this->t('Zip Code does not match record on file'));
    }

    // Check string street match
    $patron_street_compressed = preg_replace('/[^A-Z0-9]/', '', strtoupper($patron->evg_user->addresses[0]->street1));
    $entered_street_compressed = preg_replace('/[^A-Z0-9]/', '', strtoupper($form_state->getValue('street')));
    if ($patron_street_compressed != $entered_street_compressed) {
      // String match failed, try geocode normalization
      $patron_geocode_address = $this->geocode_lookup($patron->evg_user->addresses[0]->street1,
                                                      $patron->evg_user->addresses[0]->post_code);
      $entered_geocode_address = $this->geocode_lookup($form_state->getValue('street'), $form_state->getValue('zip'));

      if ($patron_geocode_address != $entered_geocode_address) {
        $form_state->setErrorByName('street', $this->t('Street address does not match record on file'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Renew Card
    $uid = $form_state->getValue('uid');
    $patron = $form_state->getValue('patron');
    $api_key = $form_state->getValue('api_key');
    $expire_date = date('Y-m-d', strtotime('+2 years'));

    $guzzle = \Drupal::httpClient();
    $api_url = \Drupal::config('arborcat.settings')->get('api_url');
    $query = ['expire_date' => $expire_date . 'T00:00:00-0400'];

    $response = $guzzle->request('GET', "$api_url/patron/$api_key/set", ['query' => $query]);

    \Drupal::messenger()->addMessage("Set $expire_date as new expiration date for Library Card #$patron->card");

    $form_state->setRedirect('entity.user.canonical', ['user' => $uid]);

    return;
  }

  private function geocode_lookup($street, $zip) {
    $address = FALSE;
    $guzzle = \Drupal::httpClient();
    $geocode_search_url = \Drupal::config('summergame.settings')->get('summergame_homecode_geocode_url');

    $query = [
      'street' => $street,
      'postalcode' => $zip,
      'country' => 'United States of America',
      'addressdetails' => 1,
      'format' => 'json',
    ];
    try {
      $response = $guzzle->request('GET', $geocode_search_url, ['query' => $query]);
    }
    catch (\Exception $e) {
      \Drupal::messenger()->addError('Unable to lookup address');
    }
    if ($response) {
      $response_body = json_decode($response->getBody()->getContents());
      if (isset($response_body[0]->address)) {
        $address = $response_body[0]->address;
      }
      else {
        \Drupal::messenger()->addError('Unable to find entered address');
      }
    }
    else {
      \Drupal::messenger()->addError('Empty response on address lookup');
    }

    return $address;
  }
}
