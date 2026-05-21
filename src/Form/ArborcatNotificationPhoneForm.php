<?php

/**
 * @file
 * Contains \Drupal\arborcat\Form\ArborcatNotificationForm.
 */

namespace Drupal\arborcat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class ArborcatNotificationPhoneForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'arborcat_notification_phone_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $uid = NULL) {
    $form = [];

    // Check access to Account
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    if ($user->get('uid')->value == $uid ||
        $user->hasPermission('administer users')) {

      // Get Library Cards associated with this account
      $account = \Drupal\user\Entity\User::load($uid);
      $field_barcodes = $account->get('field_barcode')->getValue();
      
      $sub_delta = $_GET['subaccount'] ?? 0;
      if(isset($_GET['subaccount'])){
        $barcode = $field_barcodes[$sub_delta];
        $field_barcodes = [];
        $field_barcodes[$sub_delta] = $barcode;
      }


      if (count($field_barcodes)) {
        $form['account'] = [
          '#type' => 'value',
          '#value' => $account,
        ];

        $guzzle = \Drupal::httpClient();
        $api_url = \Drupal::config('arborcat.settings')->get('api_url');
        $api_keys = $account->get('field_api_key')->getValue();

        $form['phones'] = [
          '#prefix' => '<h2>Update Notification Phone Number</h2>',
        ];

        // Check for existing barcodes and list with option to remove
        foreach ($field_barcodes as $delta => $field_barcode) {
          $field_barcode = $field_barcode['value'];

          // Get corresponding API Key
          $api_key = $api_keys[$delta]['value'];

          // Pull Patron Data
          $patron = FALSE;
          try {
            $patron = json_decode($guzzle->get("$api_url/patron/$api_key/get")->getBody()->getContents());
          }
          catch (\Exception $e) {
            \Drupal::messenger()->addError('Error retrieving patron data for ' . $field_barcode);
          }

          /*var_dump($patron);
          exit();*/

          if ($patron) {
            $form['phone_' . $delta] = [
              '#type' => 'textfield',
              '#title' => "$patron->name Notification Phone Number",
              '#default_value' => $patron->evg_user->evening_phone, // User's current email
              '#size' => 32,
              '#maxlength' => 32,
              '#description' => t('Phone Number for Library Card') . ' #' . $patron->card,
            ];
          }
        }
        $form['actions']['update_phones'] = [
          '#type' => 'submit',
          '#value' => $this->t('Update Phone'),
          '#button_type' => 'primary',
        ];
        $form['actions']['cancel'] = [
          '#type' => 'link',
          '#title' => $this->t('Cancel'),
          '#url' => \Drupal\Core\Url::fromRoute('entity.user.canonical', ['user' => $uid]),
        ];
      }
      else {
        \Drupal::messenger()->addError('No Library Cards associated with this account');
        return $this->redirect('entity.user.canonical', ['user' => $uid]);
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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $account = $values['account'];
    $barcodes = $account->get('field_barcode')->getValue();
    $api_keys = $account->get('field_api_key')->getValue();

    foreach ($api_keys as $delta => $field) {
      // Check if phone value has been changed
      if ($form['phone_' . $delta]['#default_value'] != $values['phone_' . $delta]) {
        // Update phone with api key
        $api_key = $field['value'];
        $guzzle = \Drupal::httpClient();
        $api_url = \Drupal::config('arborcat.settings')->get('api_url');
        $query = ['evening_phone' => $values['phone_' . $delta]];

        $response = $guzzle->request('GET', "$api_url/patron/$api_key/set", ['query' => $query]);

        \Drupal::messenger()->addMessage('Set ' . $values['phone_' . $delta] .
                           ' as notification phone for Library Card #' . $barcodes[$delta]['value']);
      }
    }

    $form_state->setRedirect('entity.user.canonical', ['user' => $values['account']->get('uid')->value]);

    return;
  }
}
