<?php

/**
 * @file
 * Contains \Drupal\arborcat\Form\UserRecordReviewForm
 */

namespace Drupal\arborcat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

class UserRecordReviewForm extends FormBase {

  public function getFormId() {
    return 'user_record_review_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $bib = NULL) {
    $connection = \Drupal::database();
    $query = $connection->query("SELECT * FROM arborcat_reviews WHERE uid=:uid AND bib=:bib",
        [':uid' => \Drupal::currentUser()->id(), ':bib' => $bib]);
    $review = $query->fetchObject();

    $form = [];
    $form['#attributes'] = ['class' => ['form-width-exception']];
    $form['id'] = [
      '#type' => 'hidden',
      '#default_value' => ($review->id ?? '')
    ];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => 'Review Title',
      '#maxlength' => 128,
      '#required' => true,
      '#default_value' => ($review->title ?? '')
    ];
    $form['review'] = [
      '#type' => 'textarea',
      '#title' => t('Your Review'),
      '#rows' => 7,
      '#required' => true,
      '#default_value' => ($review->review ?? '')
    ];
    $form['bib'] = [
      '#type' => 'hidden',
      '#default_value' => $bib
    ];
    $form['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Submit'),
    ];

    // honeypot to hopefully stop spam
    if (\Drupal::moduleHandler()->moduleExists('honeypot')) {
      honeypot_add_form_protection($form, $form_state, ['honeypot', 'time_restriction']);
    }

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();
    
    if ($form_state->getValue('id')) {
      $connection->update('arborcat_reviews')
        ->condition('id', $form_state->getValue('id'), '=')
        ->fields([
          'title' => $form_state->getValue('title'),
          'review' => $form_state->getValue('review'),
          'edited' => time()
        ])
        ->execute();

      drupal_set_message('Review updated!');
    } else {
      $connection->insert('arborcat_reviews')
        ->fields([
          'uid' => $user->get('uid')->value,
          'bib' => $form_state->getValue('bib'),
          'title' => $form_state->getValue('title'),
          'review' => $form_state->getValue('review'),
          'created' => time(),
          'edited' => time(),
          'staff_reviewed' => 0,
          'helpful_ratings' => 0
        ])
        ->execute();

      drupal_set_message('Review created!');
    }
  }
}
