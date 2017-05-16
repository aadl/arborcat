<?php
namespace Drupal\arborcat\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides a Patron Info Block.
 *
 * @Block(
 *   id = "patron_block",
 *   admin_label = @Translation("Patron block"),
 * )
 */
class PatronBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Get Checkouts from API
    $json = file_get_contents('http://nginx2/patron/get');
    $patron = json_decode($json);

    $rows = array();
    foreach ($patron as $field => $info) {
      $rows[] = array($field, $info);
    }

    return array(
      '#markup' => '<h1>PATRON INFORMATION</h1>',
      'patron_table' => array(
        '#type' => 'table',
        '#rows' => $rows,
      ),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account, $return_as_object = FALSE) {
    $route_name = \Drupal::routeMatch()->getRouteName();
    return ($route_name == 'entity.user.canonical' ? AccessResult::allowed() : AccessResult::forbidden());
  }
}
