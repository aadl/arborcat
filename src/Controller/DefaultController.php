<?php /**
 * @file
 * Contains \Drupal\arborcat\Controller\DefaultController.
 */

namespace Drupal\arborcat\Controller;

use Drupal\Core\Controller\ControllerBase;
//use Drupal\Core\Database\Database;
//use Drupal\Core\Url;

/**
 * Default controller for the arborcat module.
 */
class DefaultController extends ControllerBase {

  public function index() {
    return [
      '#theme' => 'catalog',
      '#catalog_slider' => [],
      '#community_slider' => [],
      '#podcast_slider' => []
    ];
  }

  public function bibrecord_page($bnum) {
    $api_url = \Drupal::config('arborcat.settings')->get('api_url');

    // Get Bib Record from API
    $guzzle = \Drupal::httpClient();
    $json = $guzzle->get("http://$api_url/record/$bnum")->getBody()->getContents();
    $bib_record = json_decode($json);

    if ($bib_record->tracks) {
      $bib_record->tracks = (array) $bib_record->tracks;
      ksort($bib_record->tracks);
    }

    // grab user api key for account actions
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $user_api_key = $user->field_api_key->value;

    return [
      '#title' => $bib_record->title,
      '#theme' => 'catalog_record',
      '#record' => $bib_record,
      '#api_key' => $user_api_key
    ];
  }

  public function user_lists() {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
  }

  public function view_user_list() {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
  }

  public function create_list() {

  }

  public function add_list_item() {

  }

  public function delete_list() {

  }

  public function delete_list_item() {

  }
}
