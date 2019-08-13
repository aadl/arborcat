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

  public function buildForm(array $form, FormStateInterface $form_state, $lid = NULL) {
    if ($lid) {
      $connection = \Drupal::database();
      $query = $connection->query("SELECT * FROM arborcat_user_lists WHERE id=:id",
        [':id' => $lid]);
      $res = $query->fetchAll()[0];
      $uid = \Drupal::currentUser()->id();
      $user = \Drupal\user\Entity\User::load($uid);
      if ($res->uid != $uid && !$user->hasPermission('administer users')) {
        drupal_set_message("You cannot edit another user's list", 'error');
        return $this->redirect('arborcat_lists.user_lists', ['uid' => $uid]);
      }
    }
    $form = [];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => t('List Title'),
      '#maxlength' => 256,
      '#required' => true,
      '#default_value' => (isset($res) ? $res->title : '')
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => t('List Description'),
      '#default_value' => (isset($res) ? $res->description : '')
    ];
    $form['public'] = [
      '#type' => 'checkbox',
      '#title' => 'Public',
      '#description' => t('Allow anyone to see this list?'),
      '#default_value' => (isset($res) ? $res->public : '')
    ];
    $form['id'] = [
      '#type' => 'hidden',
      '#default_value' => $lid
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
    if ($form_state->getValue('id')) {
      $connection->update('arborcat_user_lists')
        ->condition('id', $form_state->getValue('id'), '=')
        ->fields([
          'title' => $form_state->getValue('title'),
          'description' => $form_state->getValue('description'),
          'public' => $form_state->getValue('public')
        ])
        ->execute();

      drupal_set_message('List edited!');
    } else {
      $connection->insert('arborcat_user_lists')
        ->fields([
          'uid' => $user->get('uid')->value,
          'pnum' => ($user->field_patron_id->value ?? 0),
          'title' => $form_state->getValue('title'),
          'description' => $form_state->getValue('description'),
          'public' => $form_state->getValue('public')
        ])
        ->execute();

      drupal_set_message('List created!');
    }
    $form_state->setRedirect('arborcat_lists.user_lists', ['uid' => $user->get('uid')->value]);
  }
}
