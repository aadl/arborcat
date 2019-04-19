<?php
namespace Drupal\arborcat\Form;
use Drupal\Core\Form\FormStateInterface;

class locker_form extends FormBase{

	public function getFormId(){
		return 'locker_form';
	}

	public function buildForm(array $form,FormStateInterface $form_state){
		$guzzle = \Drupal::httpClient();
    	$uid = \Drupal::currentUser()->id();
		$account = \Drupal\user\Entity\User::load($uid);
		$api_key = $account->get('field_api_key')->value;
		$api_url = \Drupal::config('arborcat.settings')->get('api_url');
		$patron_info = json_decode($guzzle->get("$api_url/patron/$api_key/get")->getBody()->getContents());
		$locker_holds = json_decode($guzzle->get("$api_url/patron/$api_key/holds")->getBody()->getContents());
		
		if(count($locker_holds)){
			foreach($locker_holds as $hold){
				echo $hold;
				if(lockers_available($hold)){
					$locker_holds=[
						'title'=>$hold['title'],
						'status'=>$hold['status'],
						'pickuploc'=>$hold['pickup']
					]
				}
			}
		}
	
		$form['lockeritems'] = [
			'#type' => 'value',
			'#default_value' => $locker_holds,
		];
	  	$form['lockercode'] = [
			'#type' => 'value',
			'#default_value' => $patron_info['day_phone'],
	  	];
	  	$form['patronname'] = [
			'#type' => 'value',
			'#default_value' => $patron_info['first_given_name'],
		];
		$form['explanation'] = [
			'#value' => "<h2>Locker Request Form</h2>" .
			"<div>Thanks for your interest in our new pickup lockers! " .
			"This page allows you to request that all your holds that are currently ready for pickup " .
			"at locker-enabled locations be checked out to you and then placed into one of our outdoor pickup " .
			"lockers for fast, easy pickup. " .
			"The following items are currently ready for pickup:" .
			t(["Title", "Cancel Date", "Pickup Location", "Status"], $form['locker_items']) .
			"</div>" .
			"<div>" .
			"Requests will be processed within the next 45 minutes if submitted between 9 AM and 8:30 PM Monday through Friday, " .
			"9 AM and 5:30 PM on Saturday, and from Noon until 5:30 PM on Sunday. Requests received later than these times " .
			"will be filled the following day. After your request is processed, these items will be checked out to you " .
			"by library staff and placed in a locker. You will receive an email when your items are ready for pickup. " .
			"Items that are not picked up by 9AM the next morning (or noon on Sundays) will be checked in and sent to " .
			"the next person on the request list.  It will be necessary to start over with a new request to receive the " .
			"item at another time." .
			"</div>"
		];

	  	$form['email'] = [
			'#type' => 'textfield',
			'#title' => t('Notification Email'),
			'#default_value' => $patroninfo['email'],
			'#size' => 32,
			'#maxlength' => 64,
			'#description' => t('Please enter the email address to receive notification of your locker request'),
		];

	  	$form['submit'] = [
			'#type' => 'submit',
			'#default_value' => t('Check these items out to me and put them in a pickup locker'),
		];
		  return $form;
	}

	function submit_form(array $form, FormStateInterface $form_state){
        $email_from='support@aadl.org';
        $user_input = $form_state->getUserInput();

        foreach($user_input['values']['lockeritems'] as $lockeritem) {
          
          if (!in_array($lockeritem['pickuploc'], $locations)) {
            $locations[] = $lockeritem['pickuploc'];
            $email_to = (stripos($lockeritem['pickuploc'], 'malletts') !== FALSE ? 'mcblockers@aadl.org' : 'pittslockers@aadl.org');
            //not sure about mail manager implementation
            $mailManager = \Drupal::service('plugin.manager.mail');
            $result = $mailManager->mail('sopac_lockers', 'locker_request', $email_to, 'en', $user_input['values'], $email_from, $send);
          }
        }
        $locations = implode(", ", $locations);
        //add ini file
        $redis = new Predis\Client([
            'scheme'=>'ssh',
            'host'=>'#######',
            'port'=>'####'
        ]);
        $redis->rpush('lockerRequests',[time(), $user->uid, $user->name, $user->mail, count($user_input['values']['lockeritems']), $locations]);
        $easter_ts = easter_date(date('Y'));
        $easter_date = date('m-d', $easter_ts);
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

        if (in_array($date, $holidays))
            $open = FALSE;
        else {
            switch($day) {
                case 0: // Sunday
                    $open = ($hour >= 12 && $hour < 18);
                    break;
                case 6: // Saturday
                    $open = ($hour >= 9 && $hour < 18);
                    break;
                case 1: //Monday
                    $open = ($hour >=10 && hour <21);
                default: // Tuesday - Friday
                    $open = ($hour >= 9 && $hour < 21);
                    break;
            }
        }
        if ($open)
            drupal_set_message("You will receive an email at {$form_state['values']['email']} when the items are ready for pickup, within the next 45 minutes.");
        else
            drupal_set_message("The library is currently closed. You will receive an email at {$form_state['values']['email']} when the items are ready for pickup, after the library opens again");
        return new RedirectResponse('user');
	}

	function sopac_lockers_form_validate($form, &$form_state) {
  		if (!valid_email_address($form_state['values']['mail'])) {
    		$form_state -> setError('mail', t('You must enter a valid e-mail address.'));
  		}
    }
    
       
       








		if (!valid_email_address($form_state['values']['email'])) {
			$form_state -> setError('email', t('You must enter a valid e-mail address.'));
		}
	}
}

?>
