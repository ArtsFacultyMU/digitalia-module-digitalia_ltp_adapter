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
		//$form['actions']['#type'] = 'actions';
		$form['submit'] = [
			'#type' => 'submit',
			'#value' => $this->t('Generate metadata bundle'),
			'#name' => 'main'
		];

		$form['other'] = [
			'#type' => 'submit',
			'#value' => $this->t('Start transfer'),
			'#name' => 'other',
			'#submit' => [ [$this, 'submitOtherForm'] ]
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
		$utils->archiveData($node);

	}

	/**
	 * {@inheritdoc}
	 */
	public function submitOtherForm(array &$form, FormStateInterface $form_state)
	{
		dpm("Other Form!");
		$utils = new DigitaliaLtpUtils();
		$utils->startIngest();
	}
}
