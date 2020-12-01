<?php /**
 * @file
 * Contains \Drupal\arborcat\Controller\DefaultController.
 */

namespace Drupal\arborcat\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Url;
use DateTime;
use DateTimeZone;
use DateTimeHelper;

//use Drupal\Core\Database\Database;
//use Drupal\Core\Url;

/**
 * Default controller for the arborcat module.
 */
class DefaultController extends ControllerBase {

  public function index() {
    return [
      '#theme' => 'catalog',
      '#catalog_slider' => fesliders_build_cat_slider(),
      '#community_slider' => fesliders_build_community_slider(119620),
      '#podcast_slider' => fesliders_build_podcasts_slider()
    ];
  }

  public function bibrecord_page($bnum) {
    $api_url = \Drupal::config('arborcat.settings')->get('api_url');

    // Get Bib Record from API
    $guzzle = \Drupal::httpClient();
    try {
      $json = json_decode($guzzle->get("$api_url/record/$bnum/harvest")->getBody()->getContents());
      $bib_record = $json->bib;
      // Copy from Elasticsearch record id to same format as CouchDB _id
      $bib_record->_id = $bib_record->id;
      //$bib_record->id = $bib_record->_id;
    } catch (\Exception $e) {
      $bib_record->_id = NULL;
    }

    if (!$bib_record->_id) {
      $markup = "<p class=\"base-margin-top\">Sorry, the item you are looking for couldn't be found.</p>";
      return [
        '#title' => 'Record Not Found',
        '#markup' => $markup
      ];
    }

    $mat_types = $guzzle->get("$api_url/mat-names")->getBody()->getContents();
    $mat_name = json_decode($mat_types);
    $bib_record->mat_name = $mat_name->{$bib_record->mat_code};

    $downloads = ['z','za','zb','zm','zp'];
    if (in_array($bib_record->mat_code, $downloads)) {
      // below is what will be used when couch records have the download_formats field
      // foreach($bib_record->download_formats as $format) {
      //   if ($bib_record->mat_code != 'z' && $bib_record->mat_code =! 'za') {
      //     $download_url = $guzzle->get("$api_url/download/$bib_record->_id/$format")->getBody()->getContents();
      //   } else {
      //     $download_url = $guzzle->get("$api_url/download/$bib_record->_id/album/$format")->getBody()->getContents();
      //   }
      //   $bib_record->download_urls[$format] = json_decode($download_url)->download_url;
      // }
      if ($bib_record->mat_code == 'zb' || $bib_record->mat_code == 'zp') {
        $download_url = $guzzle->get("$api_url/download/$bib_record->_id/pdf")->getBody()->getContents();
        $bib_record->download_urls['pdf'] = json_decode($download_url)->download_url;
      } elseif ($bib_record->mat_code == 'z' || $bib_record->mat_code == 'za') {
        $download_url = $guzzle->get("$api_url/download/$bib_record->_id/album/mp3")->getBody()->getContents();
        $bib_record->download_urls['mp3'] = json_decode($download_url)->download_url;
      } elseif ($bib_record->mat_code == 'zm') {
        $download_url = $guzzle->get("$api_url/download/$bib_record->_id/mp4")->getBody()->getContents();
        $bib_record->download_urls['mp4'] = json_decode($download_url)->download_url;
      }

      if (\Drupal::config('summergame.settings')->get('summergame_points_enabled')) {
        if ($player = summergame_get_active_player()) {
          // Check for duplicate
          $db = \Drupal::database();
          $row = $db->query("SELECT * FROM sg_ledger WHERE pid = :pid AND type = 'File Download' AND metadata like :meta",
                            [':pid' => $player['pid'], ':meta' => '%bnum:' . $bib_record->_id . '%'])->fetchObject();
          if (!$row) {
            $type = 'File Download';
            $description = 'Downloaded ' . $bib_record->title . ' from our online catalog';
            $metadata = 'bnum:' . $bib_record->_id;
            $result = summergame_player_points($player['pid'], 50, $type, $description, $metadata);
            drupal_set_message("You earned $result points for downloading $bib_record->title from the catalog");
          }
        }
      }
    }

    if (isset($bib_record->tracks)) {
      foreach ($bib_record->tracks as $k => $track) {
        $download_url = $guzzle->get("$api_url/download/$bib_record->_id/track/$k")->getBody()->getContents();
        $bib_record->tracks->{$k}->download_url = json_decode($download_url)->download_url;
      }
      $bib_record->tracks = (array) $bib_record->tracks;
      ksort($bib_record->tracks);
    }

    if(isset($bib_record->syndetics)) {
      $bib_record->syndetics = (array) $bib_record->syndetics;
    }

    // grab user api key for account actions
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $user_api_key = $user->field_api_key->value;

    $lists = arborcat_lists_get_lists($user->get('uid')->value);

    // get community reviews
    $db = \Drupal::database();
    $query = $db->query("SELECT * FROM arborcat_reviews WHERE bib=:bib AND deleted=0",
        [':bib' => $bib_record->id]);
    $reviews = $query->fetchAll();
    foreach ($reviews as $k => $review) {
      $review_user = \Drupal\user\Entity\User::load($review->uid);
      $reviews[$k]->username = (isset($review_user) ? $review_user->get('name')->value : 'unknown');
    }

    // set up review form for users
    $review_form = \Drupal::formBuilder()->getForm('Drupal\arborcat\Form\UserRecordReviewForm', $bib_record->id, $bib_record->title);

    // get commuity ratings
    $query = $db->query("SELECT AVG(rating) as average, count(id) as total FROM arborcat_ratings WHERE bib=:bib and rating > 0",
        [':bib' => $bib_record->id]);
    $ratings = $query->fetch();
    $ratings->average = round($ratings->average, 1);
    $user_rating = $db->query("SELECT rating FROM arborcat_ratings WHERE bib=:bib AND uid=:uid",
        [':bib' => $bib_record->id, ':uid' => $user->id()])->fetch();
    $ratings->user_rating = $user_rating->rating;

    // if summer game codes, convert to array so template can loop over
    if (isset($bib_record->gamecodes)) {
      if (\Drupal::moduleHandler()->moduleExists('summergame')) {
        if (\Drupal::config('summergame.settings')->get('summergame_show_gamecodes_in_catalog') ||
            $user->hasPermission('play test summergame')) {
          $bib_record->sg_enabled = TRUE;
          $bib_record->sg_term = \Drupal::config('summergame.settings')->get('summergame_current_game_term');

          $gamecodes = [];
          foreach ($bib_record->gamecodes as $gameterm => $gameterm_gamecodes) {
            foreach ($gameterm_gamecodes as $gamecode) {
              $badges = $db->query('SELECT d.nid, d.title FROM node__field_badge_formula f, node_field_data d ' .
                                    'WHERE f.entity_id = d.nid ' .
                                    'AND f.field_badge_formula_value REGEXP :gamecode',
                                    [':gamecode' => '[[:<:]]' . $gamecode . '[[:>:]]'])->fetchAll();

              foreach ($badges as $badge) {
                $gc_data = [
                  'text' => $gamecode,
                  'badge' => [
                    'id' => $badge->nid,
                    'title' => $badge->title,
                  ]
                ];
                $gamecodes[$gameterm][] = $gc_data;
              }
            }
          }
          $bib_record->gamecodes = $gamecodes;
        }
      }
    }

    return [
      '#title' => $bib_record->title,
      '#theme' => 'catalog_record',
      '#record' => $bib_record,
      '#api_key' => $user_api_key,
      '#lists' => $lists,
      '#reviews' => $reviews,
      '#review_form' => $review_form,
      '#ratings' => $ratings,
      '#cache' => [ 'max-age' => 0 ]
    ];
  }

  public function moderate_reviews() {
    $db = \Drupal::database();
    $page = pager_find_page();
    $per_page = 50;
    $offset = $per_page * $page;
    $limit = (isset($offset) && isset($per_page) ? " LIMIT $offset, $per_page" : '');
    $total = $db->query("SELECT COUNT(*) as total FROM arborcat_reviews WHERE staff_reviewed=0 AND deleted=0")->fetch()->total;
    $reviews = $db->query("SELECT * FROM arborcat_reviews WHERE staff_reviewed=0 AND deleted=0 ORDER BY id DESC $limit")->fetchAll();
    foreach ($reviews as $k => $review) {
        $review_user = \Drupal\user\Entity\User::load($review->uid);
        $reviews[$k]->username = (isset($review_user) ? $review_user->get('name')->value : 'unknown');
    }

    $pager = pager_default_initialize($total, $per_page);

    return [
      '#theme' => 'moderate_reviews',
      '#reviews' => $reviews,
      '#pager' => [
        '#type' => 'pager',
        '#quantity' => 5
      ],
      '#cache' => [ 'max-age' => 0 ]
    ];
  }

  public function approve_review($rid) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $db = \Drupal::database();

    // grab review uid
    $query = $db->query(
        "SELECT * FROM arborcat_reviews WHERE id=:rid",
        [':rid' => $rid]
    );
    $result = $query->fetch();

    if ($user->hasPermission('administer nodes')) {
        $db->update('arborcat_reviews')
    ->condition('id', $result->id)
    ->fields([
      'staff_reviewed' => 1
    ])
    ->execute();

        $response['success'] = 'Review approved';
    } else {
        $response['error'] = "You don't have permission to approve this review";
    }

    return new JsonResponse($response);
  }

  public function delete_review($rid) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $db = \Drupal::database();

    // grab review uid
    $query = $db->query(
        "SELECT * FROM arborcat_reviews WHERE id=:rid",
        [':rid' => $rid]
    );
    $result = $query->fetch();

    if ($user->get('uid')->value == $result->uid || $user->hasPermission('administer nodes') || $_GET['pointsomaticauth']) {
        $db->update('arborcat_reviews')
          ->condition('id', $result->id)
          ->fields([
            'deleted' => 1
          ])
          ->execute();
        if (\Drupal::moduleHandler()->moduleExists('summergame')) {
            if (\Drupal::config('summergame.settings')->get('summergame_points_enabled')) {
                if ($player = summergame_player_load_all($result->uid)) {
                    $players = [];
                    foreach ($player as $play) {
                        $players[] = $play['pid'];
                    }
                    $type = 'Wrote Review';
                    $metadata = 'bnum:' . $result->bib;
                    $db->update('sg_ledger')
                      ->condition('pid', $players, 'IN')
                      ->condition('type', $type)
                      ->condition('metadata', $metadata)
                      ->fields([
                        'points' => 0,
                        'type' => 'Deleted Review'
                      ])
                      ->execute();
                }
            }
        }
        $response['success'] = 'Review deleted';
    } else {
        $response['error'] = "You don't have permission to delete this review";
    }

    return new JsonResponse($response);
  }

  public function rate_record($bib, $rating) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    if ($user->isAuthenticated()) {
        $db = \Drupal::database();
        // check if user has already rated this item and update if so
        $rated = $db->query(
            "SELECT * FROM arborcat_ratings WHERE bib=:bib AND uid=:uid",
            [':bib' => $bib, ':uid' => $user->id()]
        )->fetch();
        if (isset($rated->id)) {
            $db->update('arborcat_ratings')
        ->condition('id', $rated->id, '=')
        ->condition('bib', $bib, '=')
        ->fields([
          'rating' => $rating
        ])
        ->execute();
            $result['success'] = 'Rating updated!';
        } else {
            $db->insert('arborcat_ratings')
      ->fields([
        'uid' => $user->id(),
        'bib' => $bib,
        'rating' => $rating,
        'timestamp' => time()
      ])
      ->execute();
            $result['success'] = "You rated this item $rating out of 5!";
            if (\Drupal::moduleHandler()->moduleExists('summergame')) {
                if (\Drupal::config('summergame.settings')->get('summergame_points_enabled')) {
                    if ($player = summergame_get_active_player()) {
                        $type = 'Rated an Item';
                        $description = 'Added a Rating to the Catalog';
                        $metadata = 'bnum:' . $bib;
                        $points = summergame_player_points($player['pid'], 10, $type, $description, $metadata);
                        $result['summergame'] = "You earned $points points for rating an item!";
                    }
                }
            }
        }
    } else {
        $result['error'] = 'You must be logged in to rate an item.';
    }

    return new JsonResponse($result);
  }

  public function request_for_patron($barcode, $bnum, $loc, $type) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    if ($user->hasRole('staff') || $user->hasRole('administrator')) {
        $api_url = \Drupal::config('arborcat.settings')->get('api_url');
        $api_key = \Drupal::config('arborcat.settings')->get('api_key');
        $guzzle = \Drupal::httpClient();
        $hold = $guzzle->get("$api_url/patron/$barcode|$api_key/place_hold/$bnum/$loc/$type")->getBody()->getContents();
        return new JsonResponse($hold);
    }
    return new JsonResponse('Request could not be processed');
  }

  // -----------------------------------------------------------
  // ---------------- Pickup Request-related methods -----------
  // -----------------------------------------------------------
  public function pickup_helper() {
    $search_form = \Drupal::formBuilder()->getForm('\Drupal\arborcat\Form\ArborcatPickupHelperForm');
    $current_uri = \Drupal::request()->getRequestUri();
    $barcode = \Drupal::request()->get('bcode');
    $location_urls = [];
    $scheduled_pickups = [];

    if (isset($barcode)) {
      // grab pickup appointments to display on form
      $scheduled_pickups = arborcat_get_scheduled_pickups($barcode);
      $eligible_holds = arborcat_load_patron_eligible_holds($barcode);
      if (!isset($eligible_holds['error'])) {
        if (count($eligible_holds) > 0) {
          // Get the patron ID from the first hold object in $eligible_holds. NOTE - this starts at offset [1]
          $patron_id = $eligible_holds[1]['usr'];
          $hold_locations = [];
          // spin through the eligible holds and get the locations
          foreach ($eligible_holds as $holdobj) {
              array_push($hold_locations, $holdobj['pickup_lib']);
          }
          $hold_locations = array_unique($hold_locations);

          $api_url = \Drupal::config('arborcat.settings')->get('api_url');
          $guzzle = \Drupal::httpClient();
          $locations = json_decode($guzzle->get("$api_url/locations")->getBody()->getContents());

          foreach ($hold_locations as $loc) {
              $locationName = ($loc < 110) ? $locations->{$loc} : 'melcat';
              $url = $this->create_pickup_url($patron_id, $barcode, $loc);
              array_push($location_urls, ['url'=>$url, 'loc'=>$loc, 'locname'=>$locationName]);
          }
        }
      } else {
        $location_urls['error'] = 'Error looking up patron requests. Is this a valid barcode?';
      }
    }

    $render = [
        '#theme' => 'pickup_helper_theme',
        '#search_form' => $search_form,
        '#location_urls' => $location_urls,
        '#barcode' => $barcode,
        '#scheduled_pickups' => $scheduled_pickups ?? NULL
      ];

    return $render;
  }

  private function create_pickup_url($patron_id, $barcode, $location) {
      $html = '';
      $pickup_requests_salt = \Drupal::config('arborcat.settings')->get('pickup_requests_salt');
      $encrypted_barcode = md5($pickup_requests_salt . $barcode);
      $host = \Drupal::request()->getHost();
      $link = 'https://'. $host . '/pickuprequest/' . $patron_id . '/'. $encrypted_barcode . '/' . $location;
      return $link;
  }

  // ----------------------------------
  // ----------------------------------
  public function pickup_test() {
    $return_val = '';
    
    // $date1 = new DateTime("2020-11-01 23:59:59", new DateTimeZone('UTC'));
    // $date2 = clone $date1;
    // $date2->modify('+45 days');
    // for ($i=1; $i<14; $i++) {
    //   dblog("LOOP: ** $i **");
    //   $stuff = arborcat_get_pickup_dates(102, date_format($date1, 'Y-m-d'), date_format($date2, 'Y-m-d'));
    //   $date1->modify('+1 day');
    //   $date2->modify('+1 day');
    // }
    // For testing pickupDate calculate method changes
    // $stuff = arborcat_get_pickup_dates(102, date_format($date1, 'Y-m-d'), date_format($date2, 'Y-m-d'));

    //   $pickupdates =[];
    //   foreach ($stuff as $data_item_key => $data_item_value) {
    //     dblog('FOREACH ', $data_item_key, $data_item_value);
    //     $append_string =  ($data_item_value['date_exclusion_data'] != null) ? ' * not available *' : '';
    //     $pickupdates[$data_item_key] = $data_item_value['display_date_string'] . $append_string;
    //   }
    //   dblog('AFTER FOREACH - pickupdates = ', $pickupdates);


    return [
      '#title' => 'pickup request test',
      '#markup' => json_encode($stuff)
    ];

    $barcode = \Drupal::request()->query->get('barcode');
    $patron_id = \Drupal::request()->query->get('patronid');
    $location = \Drupal::request()->query->get('location');
    $request_id = \Drupal::request()->query->get('requestid');
    
    if (strlen($location) == 3) {
        //$locations = pickupLocations($location);
    } else {
        $location = '102';
    }
    
    $pickup_requests_salt = \Drupal::config('arborcat.settings')->get('pickup_requests_salt');

    if (strlen($request_id) > 0) {
        $encrypted_request_id = md5($pickup_requests_salt . $request_id);
        $return_val = '<p> Encrypting RequestId: ' . $request_id . ' -> ' . $encrypted_request_id . '<br>';
        $return_val .= 'pickup_requests_salt: ' . $pickup_requests_salt . '</p><br>';
    }
    else {
        if (strlen($patron_id) > 0) {
            $barcode =  arborcat_barcode_from_patron_id($patron_id);
        } else {
            $patron_id = arborcat_patron_id_from_barcode($barcode);
        }
        if (14 === strlen($barcode)) {
          if (strlen($row) > 0) {
                  $barcode = $row;
              }
          $encrypted_barcode = md5($pickup_requests_salt . $barcode);
          $return_val = '<h2>' . $patron_id .' -> '. $barcode . ' -> ' . $encrypted_barcode . '</h2><br>';
      
          $host = 'http://nginx.docker.localhost:8000';
          $link = $host . '/pickuprequest/' . $patron_id . '/'. $encrypted_barcode . '/' . $location;
          $html = '<br><a href="' . $link . '" target="_blank">' . $link  . '</a>';

          $return_val .= $html;
        }
    } 
      
    return [
      '#title' => 'pickup request test',
      '#markup' => $return_val
    ];
  }

  public function pickup_request($pnum, $encrypted_barcode, $loc) {
      $mode = \Drupal::request()->query->get('mode');
      $request_pickup_html = '';
      if ($this->validate_transaction($pnum, $encrypted_barcode)) {
          $request_pickup_html = \Drupal::formBuilder()->getForm('Drupal\arborcat\Form\UserPickupRequestForm', $pnum, $loc, $mode);
      } else {
          drupal_set_message('The Pickup Request could not be processed');
      }
      $render[] = [
              '#theme' => 'pickup_request_form',
              '#formhtml' => $request_pickup_html,
              '#max_locker_items_check' => \Drupal::config('arborcat.settings')->get('max_locker_items_check')
          ];
      return $render;
  } 

  public function custom_pickup_request($pickup_request_type, $overload_parameter) {
     $resultMessage = arborcat_custom_pickup_request($pickup_request_type, $overload_parameter);
   return new JsonResponse($resultMessage);
  }

  public function cancel_pickup_request($patron_barcode, $encrypted_request_id, $hold_shelf_expire_date) {
      $patron_id = arborcat_patron_id_from_barcode($patron_barcode);
      $cancel_record = $this->find_record_to_cancel($patron_id, $encrypted_request_id);
      if (count($cancel_record) > 0) {
          $db = \Drupal::database();
          $guzzle = \Drupal::httpClient();
          $api_key = \Drupal::config('arborcat.settings')->get('api_key');
          $api_url = \Drupal::config('arborcat.settings')->get('api_url');
          $self_check_api_key = \Drupal::config('arborcat.settings')->get('selfcheck_key');
          
          // check date is for tomorrow or later - NOTE this is overkill - the query inside 'find_record_to_cancel' method checks for date > todays date.
          $today = (new DateTime("now", new DateTimeZone('UTC')));
          $today->setTime(0,0,0);
          $tomorrow = $today->modify('+1 day');
          $pickup_time = new DateTime($cancel_record->pickupDate, new DateTimeZone('UTC'));
          if ($pickup_time >= $tomorrow) {
              if ($patron_id == $cancel_record->patronId || $user->hasRole('staff') || $user->hasRole('administrator')) {
                  // go ahead and cancel the record
                  $num_deleted = $db->delete('arborcat_patron_pickup_request')
                      ->condition('id', $cancel_record->id, '=')
		                  ->execute();
                  if (1 == $num_deleted) {
                      // Check if the expire time > tomorrow, if not set it to tomorrow
                      $hold_shelf_expire = new DateTime("$hold_shelf_expire_date 23:59:59", new DateTimeZone('UTC'));
                      if ($hold_shelf_expire < $tomorrow) {
                          $hold_shelf_expire_date = date_format($tomorrow, 'Y-m-d');
                      }
                      // Now update the hold_request expire_time in Evergreen
                      $url = "$api_url/patron/$self_check_api_key-$patron_barcode/update_hold/" . $cancel_record->requestId . "?shelf_expire_time=$hold_shelf_expire_date 23:59:59";
                      $updated_hold = $guzzle->get($url)->getBody()->getContents();
                      $response['success'] = 'Pickup Request Canceled';
                  } else {
                      $response['error'] = 'Error canceling Pickup Request';
                  }               
              } else {
                  $response['error'] = "You are not authorized to cancel this Pickup Request";
              }
          } else {
              $response['error'] = "A Pickup Request scheduled for today cannot be canceled";
          }
      }
      else {
          $response['error'] = "The Pickup Request cancellation could not be completed";
      }
      return new JsonResponse($response);
  }

  private function validate_transaction($patron_id, $encrypted_barcode) {
    $return_val = FALSE;
    $barcode =  arborcat_barcode_from_patron_id($patron_id);
    if (14 == strlen($barcode)) {
        $pickup_requests_salt = \Drupal::config('arborcat.settings')->get('pickup_requests_salt');
        $hashed_barcode = md5($pickup_requests_salt . $barcode);
        if ($hashed_barcode == $encrypted_barcode) {
            $return_val =  TRUE;
        }
    }
    return $return_val;
  }

  private function find_record_to_cancel($patron_id, $encrypted_holdId) {
      $return_record = [];
      // get all the pickupRequest records for the patron
      $today = (new DateTime("now"));
      $today_date_string = $today->format("Y-m-d");
      // lookup the pickup request record
      $db = \Drupal::database();
      $query = $db->select('arborcat_patron_pickup_request', 'appr');
      $query->fields('appr', ['id', 'patronId', 'requestId', 'pickupDate']);
      $query->condition('patronId', $patron_id);
      $query->condition('pickupDate', $today_date_string, '>');
      $results = $query->execute()->fetchAll();        
      if (count($results) > 0) {
          $pickup_requests_salt = \Drupal::config('arborcat.settings')->get('pickup_requests_salt');
            // loop through the array results, created hashes of each id and compare with $encrypted_holdId
          foreach($results as $record) {
              $hashed_request_id = md5($pickup_requests_salt . $record->requestId);
              if ($hashed_request_id == $encrypted_holdId) {
                  $return_record =  $record;
                  break;
              }  
          } 
      }
      return $return_record;
  }
}
