<?php

namespace Drupal\digitalia_ltp_adapter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use Drupal\digitalia_ltp_adapter\DigitaliaLtpUtils;
use Drupal\digitalia_ltp_adapter\ExportMode;

class GenerateMetadataForm extends FormBase
{
	private $directories;

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

		//$form['other'] = [
		//	'#type' => 'submit',
		//	'#value' => $this->t('Start transfer'),
		//	'#name' => 'other',
		//	'#submit' => [ [$this, 'submitOtherForm'] ]
		//];

		$form['export'] = [
			'#type' => 'select',
			'#title' => $this->t('Select export type'),
			'#options' => ["separate", "tree", "flat", "single"],
		];

		$form['ingest'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Start archivematica ingest'),
		];

		$form['submit'] = [
			'#type' => 'submit',
			'#value' => $this->t('Generate metadata bundle'),
			'#name' => 'main'
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

		$key = $form_state->getValue('export');
		$value = $form['export']['#options'][$key];
		dpm("Key: " . $key);
		dpm("Value: " . $value);

		switch($value) {
		case 'tree':
			$export_type = $utils::Tree;
			break;
		case 'separate':
			$export_type = $utils::Separate;
			break;
		case 'flat':
			$export_type = $utils::Flat;
			break;
		case 'single':
			$export_type = $utils::Single;
			break;
		default:
			$export_type = $utils::Flat;
		}

		dpm("export_type: " . $export_type);

		$this->directories = $utils->archiveData($node, $export_type);
		dpm("Directories:");
		dpm($this->directories);

		if ($form_state->getValue('ingest')) {
			$utils->startIngest($this->directories);
		}

	}

	/**
	 * {@inheritdoc}
	 */
	public function submitOtherForm(array &$form, FormStateInterface $form_state)
	{
		dpm("Other Form!");
		$utils = new DigitaliaLtpUtils();
		dpm($this->directories);
		//$utils->startIngest($this->directories);
	}
}
