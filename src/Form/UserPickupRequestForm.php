<?php
namespace Drupal\arborcat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Predis\Client;
use Drupal\arborcat\Controller;
use DateTime;
use DateTimeHelper;

class UserPickupRequestForm extends FormBase {
  public function getFormId() {
    return 'user_pickup_request_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $patron_id = NULL, string $request_location = NULL, string $mode = NULL, string $exclusion_marker_string = NULL) {
    $guzzle = \Drupal::httpClient();
    $api_key = \Drupal::config('arborcat.settings')->get('api_key');
    $api_url = \Drupal::config('arborcat.settings')->get('api_url');
    $patron_info = json_decode((string) $guzzle->get("$api_url/patron?apikey=$api_key&pnum=$patron_id")->getBody()->getContents(), TRUE);
    $uid = $patron_info['evg_user']['card']['id'];
    $account = \Drupal\user\Entity\User::load($uid);
    $patron_barcode = $patron_info['evg_user']['card']['barcode'];
    $eligible_holds = arborcat_load_patron_eligible_holds($patron_barcode, $request_location);

    // Get the locations
    $locations = json_decode($guzzle->get("$api_url/locations")->getBody()->getContents());
    $location_name = $locations->$request_location;

    // check the mode to see whether we need to display "Cancel Mode, rather than pickup request mode
    $cancel_holds = NULL;
    if ('cancel' == $mode) {
      $cancel_holds = 1;
      $submit_text = 'Cancel selected requests';
    } else {
      $submit_text = 'Schedule Pickup';
    }

    $form['#attributes'] = ['class' => 'form-width-exception'];
    // hidden values up here
    $form['uid'] = [
            '#type'=> 'hidden',
            '#default_value' => $uid
        ];

    $form['pnum'] = [
            '#type' => 'hidden',
            '#default_value' => $patron_id
        ];

    $form['patron_barcode'] = [
            '#type' => 'hidden',
            '#default_value' => $patron_barcode
        ];

    $form['branch'] = [
            '#type' => 'hidden',
            '#default_value' => $request_location
        ];

    $form['cancel_holds'] = [
            '#type' => 'hidden',
            '#default_value' => $cancel_holds ?? 0
        ];

    $form['lockeritems'] = [
            '#type' => 'value',
            '#default_value' => $eligible_holds,
            '#required' => TRUE
        ];
    $form['lockercode'] = [
            '#type' => 'value',
            '#default_value' => $patron_info['telephone'],
        ];
    $form['patronname'] = [
            '#type' => 'value',
            '#default_value' => $patron_info['name'],
        ];

    // $form['explanation'] = [
    // 	'#markup'=>"<h2>$branch Request Pickup Form</h2>" .
    // 	"Select items below to request for pickup:"
    // ];
    $header = [
            'Title'=>t('Title'),
            'PickupLoc'=>t('Pickup Location'),
            'artPrintTool'=>t('Art Print/Tool')
        ];

    $keys = array_keys($eligible_holds);

    // create assoc array of the eligible_hold keys in order to check all items in the tableselect if the form is not in cancel mode.
    $selection = [];
    if (!isset($cancel_holds)) {
      foreach ($eligible_holds as $key => $value) {
        $selection[$key] = strval($key);
      }
    }

    $title_string = (isset($cancel_holds)) ? 'Cancel requests for item' : 'Request Contactless Pickup for item';
    $title_string .= (count($eligible_holds) > 1) ? "s" : '';
    $direction_string = '';
    if (count($eligible_holds) > 0) {
      $direction_string = 'Select item';
      $direction_string .= (count($eligible_holds) > 1) ? "s" : '';
      $direction_string .= (isset($cancel_holds)) ? ' below to Cancel' : ' below to request for pickup';
    }
    $prefix_html = '<h2>' . $title_string . ' at ' . $location_name . ' for ' . $patron_barcode . '</h2><br />' .
                                     $direction_string .
                                     '<div><div class="l-inline-b side-by-side-form">';
    $form['item_table']=[
            '#prefix' => $prefix_html,
            '#type'=>'tableselect',
            '#header' => $header,
            '#options' => $eligible_holds,
            '#multiple' => 'TRUE',
            '#empty' => "You have no holds ready for pickup.",
            '#suffix' => '</div>',
            '#default_value' => $selection
        ];

    if (!isset($cancel_holds)) {
      $starting_day_offset = \Drupal::config('arborcat.settings')->get('starting_day_offset');
      $number_of_pickup_days = \Drupal::config('arborcat.settings')->get('number_of_pickup_days');
      $starting_day = new DateTime();
      $starting_day->modify('+1 day');

      // SPECIAL CASE for library-wide closure in order to force starting date to be the first day after the closure if
      // this form is being opened whilst the closure is in operation
      // $opening_date = new DateTime('20-12-09');

      // if ($starting_day < $opening_date) {
      //   $starting_day = $opening_date;
      // }

      $starting_day_plus_pickup_days = clone $starting_day;
      $modifystring = '+' . $number_of_pickup_days - 1 . ' days';
      $starting_day_plus_pickup_days->modify($modifystring);

      $pickup_dates_data = arborcat_get_pickup_dates($request_location, $starting_day->format('Y-m-d'), $starting_day_plus_pickup_days->format('Y-m-d'));
      $form_state->set('exclusionData', $pickup_dates_data);

      $pickup_dates =[];
      foreach ($pickup_dates_data as $data_item_key => $data_item_value) {
        $append_string =  ($data_item_value['date_exclusion_data'] != NULL) ? ' ' . $exclusion_marker_string : '';
        $pickup_dates[$data_item_key] = $data_item_value['display_date_string'] . $append_string;
      }
      $form['pickup_date'] = [
              '#prefix' => '<div class="l-inline-b side-by-side-form">',
              '#type' => 'select',
              '#title' => t('Pickup Dates'),
              '#options' => $pickup_dates,
              '#description' => t('Choose the date to pick up your requests.'),
              '#required' => TRUE
            ];

      $pickup_locations_for_request = arborcat_pickup_locations($request_location);
      $pickup_options =  [];
      $i = 1;
      foreach ($pickup_locations_for_request as $location_object) {
        // need to append the times in human readable form
        $start_time_object = new dateTime($location_object->timePeriodStart);
        $end_time_Object = new dateTime($location_object->timePeriodEnd);
        $time_period_formatted = ', ' . date_format($start_time_object, "ga") . ' to ' . date_format($end_time_Object, "ga");
        // check if this is an overnight time period
        if ($end_time_Object < $start_time_object) {
          $time_period_formatted .= ' (overnight)';
        }
        $name_plus_time_period = $location_object->locationName . $time_period_formatted;
        // concatenate the locationId and the timeslot into the key
        $pickup_options["$location_object->locationId-$location_object->timePeriod"] = $name_plus_time_period;
      }
      $form['pickup_type'] = [
              '#prefix' => '<div class="l-inline-b side-by-side-form">',
              '#type' => 'select',
              '#title' => t("Contactless Pickup Method for $location_name"),
              '#options' => $pickup_options,
              '#description' => t('Select how you would like to pick up your requests. To use a locker, please choose an available timeslot'),
              '#required' => TRUE
            ];

      $form['notification_types'] = [
                '#type' => 'checkboxes',
                '#title' => t('Notification Options'),
                '#options' => [
                    'email' => 'Email',
                    'sms' => 'Text',
                    'phone' => 'Phone Call'
                ],
                '#description' => t('Select which ways you would like to be notified when your request is ready for pickup'),
                '#required' => TRUE,
                '#default_value' => ['email']
            ];

      $form['phone'] = [
                '#type' => 'textfield',
                '#title' => t('Phone Number'),
                '#default_value' => $patron_info['telephone'],
                '#size' => 32,
                '#maxlength' => 64
            ];

      $form['email'] = [
                '#type' => 'textfield',
                '#title' => t('Email'),
                '#default_value' => $patron_info['email'],
                '#size' => 32,
                '#maxlength' => 64
            ];
    }

    $prefix_html = '<span id="submitting">';
    $form['submit'] = [
            '#type' => 'submit',
            '#default_value' => t($submit_text),
            '#prefix' => $prefix_html,
            '#suffix' => '</span>',
            //'#attributes' => ['disabled' => 'disabled']
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!$form_state->getValue('cancel_holds')) {
      // Exclusion date/location handling
      $pickup_date =  $form_state->getValue('pickup_date');
      $pickup_point = (int) explode('-', $form_state->getValue('pickup_type'))[0];

      // if ($pickup_date >= '2020-11-15') {
      //     $form_state->setErrorByName('pickup_date', t('Due to positive COVID tests, all AADL Locations will be closed for at least 2 weeks starting Sunday, Nov. 15th.'));
      // }
      // if (($pickup_point == 1000 || $pickup_point == 1002 || $pickup_point == 1012) && $pickup_date == '2020-11-03') {
      //   $form_state->setErrorByName('pickup_date', t('No appointments are available Downtown or at Pittsfield this day due to Election Day.'));

      // Check for exclusion dates
      $exclusion_data = $form_state->get('exclusionData');
      $exclusion_data_object = $exclusion_data[$pickup_date]['date_exclusion_data'];
      if ($exclusion_data_object) {
        $exclusion_data_array = (array)$exclusion_data_object;
        $exclusion_error_message = $exclusion_data_array['display_reason'];
        if (strlen($exclusion_error_message) > 0) {
          $form_state->setErrorByName('pickup_date', t($exclusion_error_message));
        }
      }

      // check to see if locker pickup
      $lockers = arborcat_locker_pickup_locations();
      $pickup_point = (int) explode('-', $form_state->getValue('pickup_type'))[0];
      if (in_array($pickup_point, $lockers)) {                                        // check if it's a locker pickup request
        $pickup_date =  $form_state->getValue('pickup_date');

        if (!$form_state->getValue('phone')) {
          $form_state->setErrorByName('phone', t('A phone number is required for lockers so we can generate your locker code'));
        }
        $db = \Drupal::database();
        // grab location object to pass to avail check
        $query = $db->select('arborcat_pickup_location', 'apl')
                    ->fields('apl', ['locationId', 'timePeriod', 'maxLockers'])
                    ->condition('locationId', $pickup_point, '=')
                    ->execute();
        $pickup_location = $query->fetch();

        $patron_id = $form_state->getValue('pnum');
        $avail = arborcat_check_locker_availability($pickup_date, $pickup_location, $patron_id);

        // if no avail lockers, set form error
        if (!$avail) {
          $form_state->setErrorByName('pickup_type', t('All lockers are full during the selected time. Please try another time option or day'));
        }
      }
      if ($form_state->getValue('notification_types')['email']) {
        if (!$form_state->getValue('email')) {
          $form_state->setErrorByName('email', t('No email is set, but you requested an email notification.'));
        } elseif (!valid_email_address($form_state->getValue('email'))) {
          $form_state->setErrorByName('email', t('You must enter a valid e-mail address.'));
        }
      }

      if (($form_state->getValue('notification_types')['sms'] || $form_state->getValue('notification_types')['phone']) && !$form_state->getValue('phone')) {
        $form_state->setErrorByName('phone', t('No phone number is set, but you requested a text and/or phone call.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $pickup_date =  $form_state->getValue('pickup_date');

    $messenger = \Drupal::messenger();
    //parse telephone number into seven digit locker code
    $locker_code = $form_state->getValue('lockercode');
    $locker_code = preg_replace("/[\\s\\-()]/", "", $locker_code);
    $locker_code = preg_replace("/^\d\d\d/", "", $locker_code);

    //if no phone number is provided, use a random seven digit number
    if ($locker_code=="") {
      $locker_code = strval(rand(1111111, 9999999));
    }

    $uid = $form_state->getValue('uid');
    $pnum = $form_state->getValue('pnum');
    $patron_barcode = $form_state->getValue('patron_barcode');
    $locker_items= $form_state->getValue('lockeritems');
    $table_values = $form_state->getValue('item_table');
    $notification_types = $form_state->getValue('notification_types');
    $patron_name = $form_state->getValue('patronname');
    $patron_email = $form_state->getValue('email');
    $patron_phone = $form_state->getValue('phone');
    $branch = $form_state->getValue('branch');
    $cancel_holds = $form_state->getValue('cancel_holds');

    // pickup point/location tied in with time slot
    $pickup_locations_for_request = $form_state->get('pickupLocationsForRequest');
    $location_id_time_slot = explode('-', $form_state->getValue('pickup_type'));

    $selected_titles = array_filter($table_values);

    $holds = [];
    foreach ($selected_titles as $key=>$val) {
      array_push($holds, $locker_items[$val]);
    }

    if (count($holds) == 0) {
      $messenger->addError(t("There are no request items selected."));
    } else {  // got at least one hold to be processed
      // Check for the number of items and whether they will fit in the selected locker
      $locker_item_max_count = \Drupal::config('arborcat.settings')->get('max_locker_items_check');
      $guzzle = \Drupal::httpClient();
      $api_key = \Drupal::config('arborcat.settings')->get('api_key');
      $api_url = \Drupal::config('arborcat.settings')->get('api_url');
      $self_check_api_key = \Drupal::config('arborcat.settings')->get('selfcheck_key');

      // Get the locations
      $locations = json_decode($guzzle->get("$api_url/locations")->getBody()->getContents());

      if ($location_id_time_slot[1] > 0 && count($holds) > $locker_item_max_count) {
        $submit_message = 'You selected more than ' . $locker_item_max_count . ' items for locker pickup on ' . date('F j', strtotime($pickup_date)) . ' at the ' . $locations->{$branch} . '. ';
        $submit_message .= 'If all the items do not fit in the locker, the remaining items will be placed in the ' . $locations->{$branch} . ' lobby';
        $messenger->addWarning($submit_message);
      }
      $error_count = 0;
      foreach ($holds as $hold) {
        if ($cancel_holds) {
          $date_time_now = new DateTime('now');
          $cancel_time = $date_time_now->format('Y-m-d H:i:s');
          $guzzle->get("$api_url/patron/$self_check_api_key-$patron_barcode/update_hold/" . $hold['holdId'] . "?cancel_time=$cancel_time&cancel_cause=6")->getBody()->getContents();
        } else {
          // set the expire date for each selected hold
          $updated_hold = $guzzle->get("$api_url/patron/$self_check_api_key-$patron_barcode/update_hold/" . $hold['holdId'] . "?shelf_expire_time=$pickup_date 23:59:59")->getBody()->getContents();
          // create arborcat_patron_pickup_request records for each of the selected holds
          $result = arborcat_create_pickup_request_record(
            'HOLD_REQUEST',
            $hold['holdId'],
            $pnum,
            $branch,
            $location_id_time_slot[1],
            $location_id_time_slot[0],
            $pickup_date,
            ($notification_types['email'] ? $patron_email : NULL),
            ($notification_types['sms'] ? $patron_phone : NULL),
            ($notification_types['phone'] ? $patron_phone : NULL),
            $patron_phone ?? NULL
          );

          if ($result < 0) {
            $error_count += 1;
          }
        }
      }
      if ($error_count == 0) {
        $submit_message = ($cancel_holds ? 'Your requests were successfully canceled' : 'Pickup appointment scheduled for ' . date('F j', strtotime($pickup_date)) . ' at ' . $locations->{$branch});
        $messenger->addMessage($submit_message);

        $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
        $uid = $user->id();
        $url = \Drupal\Core\Url::fromRoute('entity.user.canonical', ['user'=>$user->id()]);

        return $form_state->setRedirectUrl($url);
      } else {
        $messenger->addMessage("There was an error processing your pickup requests. Please try submitting the form again");
      }
    }
  }

  public function arborcat_mail($key, $email_to, $patron, $code, $holds) {
    if ($key == 'locker_requests') {
      if ($email_to == 'mcblockers@aadl.org') {
        $location = 'malletts';
        $link = \Drupal::config('arborcat.settings')->get('mcb_lockers');
      } else {
        // Default to Pitts
        $location = 'pittsfield';
        $link = \Drupal::config('arborcat.settings')->get('pts_lockers_insert');
      }
      $email_headers = "From: support@aadl.org";
      $email_subject = 'Locker requested';
      $email_message = "A patron has requested a locker pickup. Please follow the link below to reserve a locker:\r\n\r\n".$link."\r\n\r\n".
            "If the locker reservation is successful, then place the requested items in the assigned locker using the below information:\r\n".
            "Patron name: "."$patron\r\n"."Locker code: ".$code."\r\n\r\nRequested items:\r\n";
      foreach ($holds as $title) {
        $email_message = $email_message.$title['Title']."\r\n";
      }
      $mail_manager = \Drupal::service('plugin.manager.mail');
      mail($email_to, $email_subject, $email_message, $email_headers);
    }
  }
}
