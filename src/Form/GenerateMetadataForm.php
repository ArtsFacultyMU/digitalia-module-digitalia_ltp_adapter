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

		$form['export'] = [
			'#type' => 'select',
			'#title' => $this->t('Select export type'),
			'#options' => ["single", "separate"]
		];

		$form['media_toggle'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Include media'),
		];

		$form['language_toggle'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Single language only'),
		];

		$form['submit'] = [
			'#type' => 'submit',
			'#value' => $this->t('Generate metadata bundle'),
			'#name' => 'main'
		];

		$form['other_0'] = [
			'#type' => 'submit',
			'#value' => $this->t('Config test'),
			'#name' => 'other0',
			'#submit' => [ [$this, 'submitOtherForm0'] ]
		];

		$form['other_1'] = [
			'#type' => 'submit',
			'#value' => $this->t('Field config'),
			'#name' => 'other1',
			'#submit' => [ [$this, 'submitOtherForm1'] ]
		];

		$form['other_2'] = [
			'#type' => 'submit',
			'#value' => $this->t('Cleanup'),
			'#name' => 'other2',
			'#submit' => [ [$this, 'submitOtherForm2'] ]
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
		$media = \Drupal::routeMatch()->getParameter('media');
		$taxonomy_term = \Drupal::routeMatch()->getParameter('taxonomy_term');

		$entity = null;

		if ($node) {
			$entity = $node;
		}

		if ($media) {
			$entity = $media;
		}

		if ($taxonomy_term) {
			$entity = $taxonomy_term;
		}

		//dpm(\Drupal::routeMatch()->getParameters());

		$debug_settings = array(
			'media_toggle' => $form_state->getValue('media_toggle'),
			'language_toggle' => $form_state->getValue('language_toggle'),
		);

		$utils = new DigitaliaLtpUtils($debug_settings);

		$key = $form_state->getValue('export');
		$value = $form['export']['#options'][$key];
		dpm("Key: " . $key);
		dpm("Value: " . $value);

		switch($value) {
		case 'separate':
			$export_type = $utils::Separate;
			break;
		case 'single':
			$export_type = $utils::Single;
			break;
		default:
			$export_type = $utils::Single;
		}

		dpm("export_type: " . $export_type);


		$this->directories = $utils->archiveData($entity, $export_type);
		dpm("Directories:");
		dpm($this->directories);


		foreach ($this->directories as $directory) {
			$utils->startIngest($directory);
		}

	}

	public function submitOtherForm0(array &$form, FormStateInterface $form_state)
	{
		dpm("Other Form!");
		$config = \Drupal::config('digitalia_ltp_adapter.admin_settings');
		dpm("test_field: " . $config->get('test_field'));
		dpm("am_host: " . $config->get('am_host'));
		dpm("api_key_username: " . $config->get('api_key_username'));
		dpm("api_key_password: " . $config->get('api_key_password'));
		dpm("base_url: " . $config->get('base_url'));
		dpm("site_name: " . $config->get('site_name'));
		dpm("field_configuration:<br>" . $config->get('field_configuration'));
	}

	public function submitOtherForm1(array &$form, FormStateInterface $form_state)
	{
		$utils = new DigitaliaLtpUtils();
		$utils->printFieldConfig();
	}

	public function submitOtherForm2(array &$form, FormStateInterface $form_state)
	{
		$utils = new DigitaliaLtpUtils();
		$node = \Drupal::routeMatch()->getParameter('node');
		$media = \Drupal::routeMatch()->getParameter('media');
		$taxonomy_term = \Drupal::routeMatch()->getParameter('taxonomy_term');

		$entity = null;

		if ($node) {
			$entity = $node;
		}

		if ($media) {
			$entity = $media;
		}

		if ($taxonomy_term) {
			$entity = $taxonomy_term;
		}

		$utils->preExportCleanup($entity);
	}
}
