<?php

namespace Drupal\digitalia_ltp_adapter;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileSystem;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Drupal\digitalia_ltp_adapter\Utils;


class MetadataExtractor
{
	private $entity_manager;
	private $available_languages;
	private $utils;

	public $filesystem;
	public $file_repository;
	public $config;
	public $file_uri;
	public $dummy_filepaths;

	const UPDATE_CREATE = 10;
	const UPDATE_DELETE = 11;

	public function __construct()
	{
		$this->filesystem = \Drupal::service('file_system');
		$this->file_repository = \Drupal::service('file.repository');
		$this->entity_manager = \Drupal::entityTypeManager();
		$this->available_languages = \Drupal::languageManager()->getLanguages();
		$this->config = \Drupal::config('digitalia_ltp_adapter.admin_settings');
		$this->file_uri = ["", ""];
		$this->dummy_filepaths = array();
		$this->utils = new Utils();
	}

	public function getConfig()
	{
		return $this->config;
	}


	/**
	 * Tries to create/update entity and transfer it into Archivematica
	 *
	 * @param $entity
	 *   Drupal entity
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	public function updateEntity($entity, $update_mode)
	{
		dpm("preparing Sip before checks");

		if (!$this->getConfig()->get('auto_generate_switch')) {
			return;
		}

		dpm("status check: " . $this->utils->getEntityField($entity, "status"));
		if (!$this->utils->getEntityField($entity, "status")) {
			return;
		}

		dpm("preparing Sip");
		$this->prepareMetadata($entity, $this->utils->getFullEntityUID($entity), $update_mode);
	}

	/**
	 * Prepares necessary directories, starts metadata harvest and writes metadata
	 *
	 * @param $entity
	 *   Entity which is to be ingested into archivematica
	 *
	 * @param String $directory
	 *   Name of base object directory
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	private function prepareMetadata($entity, String $directory, $update_mode)
	{
		dpm("Preparing data...");

		\Drupal::logger('digitalia_ltp_adapter')->debug("Preparing sip");

		//foreach ($this->config as $conf) {
		//	dpm(print_r($conf, TRUE));
		//}

		$system_service_name = $this->config->get('enabled_ltp_systems');
		dpm("enabled system: '" . $system_service_name . "'");
		$ltp_system = \Drupal::service($system_service_name);

		if (is_null($ltp_system)) {
			dpm("Please enable at least one LTP system.");
			return;
		}

		$ltp_system->setDirectory($directory);

		$dirpath = $ltp_system->getBaseUrl() . "/" . $directory;


		$to_encode = array();

		$this->harvestMetadata($entity, $to_encode, $dirpath, $update_mode);

		dpm(json_encode($to_encode, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

		$ltp_system->writeSIP($entity, $to_encode, $this->file_uri, $this->dummy_filepaths);

	}

	/**
	 * Appends metadata of all descendants of a entity
	 *
	 * @param object $entity
	 *   A drupal entity
	 *
	 * @param String $base_path
	 *   Base path of objects from $dir_url
	 *
	 * @param Array $to_encode
	 *   For appending metadata
	 *
	 * @param String $dir_url
	 *   URL of object directory, which is ingested to Archivematica
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	private function harvestMetadata($entity, Array &$to_encode, String $dir_url, $update_mode)
	{
		dpm("Entity type id: " . $entity->getEntityTypeId());
		$type = $entity->getEntityTypeId();

		if ($type == "media") {
			$this->harvestMedia($entity);
		}

		$used_languages = [];

		foreach ($this->available_languages as $lang => $_value) {
			if ($entity->hasTranslation($lang)) {
				array_push($used_languages, $lang);
			}
		}
		
		foreach($used_languages as $lang) {
			$entity_translated = \Drupal::service('entity.repository')->getTranslationFromContext($entity, $lang);
			$this->entityExtractMetadata($entity_translated, $to_encode, $this->file_uri[1], $update_mode);
		}

		if (empty($used_languages)) {
			$this->entityExtractMetadata($entity, $to_encode, $this->file_uri[1], $update_mode);
		}

	}

	/**
	 * Extracts metadata from single entity
	 *
	 * @param $entity
	 *   Entity from which metadata is extracted
	 *
	 * @param Array $to_encode
	 *   Array with metadata
	 *
	 * @param String $filename
	 *   Entity filename, empty when not a file
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	private function entityExtractMetadata($entity, Array &$to_encode, String $filename, $update_mode)
	{
		$deleted = $update_mode == $this::UPDATE_DELETE;

		$metadata = array(
			'filename' => $filename,
			'id' => $entity->id(),
			'uuid' => $entity->uuid(),
			'entity_type' => $entity->getEntityTypeId(),
			'export_language' => $entity->language()->getId(),
			'status' => strval($this->utils->getEntityField($entity, "status")),
			'deleted' => strval($deleted),
		);

		$entity_bundle = $entity->bundle();

		$token_service = \Drupal::token();
		$data = array(
			$entity->getEntityTypeId() => $entity,
		);

		$settings = array(
			//'langcode' => $lang,
			'clear' => true,
		);

		foreach ($this->utils->getContentTypesFields()[$entity_bundle] as $name => $value) {
			//dpm($token_service->replacePlain($value, $data, $settings));
			$metadata[$name] = $token_service->replacePlain($value, $data, $settings);
		}

		array_push($to_encode, $metadata);
	}

	/**
	 * Harvests (meta)data from media entities
	 *
	 * @param $medium
	 *   Media entity to be harvested
	 */
	private function harvestMedia($medium)
	{
		$fid = $medium->getSource()->getSourceFieldValue($medium);
		$file = File::load($fid);
		$this->file_uri = [$file->getFileUri(), $file->getFilename()];
	}
}
