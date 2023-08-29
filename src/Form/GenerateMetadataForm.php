<?php

namespace Drupal\digitalia_ltp_adapter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use Drupal\digitalia_ltp_adapter\DigitaliaLtpUtils;

class GenerateMetadataForm extends FormBase
{
	const DIRECTORY = "public://digitalia_ltp";

	/**
	 * {@inheritdoc}
	 */
	public function getFormId()
	{
		return 'generate_metadata_form';
	}


	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state)
	{
		$form['actions']['#type'] = 'actions';
		$form['actions']['submit'] = [
			'#type' => 'submit',
			'#value' => $this->t('Generate metadata bundle'),
			'#button_type' => 'primary',
		];
		return $form;
	}

	/**
	 * {@inheritdoc}
	 */
	public function validateForm(array &$form, FormStateInterface $form_state) {}
	
	
	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state)
	{
		dpm("Form submitted!");
		$node = \Drupal::routeMatch()->getParameter('node');

		$utils = new DigitaliaLtpUtils();
		$utils->prepareData($node);


	}






}
