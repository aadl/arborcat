<?php
namespace Drupal\arborcat\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides a Patron Holds Block.
 *
 * @Block(
 *   id = "holds_block",
 *   admin_label = @Translation("Holds block"),
 * )
 */
class HoldsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Get Checkouts from API
    $json = file_get_contents('http://nginx2/patron/holds');
    $holds = json_decode($json);

    $rows = array();
    foreach ($holds as $hold) {
      $rows[] = (array) $hold;
    }

    return array(
      '#markup' => '<h1>USER HOLDS</h1>',
      'holds_table' => array(
        '#type' => 'table',
        '#header' => array_keys($rows[0]),
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
