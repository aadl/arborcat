<?php

/**
 * @file
 * Contains \Drupal\arborcat_lists\Form\UserListCreateForm
 */

namespace Drupal\arborcat_lists\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

class UserListCreateForm extends FormBase {

  public function getFormId() {
    return 'user_list_create_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = [];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => t('List Title'),
      '#maxlength' => 256,
      '#required' => true
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => t('List Description'),
    ];
    $form['public'] = [
      '#type' => 'checkbox',
      '#title' => 'Public',
      '#description' => t('Allow anyone to see this list?')
    ];
    $form['submit'] = [
      '#prefix' => '<br><br>',
      '#type' => 'submit',
      '#value' => t('Submit')
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $title = $form_state->getValue('title');
    if ($title == 'Checkout History' || $title == 'Wishlist') {
      $form_state->setErrorByName('title', $this->t('Cannot use reserved list title for a custom list, please choose another'));
    }
    $description = $form_state->getValue('description');
    if ($description != strip_tags($description)) {
      $form_state->setErrorByName('description', $this->t('HTML not allowed in List description'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());

    $connection = \Drupal::database();
    $connection->insert('arborcat_user_lists')
      ->fields([
        'uid' => $user->get('uid')->value,
        'pnum' => $user->field_patron_id->value,
        'title' => $form_state->getValue('title'),
        'description' => $form_state->getValue('description'),
        'public' => $form_state->getValue('public')
      ])
      ->execute();

    drupal_set_message('List created!');

    $form_state->setRedirect('arborcat_lists.user_lists', ['uid' => $user->get('uid')->value]);
  }
}
