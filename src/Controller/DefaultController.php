<?php /**
 * @file
 * Contains \Drupal\arborcat\Controller\DefaultController.
 */

namespace Drupal\arborcat\Controller;

use Drupal\Core\Controller\ControllerBase;
//use Drupal\Core\Database\Database;
//use Drupal\Core\Url;

/**
 * Default controller for the weed module.
 */
class DefaultController extends ControllerBase {

  public function bibrecord_page($bnum) {
    $api_url = \Drupal::config('arborcat.settings')->get('api_url');

    // Get Bib Record from API
    $json = file_get_contents("http://$api_url/bib/$bnum");
    $bib_record = json_decode($json);

    $output = "BIB RECORD: $bnum";
    $output .= '<pre>' . print_r($bib_record, 1) . '</pre>';

    return array(
      '#title' => $bib_record->title,
      '#markup' => $output,
    );
  }
}
