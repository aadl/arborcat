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
        dblog('== buildForm ENTERED');
        $guzzle = \Drupal::httpClient();
        $api_key = \Drupal::config('arborcat.settings')->get('api_key');
        $api_url = \Drupal::config('arborcat.settings')->get('api_url');

        $patron_info = json_decode((string) $guzzle->get("$api_url/patron?apikey=$api_key&pnum=$patronId")->getBody()->getContents(), true);

        $uid = $patron_info['evg_user']['card']['id'];
        dblog('== buildForm::uid', $uid);
        $account = \Drupal\user\Entity\User::load($uid);

        $api_key = $account->get('field_api_key')->value;
        dblog('== buildForm::api_key', $api_key);

        $patron_barcode = $patron_info['evg_user']['card']['barcode'];
        dblog('== buildForm::patron_barcode:<<', $patron_barcode, '>>');

        $selfCheckApi_key = \Drupal::config('arborcat.settings')->get('selfcheck_key');
        $selfCheckApi_key .= '-' .  $patron_barcode;
        dblog('== buildForm::count selfCheckApi_key:', $selfCheckApi_key);

        $patron_holds = json_decode($guzzle->get("$api_url/patron/$selfCheckApi_key/holds")->getBody()->getContents(), true);
        dblog('== buildForm::count patron_holds', count($patron_holds));

        $eligible_holds = [];
        
        // Get the locations
        $locations = json_decode($guzzle->get("$api_url/locations")->getBody()->getContents());
        $locationName = $locations->$requestLocation;
                     
        // check the mode to see whether we need to display "Cancel Mode, rather than pickup request mode
        if ('cancel' == $mode) {
            dblog('== PickupRequestForm::buildForm: cancel mode');
        }
        //start at 1 to avoid issue with eligible holds array not being zero-based
        $i=1;

        if (count($patron_holds)) {
            dblog('== buildForm::PickupRequestForm::count -- patron_holds:', count($patron_holds));

            foreach ($patron_holds as $hold) {
                if ($hold['status'] == 'Ready for Pickup') {
                    if (arborcat_eligible_for_locker($hold)) {
                        dblog('== buildForm:: FOREACH eligible holdId =', $hold['id']);
                        $eligible_holds[$i] = [
                            'Title' => $hold['title'],
                            'Status' => $hold['status'],
                            'PickupLoc' => $hold['pickup'],
                            'holdId' => $hold['id']
                        ];
                        $i++;
                    }
                }
                // if (!arborcat_lockers_available($hold['pickup'])) {
                //     $msg = "There are currently no lockers available.";
                // }
            }
            dblog('== buildForm::PickupRequestForm:: past foreach: eligible_holds count = ', count($eligible_holds));
        }

        $form['#attributes'] = ['class' => 'form-width-exception'];

        $form['holditems'] = [
            '#type' => 'value',
            '#default_value' => $eligible_holds,
        ];
        $form['lockercode'] = [
            '#type' => 'value',
            '#default_value' => $patron_info['telephone'],
        ];
        $form['patronname'] = [
            '#type' => 'value',
            '#default_value' => $patron_info['name'],
        ];
        $form['uid'] = [
            '#type'=>'value',
            '#default_value'=>$uid
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
        $titleString .= (count($eligible_holds) > 1) ? "'s" : '';
        $form['item_table']=[
            '#prefix' => '<h2>' . $titleString . ' at ' . $locationName . ' for card# ' . $patron_barcode . '</h2>
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
        $i = 1;
        foreach ($possibleDates as $dateStringsArray) {
            $pickupdates[$i] = $dateStringsArray['formattedDate'];
            $i++;
        }
        // Populate the possible pickup dates popup menu
        $form['pickup_date'] = [
          '#prefix' => '<div class="l-inline-b side-by-side-form">',
          '#type' => 'select',
          '#title' => t('Available Pickup Dates'),
          '#options' => $pickupdates,
          '#description' => t('Choose the date to pick up your requests.'),
        ];

        $pickupLocationsForRequest = pickupLocations($requestLocation);
        // store pickupLocationsForRequest in form_state so it is accessible in the submitFOrm method
        $form_state->set('pickupLocationsForRequest', $pickupLocationsForRequest);
 
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
                $ikey = intval($i++);
                $pickupOptions[$ikey] = $name;
            }
        }
        dblog('== buildForm::PickupRequestForm::After getting pickup locations -- pickupOptions:', $pickupOptions);

        $form['pickup_type'] = [
          '#prefix' => '<div class="l-inline-b side-by-side-form">',
          '#type' => 'select',
          '#title' => t('Pickup Method'),
          '#options' => $pickupOptions,
          '#description' => t('Select how you would like to pick up your requests. To use a locker, please choose an available timeslot'),
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

        $form['sms'] = [
            '#type' => 'textfield',
            '#title' => t('Notification by Text'),
            '#default_value' => $patron_info['telephone'],
            '#size' => 32,
            '#maxlength' => 64,
            '#description' => t('Enter a phone number if you would like to receive a text when your requests are ready to be picked up.'),
        ];

        $form['phone'] = [
            '#type' => 'textfield',
            '#title' => t('Notification by Phone Call'),
            '#default_value' => $patron_info['telephone'],
            '#size' => 32,
            '#maxlength' => 64,
            '#description' => t('Enter a phone number if you would like to receive a call when your requests are ready to be picked up.'),
        ];

        $form['email'] = [
            '#type' => 'textfield',
            '#title' => t('Notification by Email'),
            '#default_value' => $patron_info['email'],
            '#size' => 32,
            '#maxlength' => 64,
            '#description' => t('Enter an email if you would like to receive an email when your requests are ready to be picked up.'),
        ];

        $form['branch'] = [
            '#type' => 'value',
            '#default_value' => '',	// $branch,
            '#suffix' => '</div></div>'
        ];

        $form['submit'] = [
        '#type' => 'submit',
        '#default_value' => t('Check these items out to me and put them out for pickup'),
        ];

        dblog('== buildForm::PickupRequestForm:: attaching JS lib: pickuprequest-functions');
        // $form['#attached']['library'][] = 'arborcat/pickuprequest-functions';

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $offset = $form_state->getValue('pickup_date');
        $possibleDates = $this->calculateLobbyPickupDates();
        $pickupDate = $possibleDates[$offset-1]['date'];
        dblog('## submitForm: dateString:', $pickupDate);
        
        $key = $form_state->getValue('pickup_type');
        $val = $form['pickup_type']['#options'][$key];
        $pickupLocationsForRequest = $form_state->get('pickupLocationsForRequest');

        $selectedPickup = $pickupLocationsForRequest[$key-1];
        dblog('## submitForm: $selectedPickup:branchLocationId', $selectedPickup->locationId);
        dblog('## submitForm: $selectedPickup:timePeriod', $selectedPickup->timePeriod);
        
        $phone = $form_state->getValue('phone');
        $sms = $form_state->getValue('sms');

        /*   $messenger = \Drupal::messenger();
           //parse telephone number into seven digit locker code
           $lockercode = $form_state->getValue('lockercode');
           $lockercode = preg_replace("/[\\s\\-()]/", "", $lockercode);
           $lockercode = preg_replace("/^\d\d\d/", "", $lockercode);

           //if no phone number is provided, use a random seven digit number
           if ($lockercode=="") {
               $lockercode = strval(rand(1111111, 9999999));
           }
    */
        $hold_items= $form_state->getValue('holditems');
        $table_values = $form_state->getValue('item_table');
        $patron_name = $form_state->getValue('patronname');
        $patron_email = $form_state->getValue('email');
        $branch = $form_state->getValue('branch');
        $uid = $form_state->getValue('uid');

        $selected_titles = array_filter($table_values);

        $holds = [];
            
        foreach ($selected_titles as $key=>$val) {
            array_push($holds, $hold_items[$val]);
        }

        if (count($holds) == 0) {
            $messenger->addError(t("There are no request items selected."));
        } else {  // got at least one hold to be processed
            
            foreach ($holds as $holdRequested) {
                dblog('## submitForm: holds FOREACH - calling addPickupRequest', json_encode($holdRequested));
                // create arborcat_patron_pickup_request records for each of the selected holds
                addPickupRequest(
                    $uid,
                    $holdRequested->holdId,
                    $selectedPickup->branchLocationId,
                    $pickupDate,
                    $selectedPickup->timePeriod,
                    $selectedPickup->locationId,
                    $patron_email,
                    $phone,
                    $sms
                );
                dblog('## submitForm: holds FOREACH - after calling addPickupRequest');

                // set the expire date for each selected hold
                //update the shelf_expire times for holds corresponding to pickup date selected,
                //this works: https://api.aadl.org/patron/932e54adb710dbe5a0eaf9624ee04d5cself-21621033196070/update_hold/2100273?shelf_expire_time=2020-07-03%2023:59:59
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
    }

    private function calculateLobbyPickupDates()
    {
        $arrayOfDates = [];
        // get the current date
        $theDate = new DateTime('today');
        dblog('calculateLobbyPickupDates: theDate:', $theDate);
        // add 1 day to the current date
        $startingDayOffset = 1; // Load these from ArborCat Settings?
        $numPickupDays = 7;     // Load these from ArborCat Settings?

        $incrementorString = '+$startingDayOffset day';
        $theDate->modify('+1 day');

        // now loop for x days and create a date string for each day, preceded with the day name
        // create a human friendly version - 'formattedDate' for display purposes in the UI
        // and a basic verson 'date' for use in date db queries
        for ($x=0; $x < $numPickupDays; $x++) {
            $theDate_mdY = $theDate->format('M-j-Y');
            $day_of_week = intval($theDate->format('w'));
            $dayOfWeek = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat',][$day_of_week];
            $datestring = $dayOfWeek . ', ' . $theDate_mdY;

            $datestr_Ymd = $theDate->format('Y-m-d');
            $twoDates = array("date" => $datestr_Ymd, "formattedDate" => $datestring);

            array_push($arrayOfDates, $twoDates);
            $theDate->modify('+1 day');
        }
        dblog('calculateLobbyPickupDates: returning array of arrays:', $arrayOfDates);
        return $arrayOfDates;
    }
}
