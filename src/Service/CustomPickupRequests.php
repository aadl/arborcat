<?php

// The namespace is Drupal\[module_key]\[Directory\Path(s)]
namespace Drupal\arborcat\Service;

/**
 * The CustomPickupRequests service.
 */
class CustomPickupRequests {
  /**
   * Handles custom pickup request.
   *
   * @return string
   *   Some value.
   */
  public function request($pickup_request_type, $overload_parameter) {
    if ($pickup_request_type == 'PRINT_JOB') {
      $print_job_id = $overload_parameter;
      // Extract fields from the printJob request form
      $db = \Drupal::database();
      $query = $db->select('webform_submission_data', 'wsd');
      $query->fields('wsd', ['name', 'value']);
      $query->condition('sid', $print_job_id, '=');
      $rawNameValueResults= $query->execute()->fetchAll();

      // process the raw results and create an associative array of the results.
      // NOTE notification_options can have multiple entries and this is handled by creating a regular array of the different result values
      $assocResults = [];
      foreach($rawNameValueResults as $entry) {
        $keyname = $entry->name;
        if ("notification_options" == $keyname) {
          if(!array_key_exists($keyname, $assocResults)) {
            $assocResults[$keyname] = [];
          }
         array_push($assocResults[$keyname], $entry->value);
       }
        else {
          $assocResults[$keyname] = $entry->value;
        }
      }

      if (count($assocResults) > 0) {     
        $barcode = $assocResults['barcode'];
        $patronId = patronIdFromBarcode($barcode);

        $branchNameArray = explode(" ",$assocResults['delivery_method']);
        $pickupLocations = arborcat_pickup_locations(NULL, $branchNameArray[0], TRUE);
        $branch = $pickupLocations[0]->branchLocationId;
        $pickupLocation = $pickupLocations[0]->locationId;

        $timeslot = 0;
        $pickupDate = $assocResults['pickup_date'];
        $patronPhone = $assocResults['patron_phone'];
        $patronEmail = $assocResults['patron_email'];       
        $notification_options = $assocResults['notification_options'];
      
        $patronEmail = 'test@test.com';
        $patronPhone = '987-654-3210';

        // create new arborcat_pickup_request_record
        arborcat_create_pickup_request_record($pickup_request_type,            
                                          $print_job_id, 
                                          $patronId, 
                                          $branch, 
                                          $timeslot, 
                                          $pickupLocation,
                                          $pickupDate,
                                          $patronEmail,
                                          (in_array('email', array_map('strtolower', $notification_options))) ? $patronEmail : NULL,
                                          (in_array('text', array_map("strtolower", $notification_options))) ? $patronPhone : NULL,
                                          (in_array('phone', array_map("strtolower", $notification_options))) ? $patronPhone : NULL,
                                          $patronPhone ?? NULL);
        $resultMessage = 'SUCCESS';
      }
    } 
    else if ($pickup_request_type == 'GRAB_BAG') {
      $grab_bag_id = $overload_parameter;
    } 
    else if ($pickup_request_type == 'SG_ORDER') {
      $sg_order_id = $overload_parameter;
    } 
    return $resultMessage;
  }

}