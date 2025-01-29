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

      try {

        //send some notifications
        // <matcode>:<email>,<email>,<email>|<matcode>:<email>,<email>,<email>

        $raw_emails_mat_codes = \Drupal::config('arborcat.settings')->get('review_notifications_by_mat_code');
        $raw_emails_mat_codes_stripped = preg_replace('/\s+/', '', $raw_emails_mat_codes);
        $sections = explode("|", $raw_emails_mat_codes_stripped);
        $data = json_decode("{}");
        if (count($sections) > 0) {
          foreach($sections as $section){
            $arr = explode(":", $section);
            if (isset($arr[0]) && isset($arr[1])) {
              $emails = explode(",", $arr[1]);
              $data->{$arr[0]} = $emails;
            }
          }
        }

        //need to get the matt name from the bib somehow?
        // $bib_record->mat_code
        $api_url = \Drupal::config('arborcat.settings')->get('api_url');
        $bib_id = $form_state->getValue('bib');

        // set the api get record request to use either the api record "full" or "harvest" call depending whether
        // the api being called is the development/testing version of the api located on pinkeye
        $use_harvest_option = \Drupal::config('arborcat.settings')->get('api_use_harvest_option_for_bib');
        $get_record_selector = ($use_harvest_option == true) ? 'harvest' : 'full';
        $get_url = "$api_url/record/$bib_id/$get_record_selector";

        $guzzle = \Drupal::httpClient();
        try {
          $json = json_decode($guzzle->get($get_url)->getBody()->getContents());
          if ($get_record_selector == 'harvest') {
            $bib_record = $json->bib;
          }
          else {
            $bib_record = $json;
          }
        } catch (\Exception $e) {
          $bib_record->_id = NULL;
        }

        if (isset($data->{$bib_record->mat_code})) {
          //send the emails
          foreach($data->{$bib_record->mat_code} as $email_address){
             $headers = "From: notifications@aadl.org" . "\r\n" .
             "Reply-To: notifications@aadl.org" . "\r\n" .
             "X-Mailer: PHP/" . phpversion() .
             "Content-Type: text/html; charset=\"us-ascii\"";

             mail($email_address, "Review Submitted Notification", "A review was submitted for mat_code: $bib_record->mat_code on BIB record $bib_id", $headers);
          }
        }
      } catch (\Exception $e) {

      }



    }
  }
}
