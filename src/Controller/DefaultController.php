<?php /**
 * @file
 * Contains \Drupal\arborcat\Controller\DefaultController.
 */

namespace Drupal\arborcat\Controller;

use Drupal\Core\Controller\ControllerBase;
use PHPOnCouch\Couch,
    PHPOnCouch\CouchAdmin,
    PHPOnCouch\CouchClient;
//use Drupal\Core\Database\Database;
//use Drupal\Core\Url;

/**
 * Default controller for the weed module.
 */
class DefaultController extends ControllerBase {

  public function bibrecord_page($bnum) {
    // Load settings.
    $couch_server = \Drupal::config('arborcat.settings')->get('couch_server');
    $couch_database = \Drupal::config('arborcat.settings')->get('couch_database');
    // Connect to CouchDB
    $client = new CouchClient($couch_server, $couch_database);

    // get Bib Record
    $bib_record = $client->getDoc($bnum);

    $output = "BIB RECORD: $bnum";
    $output .= '<pre>' . print_r($bib_record, 1) . '</pre>';

    return array(
      '#type' => 'markup',
      '#markup' => $output,
    );
  }
}
