<?php

/**
 * @file
 * Contains \Drupal\arborcat\Form\ArborcatHoldsReadySearchForm
 */

namespace Drupal\arborcat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

class ArborcatHoldsReadySearchForm extends FormBase
{
    public function getFormId()
    {
        return 'arborcat_locations_search_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $form['arborcat_holds_ready_search_form'] = [
        '#title' => "Search locations with Requests Ready using Patron's Barcode",
        '#type' => 'textfield',
        '#maxlength' => 500,
        '#default_value' => ($_GET['bcode'] ?? ''),
        '#attributes' => ['pattern' => '31621[0-9]{9}']
      ];
        $form['bcode-submit'] = [
        '#type' => 'submit',
        '#value' => 'Search',
      ];
        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $form_state->setRedirect('arborcat_patron_requests_ready_locations_form', [], ['query' => ['bcode' => $form_state->getValue('arborcat_holds_ready_search_form')]]);
    }
}
