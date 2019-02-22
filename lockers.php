<?php

	function sopac_lockers_menu(){
		$items = [];

		$items['user/locker'] = array(
			'title'=> 'Request a locker for pickup',
			'page callback' => 'get_form',
			'page arguments' => array('sopac_lockers_form'),
			'access arguments' => array('access user pages'),
			'type' => MENU_CALLBACK
		);

		$items['admin/settings/lockers'] = array(
    		'title' => 'SOPAC Lockers Settings',
    		'description' => 'Settings for pickup lockers',
    		'page callback' => 'get_form',
    		'page arguments' => array('sopac_lockers_settings'),
    		'access arguments' => array('administer site configuration'),
    		'type' => MENU_NORMAL_ITEM,
  		);
  		return $items;
	}

	function sopac_lockers_settings(){
		//need to figure out where these variables are coming from
		$form['sopac_lockers_locations'] = array(
			'#type' => 'checkboxes'
		)
	}

	function sopac_lockers_form(){
		global $user;
		$locum = new locum_client;

		$account = user_load($user);
		$patron_info = $locum->get_patron_info($account->profile_pref_cardnum);
		$patron_holds = $locum->get_patron_holds($account->profile_pref_cardnum);
		$patronphone = ereg_replace("[^0-9]", "", $patron_info['tel1']);
  		$lockercode = ($patronphone ? substr($patronphone, -7, 7) : "rand");
  		if (count($patron_holds)) {
    		foreach($patron_holds as $hold) {
      			if (sopac_lockers_available($hold)) {
        			$lockeritems[] = array('title' => $hold['title'], 'canceldate' => $hold['canceldate'], 'pickuploc' => $hold['pickuploc'], 'status' => $hold['status']);
      			}
    		}
  		}

  		if (count($lockeritems)) {
    		// log access
    		exec("echo " . date("Y-m-d H:i:s") . " :: " . $user->name . " :: " . $user->mail . " :: " . count($lockeritems) . " items >> /tmp/lockeraccess.log");

    		$form['lockeritems'] = array(
      			'#type' => 'value',
      			'#value' => $lockeritems,
    		);
    		$form['lockercode'] = array(
      			'#type' => 'value',
      			'#value' => $lockercode,
    		);
    		$form['patronname'] = array(
      			'#type' => 'value',
      			'#value' => $patron_info['name'],
    		);
    		$form['explaination'] = array(
      			'#value' => "<h2>Locker Request Form</h2>" .
                  "<div>Thanks for your interest in our new pickup lockers! " .
                  "This page allows you to request that all your holds that are currently ready for pickup " .
                  "at locker-enabled locations be checked out to you and then placed into one of our outdoor pickup " .
                  "lockers for fast, easy pickup. " .
                  "The following items are currently ready for pickup:" .
                  theme_table(array("Title", "Cancel Date", "Pickup Location", "Status"), $lockeritems) .
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
    		);
    		$form['mail'] = array(
      			'#type' => 'textfield',
      			'#title' => t('Notification Email'),
      			'#default_value' => $user->mail,
      			'#size' => 32,
      			'#maxlength' => 64,
      			'#description' => t('Please enter the email address to receive notification of your locker request'),
    		);
    		$form['submit'] = array(
      			'#type' => 'submit',
      			'#value' => t('Check these items out to me and put them in a pickup locker'),
    		);
    		return $form;
  		}
  		else {
  			drupal_set_message("Sorry, there are no lockers available for pickup of your items at this time.");
    		$response = new RedirectResponse('user');
    		$response->send('user');
  		}
	}

	function sopac_lockers_form_validate($form, &$form_state) {
  		if (!valid_email_address($form_state['values']['mail'])) {
    		$form_state -> setError('mail', t('You must enter a valid e-mail address.'));
  		}
	}

	

}



?>
