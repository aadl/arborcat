<?php

use Drupal\Form\FormStateInterface;

class locker_form{
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

		$form['lockeritems'] = [
			'#type' => 'value',
			'#value' => $patron_info->'',
		];
	  	$form['lockercode'] = [
			'#type' => 'value',
			'#value' => $lockercode,
	  	];
	  	$form['patronname'] = [
			'#type' => 'value',
			'#value' => $patron_info['name'],
		];
		$form['explanation'] = [
			'#value' => "<h2>Locker Request Form</h2>" .
			"<div>Thanks for your interest in our new pickup lockers! " .
			"This page allows you to request that all your holds that are currently ready for pickup " .
			"at locker-enabled locations be checked out to you and then placed into one of our outdoor pickup " .
			"lockers for fast, easy pickup. " .
			"The following items are currently ready for pickup:" .
			t(["Title", "Cancel Date", "Pickup Location", "Status"], $lockeritems) .
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

	  	$form['mail'] = [
			'#type' => 'textfield',
			'#title' => t('Notification Email'),
			'#default_value' => $user->mail,
			'#size' => 32,
			'#maxlength' => 64,
			'#description' => t('Please enter the email address to receive notification of your locker request'),
		];

	  	$form['submit'] = [
			'#type' => 'submit',
			'#value' => t('Check these items out to me and put them in a pickup locker'),
		];
		  return $form;
	}

	function submit_form($form, FormStateInterface $form_state){

	}

	function sopac_lockers_form_validate(array $form, FormStateInterface $form_state) {
  		if (!valid_email_address($form_state['values']['mail'])) {
    		$form_state -> setError('mail', t('You must enter a valid e-mail address.'));
  		}
	}



	}

?>
