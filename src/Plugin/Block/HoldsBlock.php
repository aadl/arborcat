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
    $locations = json_decode($guzzle->get("http://$api_url/locations")->getBody()->getContents());

    $output = '<h2>Requests</h2>';
    if (count($holds)) {
      $output .= '<table id="holds-table"><thead><tr>';
      $output .= '<th></th>';
      $output .= '<th>Title</th>';
      $output .= '<th>Author</th>';
      $output .= '<th>Status</th>';
      $output .= '<th>Pickup</th>';
      $output .= '<th>Modify</th>';
      $output .= '</tr></thead><tbody>';
      foreach ($holds as $k => $hold) {
        // change display / value depending on if request is frozen
        if ($hold->hold->frozen == 'f') {
          $opt_val = 'frozen=t';
          $opt_display = 'Suspend';
        } else {
          $opt_val = 'frozen=f';
          $opt_display = 'Unsuspend';
        }
        // used to cancel a hold by updating canceled_time field
        $cur_time = date('Y-m-d');
        $options = '<option value="">Modify</option>';
        // show suspend and pickup options if hold isn't already in-transit or ready
        if ($hold->status != 'In-Transit' && $hold->status != 'Ready for Pickup') {
          $options .= "<option value=\"$opt_val\">$opt_display</option>";
          foreach ($locations as $n => $loc) {
            $options .= "<option value=\"pickup_lib=$n\">Pickup: $loc</option>";
          }
        }
        $options .= "<option value=\"cancel_time=$cur_time\">Cancel</option>";

        $output .="<td><input type=\"checkbox\" value=\"$k\"></td>";
        $output .= "<td><a href=\"/catalog/record/$hold->bnum\">$hold->title</a></td>";
        $output .= "<td>$hold->author</td>";
        $output .= "<td class=\"request-status\">$hold->status</td>";
        $output .= "<td class=\"request-pickup\">$hold->pickup</td>";
        $output .= "<td>
            <select class=\"request-modify\" data-request-id=\"$k\" data-api-key=\"$api_key\">
              $options
            </select>
          </td>";
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
      '#allowed_tags' => ['table', 'thead', 'th', 'tbody', 'tr', 'td', 'input', 'p', 'em', 'h2', 'a', 'select', 'option']
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
