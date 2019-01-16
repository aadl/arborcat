<?php

/**
 * @file
 * Contains \Drupal\arborcat\Form\ArborcatNotificationForm.
 */

namespace Drupal\arborcat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class ArborcatNotificationForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'arborcat_notification_form';
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

      if (count($field_barcodes)) {
        $form['account'] = [
          '#type' => 'value',
          '#value' => $account,
        ];

        $guzzle = \Drupal::httpClient();
        $api_url = \Drupal::config('arborcat.settings')->get('api_url');
        $api_keys = $account->get('field_api_key')->getValue();

        $form['emails'] = [
          '#prefix' => '<h2>Update Catalog Notification Email</h2>',
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
            drupal_set_message('Error retrieving patron data for ' . $field_barcode, 'error');
          }

          if ($patron) {
            $form['emails']['email_' . $delta] = [
              '#type' => 'email',
              '#title' => "$patron->name Notification Email",
              '#default_value' => $patron->email, // User's current email
              '#size' => 32,
              '#maxlength' => 32,
              '#description' => t('Email for Library Card') . ' #' . $patron->card,
            ];
          }
        }
        $form['actions']['update_emails'] = [
          '#type' => 'submit',
          '#value' => $this->t('Update Email'),
          '#button_type' => 'primary',
        ];
        $form['actions']['cancel'] = [
          '#type' => 'link',
          '#title' => $this->t('Cancel'),
          '#url' => \Drupal\Core\Url::fromRoute('entity.user.canonical', ['user' => $uid]),
        ];
      }
      else {
        drupal_set_message('No Library Cards associated with this account', 'error');
        return $this->redirect('entity.user.canonical', ['user' => $uid]);
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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $account = $values['account'];
    $barcodes = $account->get('field_barcode')->getValue();
    $api_keys = $account->get('field_api_key')->getValue();

    foreach ($api_keys as $delta => $field) {
      // Check if email value has been changed
      if ($form['emails']['email_' . $delta]['#default_value'] != $values['email_' . $delta]) {
        // Update mail with api key
        $api_key = $field['value'];
        $guzzle = \Drupal::httpClient();
        $api_url = \Drupal::config('arborcat.settings')->get('api_url');
        $query = ['email' => $values['email_' . $delta]];

        $response = $guzzle->request('GET', "$api_url/patron/$api_key/set", ['query' => $query]);

        drupal_set_message('Set ' . $values['email_' . $delta] .
                           ' as notification email for Library Card #' . $barcodes[$delta]['value']);
      }
    }

    $form_state->setRedirect('entity.user.canonical', ['user' => $values['account']->get('uid')->value]);

    return;
  }
}
