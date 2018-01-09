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
    try {
      $json = $guzzle->get("$api_url/patron/$api_key/holds", ['timeout' => 180])->getBody()->getContents();
    }
    catch (\Exception $e) {
      drupal_set_message('Error retrieving requests', 'error');
      return [
        '#cache' => [
          'max-age' => 0, // Don't cache, always get fresh data
        ],
        '#markup' => "<h2 id=\"requests\">Requests</h2>"
      ];
    }

    $holds = (array) json_decode($json);
    $total = (count($holds) ? ' <span id="requests-count">(<span id="requests-count-num">' . count($holds) . '</span>)</span>' : '');
    $locations = json_decode($guzzle->get("$api_url/locations")->getBody()->getContents());

    $output = "<h2 id=\"requests\">Requests$total</h2>";
    if (count($holds)) {
      $output .= "<table id=\"holds-table\" class=\"l-overflow-clear\" data-api-key=\"$api_key\"><thead><tr>";
      $output .= '<th class="no-mobile-display check-all no-sort" data-checked="false" data-sort-method="none">&#10004;</th>';
      $output .= '<th>Title</th>';
      $output .= '<th class="no-mobile-display">Author</th>';
      $output .= '<th class="no-mobile-display no-tab-display">Format</th>';
      $output .= '<th id="status-column">Status</th>';
      $output .= '<th class="no-mobile-display">Pickup</th>';
      $output .= '<th class="no-sort" data-sort-method="none">Modify</th>';
      $output .= '</tr></thead><tbody>';

      // used to cancel a hold by updating canceled_time field
      $cur_time = date('Y-m-d');
      // build location change options for individual and modify selected
      $locOptions = '';
      foreach ($locations as $n => $loc) {
        $locOptions .= "<option value=\"pickup_lib=$n\">Pickup: $loc</option>";
      }
      $count = 1;
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
        } elseif ($hold->status == 'Ready for Pickup') {
          $expire = strtotime($hold->hold->shelf_expire_time);
          $hold->status .= ' through: ' . date('m-d-Y', $expire);
        } else {
          $hold->status = $hold->queue->queue_position . ' of ' . $hold->queue->total_holds;
        }
        $author = $hold->mvr->author;
        $output .= ($count > 50 ? '<tr class="hide-row">' : '<tr>');
        $output .="<td class=\"no-mobile-display\"><input class=\"modify-checkbox\" type=\"checkbox\" value=\"$k\"></td>";
        $output .= "<td><a href=\"/catalog/record/$hold->bnum\">" . (strlen($hold->title) > 35 ? substr($hold->title, 0, 35) . '...' : $hold->title) . " <span class=\"no-desk-display\">($hold->material)</span></a></td>";
        $output .= "<td class=\"no-mobile-display\"><a href=\"/search/catalog/author:&quot;$author&quot;\">$author</a></td>";
        $output .= "<td class=\"no-mobile-display no-tab-display\">$hold->material</td>";
        $output .= "<td class=\"request-status\">$hold->status</td>";
        $output .= "<td class=\"request-pickup no-mobile-display\">$hold->pickup</td>";
        $output .= "<td class=\"modify-column\">
            <div><select class=\"request-modify\" data-request-id=\"$k\" aria-describedby=\"aria-selects\">
              $options
            </select></div>
          </td>";
        $output .= '</tr>';
        $count++;
      }
      $output .= '</tbody></table>';
      if ($count - 1 > 50) $output .= '<a href="" data-target="#holds-table" data-state="hidden" class="show-table-rows l-block base-margin-bottom">View All</a>';
      $output .= "<div id=\"modify-all-container\"><select id=\"request-modify-all\" class=\"no-mobile-display\" aria-describedby=\"aria-selects\">
                    <option value=\"\">Modify Selected Holds</option>
                    <option value=\"frozen=t\">Freeze</option>
                    <option value=\"frozen=f\">Unfreeze</option>
                    $locOptions
                    <option value=\"cancel_time=$cur_time\">Cancel Requests</option>
                 </select></div>";
    } else {
      $output .= '<p><em>You have no requested items</em></p>';
    }
    // protect against varying evg char encoding
    $output = mb_convert_encoding($output, "UTF-8");

    return array(
      '#cache' => array(
        'max-age' => 0, // Don't cache, always get fresh data
      ),
      '#markup' => $output,
      '#allowed_tags' => ['table', 'thead', 'th', 'tbody', 'tr', 'td', 'input', 'p', 'em', 'h2', 'a', 'select', 'option', 'div', 'span']
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
