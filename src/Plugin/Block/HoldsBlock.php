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
    $api_url = \Drupal::config('arborcat.settings')->get('api_url');
    $user = \Drupal::routeMatch()->getParameter('user');
    $api_key = $user->get('field_api_key')->value;

    // Get holds from API
    $guzzle = \Drupal::httpClient();
    $json = $guzzle->get("http://$api_url/patron/$api_key/holds")->getBody()->getContents();
    $holds = json_decode($json);

    $output = '<h2>Requests</h2>';
    if (count($holds)) {
      $output .= '<table><thead><tr>';
      $output .= '<th></th>';
      $output .= '<th>Title</th>';
      $output .= '<th>Author</th>';
      $output .= '<th>Status</th>';
      $output .= '<th>Pickup</th>';
      $output .= '<th>Modify</th>';
      $output .= '</tr></thead><tbody>';
      foreach ($holds as $k => $hold) {
        $output .="<td><input type=\"checkbox\" value=\"$k\"></td>";
        $output .= "<td><a href=\"/catalog/record/$hold->bnum\">$hold->title</a></td>";
        $output .= "<td>$hold->author</td>";
        $output .= "<td>$hold->status</td>";
        $output .= "<td>$hold->pickup</td>";
        $output .= "<td>placeholder $k</td>";
        $output .= '</tr>'; 
      }
      $output .= '</tbody></table>';
    } else {
      $output .= '<p><em>You have no requested items</em></p>';
    }
    
    return array(
      '#cache' => array(
        'max-age' => 0, // Don't cache, always get fresh data
      ),
      '#markup' => $output,
      '#allowed_tags' => ['table', 'thead', 'th', 'tbody', 'tr', 'td', 'input', 'p', 'em', 'h2', 'a']
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
