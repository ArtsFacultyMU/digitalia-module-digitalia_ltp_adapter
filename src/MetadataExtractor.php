<?php

namespace Drupal\digitalia_ltp_adapter;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Entity\EntityInterface;
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
	 * Tries to create/update entity and obtain metadata
	 *
	 * @param EntityInterface $entity
	 *   Drupal entity
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	public function updateEntity(EntityInterface $entity, $update_mode)
	{
		if (!$this->getConfig()->get('enable_export')) {
			return;
		}

		if ($this->getConfig()->get("only_published") && !$this->utils->getEntityField($entity, "status")) {
			return;
		}

		$this->prepareMetadata($entity, $this->utils->getFullEntityUID($entity), $update_mode);
	}

	/**
	 * Prepares necessary directories, starts metadata collection and writes metadata
	 *
	 * @param EntityInterface $entity
	 *   Entity which is to be ingested into archivematica
	 *
	 * @param String $directory
	 *   Name of base object directory
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	private function prepareMetadata(EntityInterface $entity, String $directory, $update_mode)
	{
		$system_service_name = $this->config->get('enabled_ltp_systems');

		try {
			$ltp_system = \Drupal::service($system_service_name);

		} catch (Exception $e) {
			\Drupal::logger('digitalia_ltp_adapter')->error("'$system_service_name' is not a valid LTP system. Please choose enabled LTP system.");
			return;
		}


		$ltp_system->setDirectory($directory);

		$dirpath = $ltp_system->getBaseUrl() . "/" . $directory;


		$to_encode = array();

		$this->harvestMetadata($entity, $to_encode, $dirpath, $update_mode);

		$ltp_system->writeSIP($entity, $to_encode, $this->file_uri, $this->dummy_filepaths);

	}

	/**
	 * Obtains metadata from all language versions of an entity
	 *
	 * @param EntityInterface $entity
	 *   A drupal entity
	 *
	 * @param Array $to_encode
	 *   For appending metadata
	 *
	 * @param String $dir_url
	 *   URL of object directory
	 *
	 * @param $update_mode
	 *   Delete flag
	 */
	private function harvestMetadata(EntityInterface $entity, Array &$to_encode, String $dir_url, $update_mode)
	{
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
	 * @param EntityInterface $entity
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
	private function entityExtractMetadata(EntityInterface $entity, Array &$to_encode, String $filename, $update_mode)
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
			'clear' => true,
		);

		foreach ($this->utils->getContentTypesFields()[$entity_bundle] as $name => $value) {
			$metadata[$name] = $token_service->replacePlain($value, $data, $settings);
		}

		array_push($to_encode, $metadata);
	}

	/**
	 * Obtains media filepath and name
	 *
	 * @param $medium
	 *   Media entity
	 */
	private function harvestMedia($medium)
	{
		$fid = $medium->getSource()->getSourceFieldValue($medium);
		$file = File::load($fid);
		$this->file_uri = [$file->getFileUri(), $file->getFilename()];
	}
}
