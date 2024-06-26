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
          // Check for Non Resident profiles
          if (in_array($patron->evg_user->profile, [18, 25, 26, 27])) {
            \Drupal::messenger()->addWarning(['#markup' => 'This account is not eligible for online renewal. <a href="/contactus">Contact Us</a> with your information or visit us in person to renew your card.']);
            return $this->redirect('entity.user.canonical', ['user' => $uid]);
          }

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
          $form['last_name'] = [
            '#prefix' => '<div class="barcode-form"><h2>Renewing Library Card ' . $field_barcode . '</h2>',
            '#type' => 'textfield',
            '#size' => 60,
            '#maxlength' => 128,
            '#title' => 'Verify Card Holder Last Name',
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

    if (strtolower($form_state->getValue('last_name')) != strtolower($patron->evg_user->family_name)) {
      $form_state->setErrorByName('last_name', $this->t('Last name does not match record on file'));
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
        $form_state->setErrorByName('street', $this->t('Street address does not match record on file. Is your address entered correctly?'));
      }
    }

    if ($form_state->hasAnyErrors()) {
      \Drupal::messenger()->addWarning(['#markup' => 'Having issues renewing your card online? <a href="/contactus">Contact Us</a> with your information to renew your card.']);
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
    $expire_ts = strtotime('+2 years');

    $guzzle = \Drupal::httpClient();
    $api_url = \Drupal::config('arborcat.settings')->get('api_url');
    $query = ['expire_date' => date('Y-m-d', $expire_ts) . 'T00:00:00'];

    $response = $guzzle->request('GET', "$api_url/patron/$api_key/set", ['query' => $query]);

    $expire_text = date('F j, Y', $expire_ts);
    \Drupal::messenger()->addMessage("Renewal is successful! Library Card #$patron->card will now expire on $expire_text");

    $form_state->setRedirect('entity.user.canonical', ['user' => $uid]);

    return;
  }

  private function geocode_lookup($street, $zip) {
    $address = FALSE;
    $guzzle = \Drupal::httpClient();
    $geocode_url =  \Drupal::config('arborcat.settings')->get('geocode_url');

    $query = [
      'q' =>  $street . ' ' . $zip,
      'key' => \Drupal::config('arborcat.settings')->get('geocode_key'),
      'proximity' => '42.2781923,-83.7459068',
      //'bounds' => '-84.73618,41.59193,-82.72568,42.94440',
      'no_record' => 1,
    ];
    try {
      $response = $guzzle->request('GET', $geocode_url, ['query' => $query]);
    }
    catch (\Exception $e) {
      \Drupal::messenger()->addError('Unable to lookup address');
    }

    if ($response) {
      $response_body = json_decode($response->getBody()->getContents());

      if (isset($response_body->results[0]->formatted)) {
        $address = $response_body->results[0]->formatted;
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
