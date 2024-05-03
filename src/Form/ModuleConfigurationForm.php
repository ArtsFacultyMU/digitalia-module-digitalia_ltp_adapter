<?php

namespace Drupal\digitalia_ltp_adapter\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;


/**
 * Defines a form that configures digitalia_ltp_adapter's settings
 */
class ModuleConfigurationForm extends ConfigFormBase
{
	/**
	 * {@inheritdoc}
	 */
	public function getFormId()
	{
		return 'digitalia_ltp_adapter_admin_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function getEditableConfigNames()
	{
		return [
			'digitalia_ltp_adapter.admin_settings',
		];
	}


	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state)
	{
		$config = $this->config('digitalia_ltp_adapter.admin_settings');

		$form['site_name'] = [
			'#type' => 'textfield',
			'#title' => 'Site name',
			'#description' => 'Used as prefix for object ingest. Node with id \'42\' and site name \'my-islandora\' will be ingested to Archivematica as \'my-islandora_42\'',
			'#default_value' => $config->get('site_name'),
		];

		$form['field_configuration'] = [
			'#type' => 'textarea',
			'#size' => '60',
			'#title' => 'List of content types and fields for export',
			'#description' => 'Use tokens for values: \'content_type::metadata_name::[node:token]\', e.g. \'page::dcterms.title::[node:title]\' or \'page::title::[node:title]\'',
			'#default_value' => $config->get('field_configuration'),
		];

		$ltp_systems = $this->getServices('ltp_system');
		$form['enabled_ltp_systems'] = [
			'#type' => 'radios',
			'#title' => 'Enabled LTP systems',
			'#options' => $ltp_systems,
			'#default_value' => $config->get('enabled_ltp_systems'),
			'#attributes' => [
				'id' => 'enabled_ltp_systems',
			],
		];

		//dpm(print_r($form['enabled_ltp_systems'], TRUE));

		$form['auto_generate_switch'] = [
			'#type' => 'checkbox',
			'#title' => 'Export on save',
			'#description' => 'When enabled, exports only when the entity is published (with media when media AND parent is published)',
			'#default_value' => $config->get('auto_generate_switch'),
		];



		return parent::buildForm($form, $form_state);

	}


	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state)
	{
		dpm(print_r($form_state->getValue('enabled_ltp_systems'), TRUE));
		$this->config('digitalia_ltp_adapter.admin_settings')->set('site_name', $form_state->getValue('site_name'))->save();
		$this->config('digitalia_ltp_adapter.admin_settings')->set('field_configuration', $form_state->getValue('field_configuration'))->save();

		//$this->config('digitalia_ltp_adapter.admin_settings')->set('enabled_ltp_systems', array_values($form_state->getValue('enabled_ltp_systems')))->save();
		$this->config('digitalia_ltp_adapter.admin_settings')->set('enabled_ltp_systems', $form_state->getValue('enabled_ltp_systems'))->save();

		$this->config('digitalia_ltp_adapter.admin_settings')->set('auto_generate_switch', $form_state->getValue('auto_generate_switch'))->save();

		parent::submitForm($form, $form_state);
	}

	public function getServices($type)
	{
		$services = \Drupal::getContainer()->getServiceIds();
		$services = preg_grep("/\.$type\./", $services);
		$options = [];
		foreach ($services as $service_id) {
			$service = \Drupal::service($service_id);
			$options[$service_id] = $service->getName();
		}

		return $options;
	}

}
