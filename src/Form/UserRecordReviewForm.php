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

  public function buildForm(array $form, FormStateInterface $form_state, $bib = NULL, $bib_title = NULL) {
    $connection = \Drupal::database();
    $query = $connection->query("SELECT * FROM arborcat_reviews WHERE uid=:uid AND bib=:bib",
        [':uid' => \Drupal::currentUser()->id(), ':bib' => $bib]);
    $review = $query->fetchObject();

    $prefix = (isset($review->id) ? '<h3 class="no-margin-bottom">EDIT YOUR REVIEW</h3>' : '<h3 class="no-margin-bottom">WRITE A REVIEW</h3>');
    $form = [];
    $form['#attributes'] = ['class' => ['form-width-exception']];
    $form['id'] = [
      '#type' => 'hidden',
      '#default_value' => ($review->id ?? ''),
      '#prefix' => $prefix
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
    $form['bib_title'] = [
      '#type' => 'hidden',
      '#default_value' => ($bib_title ?? '')
    ];
    $form['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Submit'),
    ];

    // honeypot to hopefully stop spam
    if (\Drupal::moduleHandler()->moduleExists('honeypot')) {
      \Drupal::service('honeypot') ->addFormProtection($form, $form_state, ['honeypot', 'time_restriction']);
    }

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {

  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();

    // check to make sure user review is unique from their other reviews
    $unique = $connection->query("SELECT * FROM arborcat_reviews WHERE uid=:uid AND review=:review",
      [':uid' => $user->get('uid')->value, ':review' => $form_state->getValue('review')])->fetch();
    if ($unique->id && !$form_state->getValue('id')) {
      \Drupal::messenger()->addError("Sorry, you've already submitted that review for another item.");
      return;
    }

    if ($form_state->getValue('id')) {
      $connection->update('arborcat_reviews')
        ->condition('id', $form_state->getValue('id'), '=')
        ->fields([
          'title' => $form_state->getValue('title'),
          'review' => $form_state->getValue('review'),
          'edited' => time(),
          'staff_reviewed' => 0,
          'deleted' => 0
        ])
        ->execute();

      \Drupal::messenger()->addMessage('Review updated!');
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

      \Drupal::messenger()->addMessage('Review created!');

      if (\Drupal::moduleHandler()->moduleExists('summergame')) {
        if (\Drupal::config('summergame.settings')->get('summergame_points_enabled')) {
          if ($player = summergame_get_active_player()) {
            $type = 'Wrote Review';
            $description = $form_state->getValue('bib_title');
            $metadata = 'bnum:' . $form_state->getValue('bib');
            $result = summergame_player_points($player['pid'], 100, $type, $description, $metadata);
            \Drupal::messenger()->addMessage("You earned $result points for writing a review!");
          }
        }
      }
    }
  }

}
