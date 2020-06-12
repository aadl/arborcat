<?php
namespace Drupal\arborcat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Predis\Client;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\arborcat\Controller;

class PickupRequestForm extends FormBase
{
    public function getFormId()
    {
        return 'pickup_request_form';
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
                        $eligible_holds[$i] = [
                            'Title' => $hold['title'],
                            'Status' => $hold['status'],
                            'PickupLoc' => $hold['pickup']
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

        $form['lockeritems'] = [
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
        $pickupLocationsForRequest = pickupLocations($requestLocation);
        $pickupOptions =  [];
        $i = 1;
        foreach ($pickupLocationsForRequest as $loc) {
            $name = $loc->locationName;
            $ikey = intval($i++);
            $pickupOptions[$ikey] = $name;
        }

        dblog('== buildForm::PickupRequestForm::After getting pickup locations -- pickupOptions:', $pickupOptions);

        // $pickupOptions =  [
        //     '1' => 'Vestibule',
        //     '2' => 'Locker',
        // ];


        $form['pickup_type'] = [
        '#prefix' => '<div class="l-inline-b side-by-side-form">',
          '#type' => 'select',
          '#title' => t('Pickup Method'),
          '#options' => $pickupOptions,
          '#description' => t('Select how you would like to pick up your requests.'),
        ];


        $form['pickup_date'] = [
            '#type' => 'date',
            '#title' => t('Pickup Date'),
            '#size' => 32,
            '#maxlength' => 64,
            '#description' => t('Please select a date to pickup your requests. We are unable to fulfill same day requests.'),
            '#datepicker_options' => array(
                // This needs to be in the same format as defined in #date_format.
                'minDate' => '06/13/2020',
                // You can also use this format.
                'maxDate' => '06/20/2020')
           ];


        $form['pickup_time'] = [
          //'#attributes' => ['class' => 'no-display'],
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
        $form['#attached']['library'][] = 'arborcat/pickuprequest-functions';

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $messenger = \Drupal::messenger();
        //parse telephone number into seven digit locker code
        $lockercode = $form_state->getValue('lockercode');
        $lockercode = preg_replace("/[\\s\\-()]/", "", $lockercode);
        $lockercode = preg_replace("/^\d\d\d/", "", $lockercode);

        //if no phone number is provided, use a random seven digit number
        if ($lockercode=="") {
            $lockercode = strval(rand(1111111, 9999999));
        }

        $locker_items= $form_state->getValue('lockeritems');
        $table_values = $form_state->getValue('item_table');
        $patron_name = $form_state->getValue('patronname');
        $patron_email = $form_state->getValue('email');
        $branch = $form_state->getValue('branch');
        $uid = $form_state->getValue('uid');

        $selected_titles = array_filter($table_values);

        $holds = [];
            
        if (arborcat_lockers_available($branch)) {
            foreach ($selected_titles as $key=>$val) {
                array_push($holds, $locker_items[$val]);
            }
            $email_from='support@aadl.org';
            $user_input = $form_state->getUserInput();
            $email_to = (stripos($branch, 'Malletts') !== false ? 'mcblockers@aadl.org' : 'pittslockers@aadl.org');
            $this->arborcat_mail('locker_requests', $email_to, $patron_name, $lockercode, $holds);

            $redis = new Client(\Drupal::config('events.settings')->get('events_redis_conn'));

            $time = strftime(time());
            $redis_query = $time . "#" . $branch;
            $redis->lPush('lockerRequests', $redis_query);
            
            //check for holidays
            $easter_ts = easter_date();
            $easter_date = date('md', $easter_ts);
            $holidays = [
                '0101', // New Year's Day
                $easter_date, // Easter
        '0530', // Memorial Day
        '0704', // Independence Day
        '0905', // Labor Day
        '1010', // Staff Day
        '1124', // Thanksgiving
        '1224', // Christmas Eve
        '1225', // Christmas
         ];

            $date = date("md");
            $day = date("w");
            $hour = date("G") + (date("i") / 60);

            if (in_array($date, $holidays)) {
                $open = false;
            } else {
                switch ($day) {
            case 0: // Sunday
              $open = ($hour >= 12 && $hour < 18);
              break;
            case 6: // Saturday
                 $open = ($hour >= 9 && $hour < 18);
              break;
            case 1: //Monday
              $open = ($hour >=10 && hour <21);
              // no break
            default: // Tuesday - Friday
              $open = ($hour >= 9 && $hour < 21);
              break;
            }
            }
            if ($open) {
                $message = "You will receive an email at {$user_input['email']} when your items are ready for pickup. If there is any issue preventing these items from being placed in a locker, library staff will contact you.";
                $messenger->addStatus(t($message));
            } else {
                $messenger->addStatus(t("The library is currently closed. You will receive an email at {$user_input['email']} when the items are ready for pickup, after the library opens again."), 'closed');
            }
        } else {
            $messenger->addError(t("There are no lockers are currently available."));
        }
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
}
