<?php
namespace Drupal\arborcat\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides a Patron Checkouts Block.
 *
 * @Block(
 *   id = "checkouts_block",
 *   admin_label = @Translation("Checkouts block"),
 * )
 */
class CheckoutsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $api_url = \Drupal::config('arborcat.settings')->get('api_url');
    $user = \Drupal::routeMatch()->getParameter('user');
    $api_key = $user->get('field_api_key')->value;

    // Get Checkouts from API
    $guzzle = \Drupal::httpClient();
    $json = $guzzle->get("http://$api_url/patron/$api_key/checkouts")->getBody()->getContents();
    $checkouts = json_decode($json);

    $rows = array();
    foreach ($checkouts as $category) {
      foreach ($category as $checkout) {
        $rows[] = (array) $checkout; //$checkout_list .= "<li>$checkout->format: $checkout->title by $checkout->author</li>";
      }
    }

    return array(
      '#cache' => array(
        'max-age' => 0, // Don't cache, always get fresh data
      ),
      '#markup' => '<h1>USER CHECKOUTS</h1>',
      'checkout_table' => array(
        '#type' => 'table',
        '#header' => array_keys($rows[0]),
        '#rows' => $rows,
      ),
//      '#attached' => array('library' => array('arborcat/patron-functions')),
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
