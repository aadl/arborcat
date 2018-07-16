<?php /**
 * @file
 * Contains \Drupal\arborcat\Controller\DefaultController.
 */

namespace Drupal\arborcat\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    $json = json_decode($guzzle->get("$api_url/record/$bnum/harvest")->getBody()->getContents());
    $bib_record = $json->bib;
    $bib_record->_id = $bib_record->id; // Copy from Elasticsearch record id to same format as CouchDB _id

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
    $query = $db->query("SELECT * FROM arborcat_reviews WHERE bib=:bib",
        [':bib' => $bib_record->id]);
    $reviews = $query->fetchAll();
    foreach ($reviews as $k => $review) {
      $review_user = \Drupal\user\Entity\User::load($review->uid);
      $reviews[$k]->username = (isset($review_user) ? $review_user->get('name')->value : 'unknown');
    }

    // set up review form for users
    $review_form = \Drupal::formBuilder()->getForm('Drupal\arborcat\Form\UserRecordReviewForm', $bib_record->id, $bib_record->title);

    // get commuity ratings
    $query = $db->query("SELECT AVG(rating) as average, count(id) as total FROM arborcat_ratings WHERE bib=:bib",
        [':bib' => $bib_record->id]);
    $ratings = $query->fetch();
    $ratings->average = round($ratings->average, 1);
    $user_rating = $db->query("SELECT rating FROM arborcat_ratings WHERE bib=:bib AND uid=:uid",
        [':bib' => $bib_record->id, ':uid' => $user->id()])->fetch();
    $ratings->user_rating = $user_rating->rating;

    // if summer game codes, convert to array so template can loop over
    if (isset($bib_record->gamecodes)) {
      if (\Drupal::moduleHandler()->moduleExists('summergame')) {
        if (\Drupal::config('summergame.settings')->get('summergame_points_enabled')) {
          $bib_record->sg_enabled = true;
          $bib_record->gamecodes = (array) $bib_record->gamecodes;
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
    $total = $db->query("SELECT COUNT(*) as total FROM arborcat_reviews WHERE staff_reviewed=0")->fetch()->total;
    $reviews = $db->query("SELECT * FROM arborcat_reviews WHERE staff_reviewed=0 ORDER BY id DESC $limit")->fetchAll();
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
      ]
    ];
  }

  public function approve_review($rid) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $db = \Drupal::database();

    // grab review uid
    $query = $db->query("SELECT * FROM arborcat_reviews WHERE id=:rid",
      [':rid' => $rid]);
    $result = $query->fetch();

    if ($user->hasRole('administrator')) {
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
    $query = $db->query("SELECT * FROM arborcat_reviews WHERE id=:rid",
      [':rid' => $rid]);
    $result = $query->fetch();

    if ($user->get('uid')->value == $result->uid || $user->hasRole('administrator')) {
      $db->delete('arborcat_reviews')
        ->condition('id', $result->id)
        ->execute();
      if (\Drupal::moduleHandler()->moduleExists('summergame')) {
        if (\Drupal::config('summergame.settings')->get('summergame_points_enabled')) {
          if ($player = summergame_get_active_player()) {
            $players = [];
            $players[] = $player['pid'];
            if ($player['other_players']) {
              foreach ($player['other_players'] as $play) {
                $players[] = $play['pid'];
              }
            }
            $type = 'Wrote Review';
            $metadata = 'bnum:' . $result->bib;
            $db->delete('sg_ledger')
             ->condition('pid', $players, 'IN')
             ->condition('type', $type)
             ->condition('metadata', $metadata)
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
      $rated = $db->query("SELECT * FROM arborcat_ratings WHERE bib=:bib AND uid=:uid",
        [':bib' => $bib, ':uid' => $user->id()])->fetch();
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

}
