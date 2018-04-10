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

    return [
      '#title' => $bib_record->title,
      '#theme' => 'catalog_record',
      '#record' => $bib_record,
      '#api_key' => $user_api_key,
      '#lists' => $lists,
      '#cache' => [ 'max-age' => 0 ]
    ];
  }

}
