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

  public function buildForm(array $form, FormStateInterface $form_state, string $patronId = NULL, string $requestLocation = NULL, string $mode = NULL) {
    $guzzle = \Drupal::httpClient();
    $api_key = \Drupal::config('arborcat.settings')->get('api_key');
    $api_url = \Drupal::config('arborcat.settings')->get('api_url');
    $patron_info = json_decode((string) $guzzle->get("$api_url/patron?apikey=$api_key&pnum=$patronId")->getBody()->getContents(), TRUE);
    $uid = $patron_info['evg_user']['card']['id'];
    $account = \Drupal\user\Entity\User::load($uid);
    $patron_barcode = $patron_info['evg_user']['card']['barcode'];
    $eligible_holds = arborcat_load_patron_eligible_holds($patron_barcode, $requestLocation);
        
    $startingDayOffset = 1; // Load these from ArborCat Settings?
    $numPickupDays = 7;     // Load these from ArborCat Settings?
    $startingDay = new DateTime('+' . $startingDayOffset . ' day');
    $startingDayPlusPickupDays = clone $startingDay;
    $startingDayPlusPickupDays->modify('+' . $numPickupDays . ' days');

    // Get the exclusion data for the requested branch locaton whilst the form is being built. Store as a form_state variable for use in the validateForm method.
    $exclusionData = arborcat_load_exclusion_data($requestLocation, $startingDay->format('Y-m-d'), $startingDayPlusPickupDays->format('Y-m-d'));
    $form_state->set('exclusionData', $exclusionData);

    dblog('buildForm: exclusionData = ', $form_state->get('exclusionData'));

    // Get the locations
    $locations = json_decode($guzzle->get("$api_url/locations")->getBody()->getContents());
    $locationName = $locations->$requestLocation;

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
            '#default_value' => $patronId
        ];

    $form['patron_barcode'] = [
            '#type' => 'hidden',
            '#default_value' => $patron_barcode
        ];

    $form['branch'] = [
            '#type' => 'hidden',
            '#default_value' => $requestLocation
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

    $titleString = (isset($cancel_holds)) ? 'Cancel requests for item' : 'Request Contactless Pickup for item';
    $titleString .= (count($eligible_holds) > 1) ? "s" : '';
    $directionString = '';
    if (count($eligible_holds) > 0) {
      $directionString = 'Select item';
      $directionString .= (count($eligible_holds) > 1) ? "s" : '';
      $directionString .= (isset($cancel_holds)) ? ' below to Cancel' : ' below to request for pickup';
    }
    $prefixHTML = '<h2>' . $titleString . ' at ' . $locationName . ' for ' . $patron_barcode . '</h2><br />' .
                                     $directionString .
                                     '<div><div class="l-inline-b side-by-side-form">';
    $form['item_table']=[
            '#prefix' => $prefixHTML,
            '#type'=>'tableselect',
            '#header' => $header,
            '#options' => $eligible_holds,
            '#multiple' => 'TRUE',
            '#empty' => "You have no holds ready for pickup.",
            '#suffix' => '</div>',
            '#default_value' => $selection
        ];
 
    if (!isset($cancel_holds)) {
      // Populate the possible pickup dates popup menu for the requested pickup location
      $pickupdates = $this->calculate_pickup_dates($requestLocation, $exclusionData);
      $form['pickup_date'] = [
              '#prefix' => '<div class="l-inline-b side-by-side-form">',
              '#type' => 'select',
              '#title' => t('Available Pickup Dates'),
              '#options' => $pickupdates,
              '#description' => t('Choose the date to pick up your requests.'),
              '#required' => TRUE
            ];

      $pickupLocationsForRequest = arborcat_pickup_locations($requestLocation);
      $selectedDate = '';
      $pickupOptions =  [];
      $i = 1;
      foreach ($pickupLocationsForRequest as $locationObj) {
        $addLocation = TRUE;
        if (TRUE == $addLocation) {
          // need to append the times in human readable form
          $starttimeObj = new dateTime($locationObj->timePeriodStart);
          $st = date_format($starttimeObj, "h:ia");
          $endtimeObj = new dateTime($locationObj->timePeriodEnd);
          $timePeriodFormatted = ', ' . date_format($starttimeObj, "ga") . ' to ' . date_format($endtimeObj, "ga");
          // check if this is an overnight time period
          if ($endtimeObj < $starttimeObj) {
            $timePeriodFormatted .= ' (overnight)';
          }
          $namePlusTimePeriod = $locationObj->locationName . $timePeriodFormatted;
          // concatenate the locationId and the timeslot into the key
          $pickupOptions["$locationObj->locationId-$locationObj->timePeriod"] = $namePlusTimePeriod;
        }
      }
      $form['pickup_type'] = [
              '#prefix' => '<div class="l-inline-b side-by-side-form">',
              '#type' => 'select',
              '#title' => t("Contactless Pickup Method for $locationName"),
              '#options' => $pickupOptions,
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

    $prefixHTML = '<span id="submitting">';
    $form['submit'] = [
            '#type' => 'submit',
            '#default_value' => t($submit_text),
            '#prefix' => $prefixHTML,
            '#suffix' => '</span>',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!$form_state->getValue('cancel_holds')) {
      // Exclusion date/location handling
      $pickup_date =  $form_state->getValue('pickup_date');
      $pickup_point = (int) explode('-', $form_state->getValue('pickup_type'))[0];

      $exclusionData = $form_state->get('exclusionData');
      dblog('validateForm: exclusionData = ', $exclusionData);

      if ($pickup_point == 1000 && ($pickup_date >= '2020-10-11' && $pickup_date <= '2020-10-12')) {
          $form_state->setErrorByName('pickup_date', t('The Downtown Library will be closed due to utility issues on October 11th and 12th.'));
      }
      if (($pickup_point == 1000 || $pickup_point == 1002 || $pickup_point == 1012) && $pickup_date == '2020-08-04') {
        $form_state->setErrorByName('pickup_date', t('No appointments are available Downtown or at Pittsfield this day due to Election Day.'));
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
                
        $patronId = $form_state->getValue('pnum');
        $avail = arborcat_check_locker_availability($pickup_date, $pickup_location, $patronId);

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
    $lockercode = $form_state->getValue('lockercode');
    $lockercode = preg_replace("/[\\s\\-()]/", "", $lockercode);
    $lockercode = preg_replace("/^\d\d\d/", "", $lockercode);

    //if no phone number is provided, use a random seven digit number
    if ($lockercode=="") {
      $lockercode = strval(rand(1111111, 9999999));
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
    $pickup_timeslot = explode('-', $form_state->getValue('pickup_type'));

    $pickupLocationsForRequest = $form_state->get('pickupLocationsForRequest');
    $locationId_timeslot = explode('-', $form_state->getValue('pickup_type'));

    $selected_titles = array_filter($table_values);

    $holds = [];
    foreach ($selected_titles as $key=>$val) {
      array_push($holds, $locker_items[$val]);
    }

    if (count($holds) == 0) {
      $messenger->addError(t("There are no request items selected."));
    } else {  // got at least one hold to be processed
      // Check for the number of items and whether they will fit in the selected locker
      $lockerItemMaxCount = \Drupal::config('arborcat.settings')->get('max_locker_items_check');
      $guzzle = \Drupal::httpClient();
      $api_key = \Drupal::config('arborcat.settings')->get('api_key');
      $api_url = \Drupal::config('arborcat.settings')->get('api_url');
      $selfCheckApi_key = \Drupal::config('arborcat.settings')->get('selfcheck_key');
 
      // Get the locations
      $locations = json_decode($guzzle->get("$api_url/locations")->getBody()->getContents());

      if ($locationId_timeslot[1] > 0 && count($holds) > $lockerItemMaxCount) {
        $submit_message = 'You selected more than ' . $lockerItemMaxCount . ' items for locker pickup on ' . date('F j', strtotime($pickup_date)) . ' at the ' . $locations->{$branch} . '. ';
        $submit_message .= 'If all the items do not fit in the locker, the remaining items will be placed in the ' . $locations->{$branch} . ' lobby';
        $messenger->addWarning($submit_message);
      }

      foreach ($holds as $hold) {
        if ($cancel_holds) {
          $cancel_time = date('Y-m-d');
          $guzzle->get("$api_url/patron/$selfCheckApi_key-$patron_barcode/update_hold/" . $hold['holdId'] . "?cancel_time=$cancel_time&cancel_cause=6")->getBody()->getContents();
        } else {
          // set the expire date for each selected hold
          $updated_hold = $guzzle->get("$api_url/patron/$selfCheckApi_key-$patron_barcode/update_hold/" . $hold['holdId'] . "?shelf_expire_time=$pickup_date 23:59:59")->getBody()->getContents();
          // create arborcat_patron_pickup_request records for each of the selected holds
          arborcat_create_pickup_request_record(
            'HOLD_REQUEST',
            $hold['holdId'],
            $pnum,
            $branch,
            $locationId_timeslot[1],
            $locationId_timeslot[0],
            $pickup_date,
            ($notification_types['email'] ? $patron_email : NULL),
            ($notification_types['sms'] ? $patron_phone : NULL),
            ($notification_types['phone'] ? $patron_phone : NULL),
            $patron_phone ?? NULL
          );
        }
      }
      $submit_message = ($cancel_holds ? 'Your requests were successfully canceled' : 'Pickup appointment scheduled for ' . date('F j', strtotime($pickup_date)) . ' at ' . $locations->{$branch});
      $messenger->addMessage($submit_message);
 
      $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
      $uid = $user->id();
      $url = \Drupal\Core\Url::fromRoute('entity.user.canonical', ['user'=>$user->id()]);

      return $form_state->setRedirectUrl($url);
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
      $mailManager = \Drupal::service('plugin.manager.mail');
      mail($email_to, $email_subject, $email_message, $email_headers);
    }
  }

  private function calculateLobbyPickupDates() {
    $arrayOfDates = [];
    // get the current date
    $theDate = new DateTime('today');
    // add 1 day to the current date
        $startingDayOffset = 1; // Load these from ArborCat Settings?
        $numPickupDays = 7;     // Load these from ArborCat Settings?
        $incrementorString = '+$startingDayOffset day';
    $theDate->modify('+1 day');

    // now loop for x days and create a date string for each day, preceded with the day name
    // create a human friendly version - 'formattedDate' for display purposes in the UI
    // and a basic verson 'date' for use in date db queries

    // this is not ideal whatsoever and just quick way to address unavailable / closure dates
    $date_exclude = [
            'Jun. 20',
            'Jun. 21',
            'Jul. 4',
            'Sep. 7'
        ];
    for ($x=0; $x < $numPickupDays; $x++) {
      $theDate_mdY = $theDate->format('M. j');
      $day_of_week = intval($theDate->format('w'));
      $dayOfWeek = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat',][$day_of_week];
      $datestring = $dayOfWeek . ', ' . $theDate_mdY;

      $datestr_Ymd = $theDate->format('Y-m-d');
      $twoDates = array("date" => $datestr_Ymd, "formattedDate" => $datestring);

      if (!in_array($theDate_mdY, $date_exclude)) {
        $arrayOfDates[$datestr_Ymd] = $twoDates;
      }
      $theDate->modify('+1 day');
    }

    return $arrayOfDates;
  }

  private function calculate_pickup_dates($branchLocation, $exclusionData) {
    dblog('arborcat_calculate_pickup_dates: ENTERED $branchLocation =', $branchLocation, '$exclusionData =', $exclusionData);
  
    $startingDayOffset = 1; // Load these from ArborCat Settings?
    $numPickupDays = 7;     // Load these from ArborCat Settings?
    $startingDay = new DateTime('+' . $startingDayOffset . ' day');
    $startingDayPlusPickupDays = clone $startingDay;
    $startingDayPlusPickupDays->modify('+' . $numPickupDays . ' days');

    $pickupLocations = arborcat_get_pickup_locations($branchLocation);
    dblog('arborcat_calculate_pickup_dates: pickupLocations =', json_encode($pickupLocations));

    $exclusionDates = [];
    
    foreach ($exclusionData as $exclusionDataRec) {
      // check whether any exclusionDataRec locationId is in the pickupLocations for the requested branch
      dblog('arborcat_calculate_pickup_dates: FOREACH exclusionDataRec =', json_encode($exclusionDataRec));
      dblog('arborcat_calculate_pickup_dates: Before unset =', json_encode($pickupLocations), $exclusionDataRec->locationId);
      $offset = array_search($exclusionDataRec->locationId, $pickupLocations);
      dblog('arborcat_calculate_pickup_dates:       offset =', $offset);
      if (false != $offset) {
        array_splice($pickupLocations, $offset);
        dblog('arborcat_calculate_pickup_dates:  After unset =', json_encode($pickupLocations));
      }
      if (count($pickupLocations) == 0) {
        $start = $exclusionDataRec->dateStart;
        $end = $exclusionDataRec->dateEnd;
        if ($end == NULL) { //  single day, so set $end to $start
          $end = $start;
        }
        $start = new DateTime($start);
        $end = new DateTime($end);
        $end->setTime(0, 0, 1);     // Make ending dateTime at least 1 second greater than the start dateTime.
        // iterate between startDate and endDate creating simple Date strings of the format: 'Jun. 20'
        $interval = \DateInterval::createFromDateString('1 day');
        $period = new \DatePeriod($start, $interval, $end);
        foreach ($period as $date) {
          array_push($exclusionDates, $date->format('Y-m-d'));
        }
      }
    }

    dblog('arborcat_calculate_pickup_dates: $exclusionDates =', $exclusionDates);

    // now loop for x days and create a date string for each day, preceded with the day name
    // create a human friendly version - 'formattedDate' for display purposes in the UI
    // and a basic verson 'date' for use in date db queries
    $interval = \DateInterval::createFromDateString('1 day');
    $period = new \DatePeriod($startingDay, $interval, $startingDayPlusPickupDays);

    $pickupdates = [];
    foreach ($period as $date) {
      $theDate_mdY = $date->format('M. j');
      $day_of_week = intval($date->format('w'));
      $dayOfWeek = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat',][$day_of_week];
      $datestring = $dayOfWeek . ', ' . $theDate_mdY;
      $datestr_Ymd = $date->format('Y-m-d');
      if (!in_array($datestr_Ymd, $exclusionDates)) {
        $pickupdates[$datestr_Ymd] = $datestring;
      }
    }

    return $pickupdates;
  }

  private function process_exclusion_data($exclusionData) {

    return $exclusionDates;
  }

}
