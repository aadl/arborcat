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
    $output = "BIB RECORD: $bnum";

    return array(
      '#type' => 'markup',
      '#markup' => $output,
    );
  }
}
