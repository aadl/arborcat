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

    // Check access to Account
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    if ($user->get('uid')->value == $uid ||
        $user->hasPermission('administer users')) {

      $account = \Drupal\user\Entity\User::load($uid);
      $form['account'] = [
        '#type' => 'value',
        '#value' => $account,
      ];
      $form['barcode'] = [
        '#prefix' => '<div class="barcode-form"><h2>Add New Barcode</h2>',
        '#type' => 'textfield',
        '#title' => t('Library Card Barcode'),
        '#default_value' => '', // User's current Barcode
        '#size' => 32,
        '#maxlength' => 32,
        '#description' => t('Your Library Card Barcode number for user ' . $uid),
      ];
      $form['patron_data'] = [
        '#prefix' => '<div id="barcode-form-patron-data"><h3>Validate Barcode with ONE of the following items</h3>',
        '#suffix' => '</div>',
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

      $form['actions']['add_barcode'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Add Barcode'),
        '#button_type' => 'primary',
      );
      $form['actions']['cancel'] = [
        '#suffix' => '</div>',
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => \Drupal\Core\Url::fromRoute('entity.user.canonical', ['user' => $uid]),
      ];

      // Add Barcode Buttons if account has existing barcodes
      $field_barcodes = $account->get('field_barcode')->getValue();

      if (count($field_barcodes)) {
        $guzzle = \Drupal::httpClient();
        $api_url = \Drupal::config('arborcat.settings')->get('api_url');
        $api_keys = $account->get('field_api_key')->getValue();

        $form['existing_barcodes'] = [
          '#prefix' => '<div class="barcode-form"><h2>Existing Barcodes</h2>',
          '#suffix' => '</div>',
          'barcodes' => [
            '#type' => 'value',
          ]
        ];

        // Check for existing barcodes and list with option to remove
        foreach ($field_barcodes as $delta => $field_barcode) {
          $field_barcode = $field_barcode['value'];

          // Get corresponding API Key
          $api_key = $api_keys[$delta]['value'];

          // Pull Patron Data
          $api_url = \Drupal::config('arborcat.settings')->get('api_url');
          $patron = FALSE;
          try {
            $patron = json_decode($guzzle->get("$api_url/patron/$api_key/get")->getBody()->getContents());
          }
          catch (\Exception $e) {
            \Drupal::messenger()->addError('Error retrieving patron data for ' . $field_barcode);
          }

          if ($patron) {
            $form['existing_barcodes']['barcodes']['#value'][] = $field_barcode;
            $form['existing_barcodes']['remove_barcode_' . $delta] = [
              '#prefix' => "<p>$field_barcode, $patron->name</p>",
              '#suffix' => ($delta == 0 ? '<hr>' : ''),
              '#type' => 'submit',
              '#value' => "Remove $field_barcode from account",
              '#submit' => [[$this, 'removeBarcodeSubmit']],
            ];
            if ($delta > 0) {
              $form['existing_barcodes']['make_primary_barcode_' . $delta] = [
                '#suffix' => '<hr>',
                '#type' => 'submit',
                '#value' => "Set $field_barcode as primary",
                '#submit' => [[$this, 'primaryBarcodeSubmit']],
              ];
            }
          }
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
    $trigger = $form_state->getTriggeringElement();
    if ($trigger['#id'] == 'edit-add-barcode') {
      $barcode = preg_replace('/[^0-9]/', '', $form_state->getValue('barcode'));
      // Make sure barcode is correct format
      if (!preg_match('/21621[0-9]{9}/', $barcode)) {
        $form_state->setErrorByName('barcode', $this->t('Invalid format. Barcodes are 14 digits long and start with "21621"'));
      }
      else {
        // Make sure barcode isn't already attached to Account
        if (in_array($barcode, $form_state->getValue('barcodes'))) {
          $form_state->setErrorByName('barcode', $this->t('Barcode already attached to account'));
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
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Set Barcode and Patron ID fields, generate API Key
    $account = $form_state->getValue('account');
    $account->field_barcode[] = $form_state->getValue('barcode');
    $account->field_patron_id[] = $form_state->getValue('patron_id');
    $account->field_api_key[] = arborcat_generate_api_key();    
    $account->save();

    $uid = $account->get('uid')->value;

    $user = \Drupal\user\Entity\User::load($uid);
    $additional_accounts = arborcat_additional_accounts($user);
    $last_account = end($additional_accounts);

    // only create an empty list for Checkout History if the primary account has "Record Checkouts" checked in preferences
    if ($user->get('profile_cohist')->value) {
      $db = \Drupal::database();
      // Create a new Checkout History list
      $description_text = ($last_account['delta'] > 0) ? $last_account['subaccount']->name . "'s Checkout History" : "My Checkout History";
      $list_id = $db->insert('arborcat_user_lists')
        ->fields(['uid' => $uid, 'pnum' => $last_account['patron_id'], 'title' => 'Checkout History', 'description' => $description_text])
        ->execute();
    }

    \Drupal::messenger()->addMessage('Successfully added library card barcode to your website account');

    $form_state->setRedirect('entity.user.canonical', ['user' => $uid]);

    return;
  }

  public function removeBarcodeSubmit(array &$form, FormStateInterface $form_state) {
    $te = $form_state->getTriggeringElement();
    $delta = str_replace('edit-remove-barcode-', '', $te['#id']);

    $account = $form_state->getValue('account');
    $account_uid = $account->get('uid')->value;
    $field_patron_ids = $account->get('field_patron_id')->getValue();
    $additional_pnum = $field_patron_ids[$delta]['value'];

    unset($account->field_barcode[$delta]);
    unset($account->field_patron_id[$delta]);
    unset($account->field_api_key[$delta]);
    $account->save();

    $info_message = 'Successfully removed barcode from your website account';
    $result = arborcat_lists_remove_checkout_history($account_uid, $additional_pnum);

    $info_message .= ($result['success'] == true) ? ' and the associated Checkout History list' : '';

    \Drupal::messenger()->addMessage($info_message);

    $form_state->setRedirect('entity.user.canonical', ['user' => $account_uid]);

    return;
  }

  public function primaryBarcodeSubmit(array &$form, FormStateInterface $form_state) {
    $te = $form_state->getTriggeringElement();
    $top_delta = str_replace('edit-make-primary-barcode-', '', $te['#id']);

    $account = $form_state->getValue('account');

    // Reorder Barcodes
    $field_barcodes = $account->get('field_barcode')->getValue();
    $new_field_barcodes = [$field_barcodes[$top_delta]['value']];
    foreach ($field_barcodes as $delta => $field_barcode) {
      if ($delta != $top_delta) {
        $new_field_barcodes[] = $field_barcode['value'];
      }
    }
    unset($account->field_barcode);
    foreach ($new_field_barcodes as $new_field_barcode) {
      $account->field_barcode[] = $new_field_barcode;
    }

    // Reorder Patron IDs
    $field_patron_ids = $account->get('field_patron_id')->getValue();
    $new_field_patron_ids = [$field_patron_ids[$top_delta]['value']];
    foreach ($field_patron_ids as $delta => $field_patron_id) {
      if ($delta != $top_delta) {
        $new_field_patron_ids[] = $field_patron_id['value'];
      }
    }
    unset($account->field_patron_id);
    foreach ($new_field_patron_ids as $new_field_patron_id) {
      $account->field_patron_id[] = $new_field_patron_id;
    }

    // Reorder API Keys
    $field_api_keys = $account->get('field_api_key')->getValue();
    $new_field_api_keys = [$field_api_keys[$top_delta]['value']];
    foreach ($field_api_keys as $delta => $field_api_key) {
      if ($delta != $top_delta) {
        $new_field_api_keys[] = $field_api_key['value'];
      }
    }
    unset($account->field_api_key);
    foreach ($new_field_api_keys as $new_field_api_key) {
      $account->field_api_key[] = $new_field_api_key;
    }
    $account->save();

    \Drupal::messenger()->addMessage('Successfully reordered barcodes');

    return;
  }
}
