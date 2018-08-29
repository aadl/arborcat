<?php

namespace Drupal\arborcat\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormBase;


class ArborcatReviewModForm extends FormBase {
   
    /**
     * {@inheritDoc}
     */
    public function getFormId() {
        return 'arborcat_review_mod_form';
    }

    /**
     * {@inheritDoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state) {

        $form['new'] = [
            '#type' => 'submit',
            '#value' => 'New',
        ];
        $form['flagged'] = [
            '#type' => 'submit',
            '#value' => 'Flagged',
        ];

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state) {
        //nothing to validate

    }

    public function submitForm(array &$form, FormStateInterface $form_state) {

        $trigger = $form_state->getTriggeringElement();
        switch ($trigger['#value']) {
            case ('New'):
                $form_state->setRedirect('arborcat.moderate_review', ['mode' => 'new']);
                break;
            case ('Flagged'):
                $form_state->setRedirect('arborcat.moderate_review', ['mode' => 'flagged']);
                break;
            default: 
                break;
        }

        return;
    }


}