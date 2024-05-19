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
			'#title' => $this->t('Site name'),
			'#description' => $this->t('Used as prefix for object ingest.'),
			'#default_value' => $config->get('site_name'),
		];

		$form['field_configuration'] = [
			'#type' => 'textarea',
			'#size' => '60',
			'#title' => $this->t('Exported metadata'),
			'#description' => $this->t('Use tokens: "content_type::metadata_name::[node:token]", e.g. "page::dcterms.title::[node:title]" or "page::title::[node:title]"'),
			'#default_value' => $config->get('field_configuration'),
		];

		$ltp_systems = $this->getAvailableLTPSystems();
		$form['enabled_ltp_systems'] = [
			'#type' => 'radios',
			'#title' => $this->t('LTP system'),
			'#options' => $ltp_systems,
			'#default_value' => $config->get('enabled_ltp_systems'),
			'#attributes' => [
				'id' => 'enabled_ltp_systems',
			],
		];

		$form['only_published'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Only published entities'),
			'#description' => '',
			'#default_value' => $config->get('only_published'),
		];

		$form['enable_export'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Enable export'),
			'#description' => '',
			'#default_value' => $config->get('enable_export'),
		];

		return parent::buildForm($form, $form_state);
	}


	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state)
	{
		dpm(print_r($form_state->getValue('enabled_ltp_systems'), TRUE));
		$this->config('digitalia_ltp_adapter.admin_settings')->set('site_name', $form_state->getValue('site_name'));
		$this->config('digitalia_ltp_adapter.admin_settings')->set('field_configuration', $form_state->getValue('field_configuration'));
		$this->config('digitalia_ltp_adapter.admin_settings')->set('enabled_ltp_systems', $form_state->getValue('enabled_ltp_systems'));
		$this->config('digitalia_ltp_adapter.admin_settings')->set('only_published', $form_state->getValue('only_published'));
		$this->config('digitalia_ltp_adapter.admin_settings')->set('enable_export', $form_state->getValue('enable_export'))->save();

		parent::submitForm($form, $form_state);
	}

	/**
	 * Obtains available LTP systems
	 */
	public function getAvailableLTPSystems()
	{
		$services = \Drupal::getContainer()->getServiceIds();
		$services = preg_grep("/\.ltp_system\./", $services);
		$ltp_systems = [];

		foreach ($services as $service_id) {
			$service = \Drupal::service($service_id);
			$ltp_systems[$service_id] = $service->getName();
		}

		return $ltp_systems;
	}

}
