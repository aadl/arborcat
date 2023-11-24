<?php

/**
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
class DefaultController extends ControllerBase
{

  public function user_lists($uid = NULL)
  {
    $current_uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    if ($current_uid != $uid && !$user->hasPermission('administer users')) {
      \Drupal::messenger()->addWarning('You are not authorized to view these lists');

      return new RedirectResponse(\Drupal\Core\Url::fromRoute('user.page'));
    }

    $lists = arborcat_lists_get_lists($uid);

    // build the pager
    $pager_manager = \Drupal::service('pager.manager');
    $pager_params = \Drupal::service('pager.parameters');
    $page = $pager_params->findPage();
    $per_page = 20;
    $offset = $per_page * $page;
    $pager = $pager_manager->createPager(count($lists), $per_page);

    $lists = arborcat_lists_get_lists($uid, $offset, $per_page);

    return [
      [
        '#theme' => 'user_lists',
        '#lists' => $lists,
        '#total_results' => count($lists),
        '#cache' => ['max-age' => 0],
        '#pager' => [
          '#type' => 'pager',
          '#quantity' => 5
        ]
      ]
    ];
  }

  public function user_checkout_history()
  {
    $user = \Drupal::currentUser();
    if (!$user->isAuthenticated()) {
      \Drupal::messenger()->addMessage("Sign in to see your checkout history.");
      return new RedirectResponse("/user/login?destination=" . $_SERVER['REQUEST_URI']);
    }

    $db = \Drupal::database();
    $checkout_list = $db->query("SELECT * FROM arborcat_user_lists WHERE title='Checkout History' AND uid=:uid", [':uid' => $user->id()])->fetch();
    if ($checkout_list->id) {
      return new RedirectResponse("/user/lists/" . $checkout_list->id);
    } else {
      \Drupal::messenger()->addMessage(['#markup' => 'You do not have a checkout history list. Enable checkout history in your <a href="/user/' . $user->id() . '/edit">preferences</a>.']);
      return new RedirectResponse("/user");
    }
  }

  public function view_public_lists()
  {
    $db = \Drupal::database();

    $pager_manager = \Drupal::service('pager.manager');
    $pager_params = \Drupal::service('pager.parameters');
    $page = $pager_params->findPage();
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
      $total = count($db->query("SELECT * FROM arborcat_user_lists WHERE public=1")->fetchAll());
      $lists = $db->query("SELECT * FROM arborcat_user_lists WHERE public=1 ORDER BY id DESC $limit")->fetchAll();
    }

    for ($i = 0; $i < count($lists); $i++) {
      $query = $db->query(
        "SELECT bib FROM arborcat_user_list_items WHERE list_id=:lid ORDER BY list_order DESC LIMIT 1",
        [':lid' => $lists[$i]->id]
      );
      $res = $query->fetch();
      if (isset($res->bib)) {
        $lists[$i]->bib = $res->bib;
      } else {
        unset($lists[$i]);
        continue;
      }
      $user = \Drupal\user\Entity\User::load($lists[$i]->uid);
      $lists[$i]->username = (isset($user) ? $user->get('name')->value : 'unknown');
      if (isset($user)) {
        if ($user->hasPermission('access accountfix')) {
          $lists[$i]->staff = TRUE;
        }
      }
      unset($user);
    }

    // build the pager
    $pager = $pager_manager->createPager($total, $per_page);

    return [
      [
        '#theme' => 'user_lists',
        '#lists' => $lists,
        '#total_results' => $total,
        '#pub_view' => true,
        '#cache' => ['max-age' => 0],
        '#pager' => [
          '#type' => 'pager',
          '#quantity' => 5
        ]
      ]
    ];
  }

  public function view_user_list($lid = NULL)
  {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();
    $patron_display_name = '';
    // grab list uid
    $query = $connection->query(
      "SELECT * FROM arborcat_user_lists WHERE id=:lid",
      [':lid' => $lid]
    );
    $list = $query->fetch();
    if ($list != null) {
      if ($user->get('uid')->value == $list->uid || $list->public || $user->hasPermission('administer users')) {
        // Checkout History manual refresh
        if ($list->title == 'Checkout History') {
          $list_user = \Drupal\user\Entity\User::load($list->uid);
          if ($list_user->get('profile_cohist')->value) {
            arborcat_lists_update_user_history($list->uid);
            // get the display name for the Checkout History list

            // get the patron's name for use when displaying the "Checkout History" list titles in the theme
            $additional_accounts = arborcat_additional_accounts($list_user);
            foreach ($additional_accounts as $evg_account) {
              if ($evg_account['patron_id'] == $list->pnum) {
                $patron_display_name = $evg_account['subaccount']->name;
                break;
              }
            }
          }
        }

        $query = $connection->query(
          "SELECT * FROM arborcat_user_list_items WHERE list_id=:lid ORDER BY list_order DESC",
          [':lid' => $lid]
        );

        $term = (!empty($_GET['search']) ? $_GET['search'] : '*');
        $sort = ($_GET['sort'] ?? 'list_order_desc');
        $items = arborcat_lists_search_list_items($lid, $term, $sort);

        // build the pager
        $total = ($items != null) ? $items['hits']['total']['value'] : 0;
        $pager_manager = \Drupal::service('pager.manager');
        $pager_params = \Drupal::service('pager.parameters');
        $page = $pager_params->findPage();
        $per_page = 20;
        $offset = $per_page * $page;
        $pager = $pager_manager->createPager($total, $per_page);

        $list_items = [];
        $list_items['user_owns'] = ($user->get('uid')->value == $list->uid || $user->hasPermission('access accountfix') ? true : false);
        $list_items['title'] = $list->title;
        $list_items['patron_display_name'] = $patron_display_name;
        $list_items['id'] = $lid;
        if ($items != null && count($items['hits']['hits'])) {
          $api_url = \Drupal::config('arborcat.settings')->get('api_url');
          $guzzle = \Drupal::httpClient();

          foreach ($items['hits']['hits'] as $item) {
            $bib_record = $item['_source'];
            $mat_types = $guzzle->get("$api_url/mat-names")->getBody()->getContents();
            $mat_name = json_decode($mat_types);
            $bib_record['mat_name'] = $mat_name->{$bib_record['mat_code']};
            $bib_record['_id'] = $item['_id'];
            $list_items['items'][$item['_id']] = $bib_record;
          }

          if ($sort == 'list_order' || $sort == 'list_order_desc') {
            $order = ($sort == 'list_order' ? SORT_ASC : SORT_DESC);
            $sorting = [];
            foreach ($list_items['items'] as $key => $row) {
              $sorting[$key] = $row['list_order'];
            }
            array_multisort($sorting, $order, $list_items['items']);
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
              '#quantity' => 5
            ]
          ]
        ];
      } else {
        return [
          '#title' => t('Access Denied'),
          '#markup' => t('You do not have permission to view this list')
        ];
      }
    } else {
      return [
        '#title' => t('List not found'),
        '#markup' => t('The requested list could not be found')
      ];
    }
  }

  public function delete_list($lid)
  {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();
    // grab list uid
    $query = $connection->query(
      "SELECT * FROM arborcat_user_lists WHERE id=:lid",
      [':lid' => $lid]
    );
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

  public function add_list_item($lid, $bib)
  {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();

    if ($lid == 'wishlist') {
      $query = $connection->query(
        "SELECT * FROM arborcat_user_lists WHERE uid=:uid AND title = 'Wishlist'",
        [':uid' => $user->get('uid')->value]
      );
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
      } else {
        $lid = $result->id;
      }
    } else {
      // grab list uid
      $query = $connection->query(
        "SELECT uid FROM arborcat_user_lists WHERE id=:lid",
        [':lid' => $lid]
      );
      $result = $query->fetch();
    }

    if ($user->get('uid')->value == $result->uid || $user->hasRole('administrator')) {
      // check if bib is already in the list
      $query = $connection->query(
        "SELECT bib FROM arborcat_user_list_items WHERE list_id=:lid AND bib=:bib",
        [':lid' => $lid, ':bib' => $bib]
      );
      if (count($query->fetchAll())) {
        $response['error'] = 'Item is already on this list';

        return new JsonResponse($response);
      }

      // get total items in list for list_order column insert
      $query = $connection->query(
        "SELECT item_id FROM arborcat_user_list_items WHERE list_id=:lid",
        [':lid' => $lid]
      );
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

  public function delete_list_item($lid, $bib)
  {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();

    // grab list uid
    $query = $connection->query(
      "SELECT uid FROM arborcat_user_lists WHERE id=:lid",
      [':lid' => $lid]
    );
    $result = $query->fetch();

    if ($user->get('uid')->value == $result->uid || $user->hasRole('staff')) {
      $query = $connection->query(
        "SELECT * FROM arborcat_user_list_items WHERE list_id=:lid AND bib=:bib",
        [':lid' => $lid, ':bib' => $bib]
      );
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

  public function download_user_list($lid)
  {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $db = \Drupal::database();
    $query = $db->query("SELECT * FROM arborcat_user_lists WHERE id=:lid", [':lid' => $lid]);
    $list = $query->fetch();
    // do an access check here in case someone messes with a button/link
    if ($user->get('uid')->value == $list->uid || $list->public || $user->hasPermission('administer users')) {
      $l_title = $list->title;
      // prep for loading and parsing indvidual list items
      $guzzle = \Drupal::httpClient();
      $api_url = \Drupal::config('arborcat.settings')->get('api_url');
      $mat_types = $guzzle->get("$api_url/mat-names")->getBody()->getContents();
      $mat_name = json_decode($mat_types);

      // set up format for generating a csv
      $rows = [];
      $header = ['Title', 'Author', 'Format', 'Website Link', 'Date Added to List'];
      $rows[] = implode(',', $header);
      $query = $db->query(
        "SELECT * FROM arborcat_user_list_items WHERE list_id=:lid ORDER BY list_order",
        [':lid' => $lid]
      );
      $items = $query->fetchAll();
      foreach ($items as $item) {
        $bnum = $item->bib;
        try {
          $json = $guzzle->get("$api_url/record/$bnum/full")->getBody()->getContents();
          $json = json_decode($json);
          if (!empty($json->title)) {
            $record = [
              "\"$json->title\"",
              "\"$json->author\"",
              $mat_name->{$json->mat_code},
              'https://aadl.org/catalog/record/' . $item->bib,
              ($item->timestamp ? date('m-d-Y', $item->timestamp) : '')
            ];
            $rows[] = implode(',', $record);
          }
        } catch (\Exception $e) {
          // item is no longer in catalog
          // not doing anything here
        }
      }

      $list = implode("\n", $rows);
      $response = new Response($list);
      $response->headers->set('Content-Type', 'text/csv');
      $response->headers->set('Content-Disposition', 'attachment; filename="' . str_replace(' ', '-', $l_title) . '.csv"');

      return $response;
    } else {
      \Drupal::messenger()->addWarning('You do not have permission to download this list');
      return new RedirectResponse(\Drupal\Core\Url::fromRoute('user.page'));
    }
  }

  public function fix_checkout_history()
  {
    $result = arborcat_fix_checkout_history();
    return new JsonResponse($result);
  }
}
