<?php

/**
 * @file
 * Contains \Drupal\arborcat\Form\ArborcatBarcodeForm.
 */

namespace Drupal\arborcat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class ArborcatBarcodeForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'arborcat_barcode_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $uid = NULL) {
    $form = [];
    $form['uid'] = [
      '#type' => 'value',
      '#value' => $uid,
    ];
    $form['barcode'] = [
      '#type' => 'textfield',
      '#title' => t('Library Card Barcode'),
      '#default_value' => '', // User's current Barcode
      '#size' => 32,
      '#maxlength' => 32,
      '#description' => t('Your Library Card Barcode number for user ' . $uid),
    ];
    $form['patron_data']['name'] = [
      '#type' => 'textfield',
      '#title' => t('Last Name'),
      '#size' => 32,
      '#maxlength' => 32,
      '#description' => t('Validate with the Last Name on the Library Account'),
    ];
    $form['patron_data']['street'] = [
      '#type' => 'textfield',
      '#title' => t('Street Name'),
      '#size' => 32,
      '#maxlength' => 32,
      '#description' => t('Validate with the Street Name on the Library Account'),
    ];
    $form['patron_data']['phone'] = [
      '#type' => 'tel',
      '#title' => t('Phone Number'),
      '#size' => 32,
      '#maxlength' => 32,
      '#description' => t('Validate with the Phone number on the Library Account'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Add Barcode'),
      '#button_type' => 'primary',
    );
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('entity.user.canonical', ['user' => $uid]),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $barcode = preg_replace('/[^0-9]/', '', $form_state->getValue('barcode'));
    // Make sure barcode is correct format
    if (!preg_match('/21621[0-9]{9}/', $barcode)) {
      $form_state->setErrorByName('barcode', $this->t('Invalid format. Barcodes are 14 digits long and start with "21621"'));
    }
    else {
      // Make sure barcode exists in Evergreen
      $api_url = \Drupal::config('arborcat.settings')->get('api_url');
      $guzzle = \Drupal::httpClient();

      $query = [
        'barcode' => $barcode,
      ];
      if ($form_state->getValue('name')) {
        $query['name'] = $form_state->getValue('name');
      }
      else if ($form_state->getValue('street')) {
        $query['street'] = $form_state->getValue('street');
      }
      else if ($form_state->getValue('phone')) {
        $query['phone'] = $form_state->getValue('phone');
      }

      $response = $guzzle->request('GET', "$api_url/patron/validate_barcode", ['query' => $query]);
      $response_body = json_decode($response->getBody()->getContents());

      if ($response_body->status == 'ERROR') {
        $form_state->setErrorByName($response_body->error, $response_body->message);
      }
      else {
        $form_state->setValue('barcode', $barcode);
        $form_state->setValue('patron_id', $response_body->patron_id);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Set Barcode and Patron ID fields, generate API Key
    $uid = $form_state->getValue('uid');

    $account = \Drupal\user\Entity\User::load($uid);
    $account->set('field_barcode', $form_state->getValue('barcode'));
    $account->set('field_patron_id', $form_state->getValue('patron_id'));
    $account->set('field_api_key', arborcat_generate_api_key());
    $account->save();

    drupal_set_message('Successfully added library card barcode to your website account');

    $form_state->setRedirect('entity.user.canonical', ['user' => $uid]);

    return;
  }
}
