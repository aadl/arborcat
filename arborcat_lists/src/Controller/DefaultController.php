<?php /**
 * @file
 * Contains \Drupal\arborcat_lists\Controller\DefaultController.
 */

namespace Drupal\arborcat_lists\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Default controller for the arborcat_lists module.
 */
class DefaultController extends ControllerBase {

  public function user_lists($uid = NULL) {
    if (!$uid) {
      $uid = \Drupal::currentUser()->id();
      if (!$uid) {
        // Anonymous user, redirect to front page
        drupal_set_message('Please log in or create an account to access your lists', 'warning');
        return new RedirectResponse(\Drupal::url('user.page'));
      }
    }
    $lists = arborcat_lists_get_lists($uid);

    // build the pager
    $page = pager_find_page();
    $per_page = 20;
    $offset = $per_page * $page;
    $pager = pager_default_initialize(count($lists), $per_page);

    $lists = arborcat_lists_get_lists($uid, $offset, $per_page);

    return [
      [
        '#theme' => 'user_lists',
        '#lists' => $lists,
        '#total_results' => count($lists),
        '#cache' => ['max-age' => 0],
        '#pager' => [
          '#type' => 'pager',
          '#quantity' => 3
        ]
      ]
    ];
  }

  public function view_public_lists() {
    
    $page = pager_find_page();
    $per_page = 20;
    $offset = $per_page * $page;
    $limit = (isset($offset) && isset($per_page) ? " LIMIT $offset, $per_page" : '');

    // grab lists from DB
    if (!empty($_GET['search'])) {
      $term = $_GET['search'];
      $lists = arborcat_lists_search_lists($term);
      $total = count($lists['total']);
      $lists = $lists['lists'];
    } else {
      $db = \Drupal::database();
      $total = count($db->query("SELECT * FROM arborcat_user_lists WHERE public=1")->fetchAll());
      $lists = $db->query("SELECT * FROM arborcat_user_lists WHERE public=1 $limit")->fetchAll();
    }

    // build the pager
    $pager = pager_default_initialize($total, $per_page);

    return [
      [
        '#theme' => 'user_lists',
        '#lists' => $lists,
        '#total_results' => $total,
        '#pub_view' => true,
        '#cache' => ['max-age' => 0],
        '#pager' => [
          '#type' => 'pager',
          '#quantity' => 3
        ]
      ]
    ];
  }

  public function view_user_list($lid = NULL) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();

    // grab list uid
    $query = $connection->query("SELECT * FROM arborcat_user_lists WHERE id=:lid",
      [':lid' => $lid]);
    $list = $query->fetch();
    if ($user->get('uid')->value == $list->uid || $list->public || $user->hasPermission('administer users')) {

      // Checkout History manual refresh
      if ($list->title == 'Checkout History') {
        $list_user = \Drupal\user\Entity\User::load($list->uid);
        if ($list_user->get('profile_cohist')->value) {
          arborcat_lists_update_user_history($list->uid);
        }
      }
      
      $query = $connection->query("SELECT * FROM arborcat_user_list_items WHERE list_id=:lid ORDER BY list_order ASC",
        [':lid' => $lid]);
      $total = $query->fetchAll();

      $term = (!empty($_GET['search']) ? $_GET['search'] : '*');
      $sort = ($_GET['sort'] ?? 'list_order');
      $items = arborcat_lists_search_list_items($lid, $term, $sort);

      // build the pager
      $total = (!empty($_GET['search']) ? $items['hits']['total'] : count($total));
      $page = pager_find_page();
      $per_page = 20;
      $offset = $per_page * $page;
      $pager = pager_default_initialize($total, $per_page);

      $list_items = [];
      $list_items['user_owns'] = ($user->get('uid')->value == $list->uid || $user->hasPermission('administer users') ? true : false);
      $list_items['title'] = $list->title;
      $list_items['id'] = $lid;
      if (count($items['hits']['hits'])) {
        $api_url = \Drupal::config('arborcat.settings')->get('api_url');
        $guzzle = \Drupal::httpClient();

        foreach ($items['hits']['hits'] as $item) {
          $bib_record = $item['_source'];
          $mat_types = $guzzle->get("$api_url/mat-names")->getBody()->getContents();
          $mat_name = json_decode($mat_types);
          $bib_record['mat_name'] = $mat_name->{$bib_record['mat_code']};
          $list_items['items'][$item['_id']] = $bib_record;
        }

        if ($sort == 'list_order') {
          $sorting = [];
          foreach ($list_items['items'] as $key => $row) {
            $sorting[$key] = $row[$sort];
          }
          array_multisort($sorting, SORT_ASC, $list_items['items']);
        }
      }

      return [
        [
          '#title' => t($list->title),
          '#theme' => 'user_list_view',
          '#list_items' => $list_items,
          '#total_results' => $total,
          '#cache' => ['max-age' => 0],
          '#pager' => [
            '#type' => 'pager',
            '#quantity' => 3
          ]
        ]
      ];
    } else {
      return [
        '#title' => t('Access Denied'),
        '#markup' => t('You do not have permission to view this list')
      ];
    }

  }

  public function delete_list($lid) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();

    // grab list uid
    $query = $connection->query("SELECT * FROM arborcat_user_lists WHERE id=:lid",
      [':lid' => $lid]);
    $result = $query->fetch();

    if ($user->get('uid')->value == $result->uid || $user->hasRole('administrator')) {
      $connection->delete('arborcat_user_list_items')
        ->condition('list_id', $result->id)
        ->execute();
      $connection->delete('arborcat_user_lists')
        ->condition('id', $result->id)
        ->execute();

      $response['success'] = 'List deleted';
    } else {
      $response['error'] = "You don't have permission to delete this list";
    }

    return new JsonResponse($response);
  }

  public function add_list_item($lid, $bib) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();

    if ($lid == 'wishlist') {
      $query = $connection->query("SELECT * FROM arborcat_user_lists WHERE uid=:uid AND title = 'Wishlist'",
        [':uid' => $user->get('uid')->value]);
      $result = $query->fetch();
      if (!$result->id) {
        // Create new wishlist
        $lid = $connection->insert('arborcat_user_lists')
          ->fields([
            'uid' => $user->get('uid')->value,
            'pnum' => $user->field_patron_id->value,
            'title' => 'Wishlist',
            'description' => '',
            'public' => 0,
          ])
          ->execute();
        $result->uid = $user->get('uid')->value;
      }
      else {
        $lid = $result->id;
      }
    }
    else {
      // grab list uid
      $query = $connection->query("SELECT uid FROM arborcat_user_lists WHERE id=:lid",
        [':lid' => $lid]);
      $result = $query->fetch();
    }

    if ($user->get('uid')->value == $result->uid || $user->hasRole('administrator')) {
      // check if bib is already in the list
      $query = $connection->query("SELECT bib FROM arborcat_user_list_items WHERE list_id=:lid AND bib=:bib",
        [':lid' => $lid, ':bib' => $bib]);
      if (count($query->fetchAll())) {
        $response['error'] = 'Item is already on this list';

        return new JsonResponse($response);
      }

      // get total items in list for list_order column insert
      $query = $connection->query("SELECT item_id FROM arborcat_user_list_items WHERE list_id=:lid",
        [':lid' => $lid]);
      $count = count($query->fetchAll());

      $connection->insert('arborcat_user_list_items')
        ->fields([
          'list_id' => $lid,
          'bib' => $bib,
          'timestamp' => time(),
          'list_order' => $count + 1
        ])
        ->execute();

      $response['success'] = 'Item added to list';
    } else {
      $response['error'] = 'You are not authorized to add to this list';
    }

    return new JsonResponse($response);
  }

  public function delete_list_item($lid, $bib) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();

    // grab list uid
    $query = $connection->query("SELECT uid FROM arborcat_user_lists WHERE id=:lid",
      [':lid' => $lid]);
    $result = $query->fetch();

    if ($user->get('uid')->value == $result->uid || $user->hasRole('administrator')) {
      $query = $connection->query("SELECT * FROM arborcat_user_list_items WHERE list_id=:lid AND bib=:bib",
        [':lid' => $lid, ':bib' => $bib]);
      $row = $query->fetch();
      $connection->delete('arborcat_user_list_items')
        ->condition('item_id', $row->item_id)
        ->execute();
      $connection->update('arborcat_user_list_items')
        ->condition('list_id', $lid, '=')
        ->condition('list_order', $row->list_order, '>')
        ->expression('list_order', 'list_order - 1')
        ->execute();

      $response['success'] = 'Item removed from list';
    } else {
      $response['error'] = "You don't have permission to delete this list item";
    }

    return new JsonResponse($response);
  }

}
