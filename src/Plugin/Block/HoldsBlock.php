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
      $output .= "<table id=\"holds-table\" class=\"l-overflow-clear\" data-api-key=\"$api_key\"><thead><tr>";
      $output .= '<th id="holds-checkbox" class="no-mobile-display">&#10004;</th>';
      $output .= '<th>Title</th>';
      $output .= '<th class="no-mobile-display">Author</th>';
      $output .= '<th>Status</th>';
      $output .= '<th class="no-mobile-display">Pickup</th>';
      $output .= '<th>Modify</th>';
      $output .= '</tr></thead><tbody>';

      // used to cancel a hold by updating canceled_time field
      $cur_time = date('Y-m-d');
      // build location change options for individual and modify selected
      $locOptions = '';
      foreach ($locations as $n => $loc) {
        $locOptions .= "<option value=\"pickup_lib=$n\">Pickup: $loc</option>";
      }
      foreach ($holds as $k => $hold) {
        // change display / value depending on if request is frozen
        if ($hold->hold->frozen == 'f') {
          $opt_val = 'frozen=t';
          $opt_display = 'Freeze';
        } else {
          $opt_val = 'frozen=f';
          $opt_display = 'Unfreeze';
        }
        
        $options = '<option value="">Modify</option>';
        // show suspend and pickup options if hold isn't already in-transit or ready
        if ($hold->status != 'In-Transit' && $hold->status != 'Ready for Pickup') {
          $options .= "<option value=\"$opt_val\">$opt_display</option>";
          $options .= $locOptions;
        }
        $options .= "<option value=\"cancel_time=$cur_time\">Cancel Request</option>";

        if ($hold->status == 'Suspended') {
          $hold->status = 'Frozen';
        } elseif ($hold->status == 'Waiting for Capture') {
          $hold->status = "You're next!";
        } elseif ($hold->status == 'Waiting for Copy') {
          $hold->status = 'Waiting for Item';
        }
        $output .="<td class=\"no-mobile-display\"><input class=\"modify-checkbox\" type=\"checkbox\" value=\"$k\"></td>";
        $output .= "<td><a href=\"/catalog/record/$hold->bnum\">" . (strlen($hold->title) > 35 ? substr($hold->title, 0, 35) . '...' : $hold->title) . "</a></td>";
        $output .= "<td class=\"no-mobile-display\">$hold->author</td>";
        $output .= "<td class=\"request-status\">$hold->status</td>";
        $output .= "<td class=\"request-pickup no-mobile-display\">$hold->pickup</td>";
        $output .= "<td class=\"modify-column\">
            <div><select class=\"request-modify\" data-request-id=\"$k\">
              $options
            </select></div>
          </td>";
        $output .= '</tr>'; 
      }
      $output .= '</tbody></table>';
      $output .= "<select id=\"request-modify-all\" class=\"no-mobile-display\">
                    <option value=\"\">Modify Selected Holds</option>
                    <option value=\"frozen=t\">Freeze</option>
                    <option value=\"frozen=f\">Unfreeze</option>
                    $locOptions
                    <option value=\"cancel_time=$cur_time\">Cancel Requests</option>
                 </select>";
    } else {
      $output .= '<p><em>You have no requested items</em></p>';
    }
    
    return array(
      '#cache' => array(
        'max-age' => 0, // Don't cache, always get fresh data
      ),
      '#markup' => $output,
      '#allowed_tags' => ['table', 'thead', 'th', 'tbody', 'tr', 'td', 'input', 'p', 'em', 'h2', 'a', 'select', 'option', 'div']
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
