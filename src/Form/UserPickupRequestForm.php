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

class UserPickupRequestForm extends FormBase
{
    public function getFormId()
    {
        return 'user_pickup_request_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state, string $patronId = null, string $requestLocation = null, string $mode = null)
    {
        $guzzle = \Drupal::httpClient();
        $api_key = \Drupal::config('arborcat.settings')->get('api_key');
        $api_url = \Drupal::config('arborcat.settings')->get('api_url');

        $patron_info = json_decode((string) $guzzle->get("$api_url/patron?apikey=$api_key&pnum=$patronId")->getBody()->getContents(), true);

        $uid = $patron_info['evg_user']['card']['id'];
        $account = \Drupal\user\Entity\User::load($uid);

        $api_key = $account->get('field_api_key')->value;

        $patron_barcode = $patron_info['evg_user']['card']['barcode'];

        $selfCheckApi_key = \Drupal::config('arborcat.settings')->get('selfcheck_key');
        $selfCheckApi_key .= '-' .  $patron_barcode;

        $patron_holds = json_decode($guzzle->get("$api_url/patron/$selfCheckApi_key/holds")->getBody()->getContents(), true);

        $eligible_holds = [];
        
        // Get the locations
        $locations = json_decode($guzzle->get("$api_url/locations")->getBody()->getContents());
        $locationName = $locations->$requestLocation;
                     
        // check the mode to see whether we need to display "Cancel Mode, rather than pickup request mode
        if ('cancel' == $mode) {
            // do cancel things
        }
        //start at 1 to avoid issue with eligible holds array not being zero-based
        $i=1;

        $mel_mappings = [
            113 => 102,
            114 => 103,
            115 => 104,
            116 => 105,
            117 => 106
        ];

        $db = \Drupal::database();
        if (count($patron_holds)) {
            foreach ($patron_holds as $hold) {
                if ($hold['status'] == 'Ready for Pickup') {
                    if ($hold['hold']['pickup_lib'] == $requestLocation || isset($mel_mappings[$hold['hold']['pickup_lib']])) {
                        // if pickup appt already set, don't display item
                        $pickup_req_exists = $db->query("SELECT * from arborcat_patron_pickup_request WHERE requestId = :hid", [':hid' => $hold['id']])->fetch();
                        if (isset($pickup_req_exists->id)) {
                            continue;
                        }
                        if (arborcat_eligible_for_locker($hold)) {
                            $eligible_holds[$i] = [
                                'Title' => $hold['title'],
                                'Status' => $hold['status'],
                                'PickupLoc' => $hold['pickup'],
                                'holdId' => $hold['id']
                            ];
                            $i++;
                        }
                    }
                }
                // if (!arborcat_lockers_available($hold['pickup'])) {
                //     $msg = "There are currently no lockers available.";
                // }
            }
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
            '#default_value' => $request_location
        ];

        $form['lockeritems'] = [
            '#type' => 'value',
            '#default_value' => $eligible_holds,
            '#required' => true
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
            'PickupLoc'=>t('Pickup Location')
        ];

        $titleString = (strlen($mode) > 0) ? 'Cancel hold/request for item' : 'Request Pickup for item';
        $titleString .= (count($eligible_holds) > 1) ? "s" : '';
        $form['item_table']=[
            '#prefix' => '<h2>' . $titleString . ' at ' . $locationName . ' for ' . $patron_barcode . '</h2>
									 Select items below to request for pickup:
									 <div><div class="l-inline-b side-by-side-form">',
            '#type'=>'tableselect',
            '#header' => $header,
            '#options' => $eligible_holds,
            '#multiple' => 'true',
            '#empty' => "You have no holds ready for pickup.",
            '#suffix' => '</div>'
        ];

        // $form['explanationcont']=[
        // 	'#markup'=>
        // 	"<div>" .
        // 	"Once your items are in a locker, please pick them up by 9 AM the next morning. Items still in the lockers when the library opens may be checked back in for the next patron. Requests placed within 30 minutes of closing may not be ready today. Thank you for using this service, and thank you for using your library!" .
        // 	"</div>"
        // ];

        $possibleDates = $this->calculateLobbyPickupDates();
        $pickupdates = [];
        foreach ($possibleDates as $key => $dateStringsArray) {
            $pickupdates[$key] = $dateStringsArray['formattedDate'];
        }

        // Populate the possible pickup dates popup menu
        $form['pickup_date'] = [
          '#prefix' => '<div class="l-inline-b side-by-side-form">',
          '#type' => 'select',
          '#title' => t('Available Pickup Dates'),
          '#options' => $pickupdates,
          '#description' => t('Choose the date to pick up your requests.'),
          '#required' => true
        ];

        $pickupLocationsForRequest = arborcat_pickupLocations($requestLocation);

        $selectedDate = '';
        $pickupOptions =  [];
        $i = 1;
        foreach ($pickupLocationsForRequest as $locationObj) {
            $addLocation = false;
            if ($locationObj->timePeriod == 0) {    // for lobby (loc=0), always add it as a location)
                $addLocation = true;
            } else {
                $addLocation = lockerAvailableForDateAndTimeSlot($possibleDates[0]['date'], $locationObj);
            }
            if (true == $addLocation) {
                $name = $locationObj->locationName;
                $pickupOptions["$locationObj->locationId-$locationObj->timePeriod"] = $name;
            }
        }

        $form['pickup_type'] = [
          '#prefix' => '<div class="l-inline-b side-by-side-form">',
          '#type' => 'select',
          '#title' => t('Pickup Method'),
          '#options' => $pickupOptions,
          '#description' => t('Select how you would like to pick up your requests. To use a locker, please choose an available timeslot'),
          '#required' => true
        ];

        // This is hidden using Jquery when the javascript is loaded
        $form['pickup_time'] = [
          //'#prefix' => '<span class="no-display">',
          '#type' => 'select',
          '#title' => t('Pickup Time'),
          '#options' => [
            '0' => '',
            '1' => '12pm - 2pm',
            '2' => '2pm - 4pm',
            '3' => '4pm - 6pm',
            '4' => '6pm - 8pm'
          ],
          '#description' => t('Select time period for when you would like to pick up your requests from a locker.'),
          '#suffix' => '</span>'
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
            '#required' => true
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

        $form['submit'] = [
        '#type' => 'submit',
        '#default_value' => t('Check these items out to me and put them out for pickup'),
        ];

        // $form['#attached']['library'][] = 'arborcat/pickuprequest-functions';

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $offset = $form_state->getValue('pickup_date');
        $possibleDates = $this->calculateLobbyPickupDates();
        $dateString = $possibleDates[$offset]['date'];
        
        $key = $form_state->getValue('pickup_type');
        $val = $form['pickup_type']['#options'][$key];

        //I want to access pickupLocationsForRequest that was assigned a datastructure in the builfForm method.
        // need to make it glabal in some way
        $pickupTypeSelected = $this->$pickupLocationsForRequest[$key];
        
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

        // pickup point/location tied in with time slot
        $pickup_timeslot = explode('-', $form_state->getValue('pickup_type'));
        $pickup_date = $form_state->getValue('pickup_date');

        $selected_titles = array_filter($table_values);

        $holds = [];
            
        foreach ($selected_titles as $key=>$val) {
            array_push($holds, $locker_items[$val]);
        }

        if (count($holds) == 0) {
            $messenger->addError(t("There are no request items selected."));
        } else {  // got at least one hold to be processed
            $db = \Drupal::database();
            // this should really be in a constructor, but doing this for time
            $guzzle = \Drupal::httpClient();
            $api_key = \Drupal::config('arborcat.settings')->get('api_key');
            $api_url = \Drupal::config('arborcat.settings')->get('api_url');
            $selfCheckApi_key = \Drupal::config('arborcat.settings')->get('selfcheck_key');
            foreach ($holds as $hold) {
                // set the expire date for each selected hold
                // commented out for now during testing
                // $updated_hold = $guzzle->get("$api_url/patron/$selfCheckApi_key-$patron_barcode/updated_hold/" . $hold['holdId'] . "?shelf_expire_time=$pickup_date 23:59:59")->getBody()->getContents();
                // create arborcat_patron_pickup_request records for each of the selected holds
                $db->insert('arborcat_patron_pickup_request')
                    ->fields([
                      'requestId' => $hold['holdId'],
                      'patronId' => $pnum,
                      'holdId' => $hold['holdId'], // duplicate to requestId ???
                      'branch' => (int) $requestLocation,
                      'timeSlot' => $pickup_timeslot[1],
                      'pickupLocation' => $pickup_timeslot[0],
                      'pickupDate' => $pickup_date,
                      'contactEmail' => ($notification_types['email'] ? $patron_email : null),
                      'contactSMS' => ($notification_types['sms'] ? $patron_phone : null),
                      'contactPhone' => ($notification_types['phone'] ? $patron_phone : null),
                    ])
                    ->execute();
            }
        }
 
        // Need to add a c"confirm the request" modal dialog here before proceeding

        $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
        $uid = $user->id();
        $url = \Drupal\Core\Url::fromRoute('entity.user.canonical', ['user'=>$user->id()]);

        return $form_state->setRedirectUrl($url);
    }

    public function arborcat_mail($key, $email_to, $patron, $code, $holds)
    {
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

    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        if (!valid_email_address($form_state->getValue('email'))) {
            $form_state->setErrorByName('email', t('You must enter a valid e-mail address.'));
        }

        if ($form_state->getValue('notification_types')['email'] && !$form_state->getValue('email')) {
            $form_state->setErrorByName('email', t('No email is set, but you requested an email notification.'));
        } elseif (!valid_email_address($form_state->getValue('email'))) {
            $form_state->setErrorByName('email', t('You must enter a valid e-mail address.'));
        }

        if (($form_state->getValue('notification_types')['sms'] || $form_state->getValue('notification_types')['phone'] ) && !$form_state->getValue('phone')) {
            $form_state->setErrorByName('phone', t('No phone number is set, but you requested a text and/or phone call.'));
        }
    }

    private function calculateLobbyPickupDates()
    {
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
            'Jul. 4'
        ];
        for ($x=0; $x < $numPickupDays; $x++) {
            $theDate_mdY = $theDate->format('M. j');
            if (in_array($theDate_mdY, $date_exclude)) {
                continue;
            }
            $day_of_week = intval($theDate->format('w'));
            $dayOfWeek = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat',][$day_of_week];
            $datestring = $dayOfWeek . ', ' . $theDate_mdY;

            $datestr_Ymd = $theDate->format('Y-m-d');
            $twoDates = array("date" => $datestr_Ymd, "formattedDate" => $datestring);

            // array_push($arrayOfDates, $twoDates);
            $arrayOfDates[$datestr_Ymd] = $twoDates;
            $theDate->modify('+1 day');
        }

        return $arrayOfDates;
    }
}
